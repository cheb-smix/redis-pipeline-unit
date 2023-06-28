<?php

namespace rpu;

use websocket\SmixWebSocketServer;
use redis\SocketConnection;
use redis\StreamConnection;

class WSServer extends SmixWebSocketServer
{
    public $redis;
    public $redisConnectionClassName = '\redis\SocketConnection';

    protected $currentDatabase = 0;
    protected $currentConnectionsCnt = 0;
    protected $connected = false;
    protected $pipeline = [];
    protected $pipewidth = 2;
    protected $pipelineMinClients = 3;
    protected $pipelineFraction = 0.3;

    // Metrics
    protected $TPC = 0;
    protected $TPR = 0;
    protected $maxpipewidth = 0;
    protected $totalRequests = 0;
    protected $totalExecTime = 0;
    protected $pipelineExecuted = 0;
    protected $commandsExecuted = 0;

    public function __destruct()
    {
        Helper::printer($this->redis->close() ? "Redis connection closed" : "Redis connection closing failed");

        parent::__destruct();
    }

    public function run()
    {
        if (!$this->connected) {
            $this->redis = new $this->redisConnectionClassName($this->redis);
            $this->connected = true;
        }
        $this->pipeline[0] = [];
        parent::run();
    }

    protected function onBeforeLoop()
    {
        $this->redis->keepAlive();
    }

    protected function onLoop()
    {
        $this->currentConnectionsCnt = count($this->connections);

        $this->pipewidth = ceil($this->currentConnectionsCnt * $this->pipelineFraction);

        if ($this->maxpipewidth < $this->pipewidth) {
            $this->maxpipewidth = $this->pipewidth;
        }

        foreach ($this->pipeline as $dbnum => $requests) {
            if (!$requests) {
                continue;
            }
            if ($this->currentConnectionsCnt < $this->pipelineMinClients || count($requests) >= $this->pipewidth) {
                Helper::printer("Execute by requests count");
                $this->executePipeline($dbnum);
                continue;
            }
            if (array_sum(array_column($requests, "count")) >= $this->pipewidth) {
                Helper::printer("Execute by clients per requests count");
                $this->executePipeline($dbnum);
                continue;
            }
        }
    }

    protected function executePipeline($dbnum)
    {
        $start = \microtime(true);

        $cmds = array_column($this->pipeline[$dbnum], "request");
        $shift = false;

        if ($this->currentDatabase != $dbnum) {
            array_unshift($cmds, "SELECT $dbnum");
            $this->currentDatabase = $dbnum;
            $shift = true;
        }
        
        $results = $this->redis->pipeline($cmds);

        if ($shift) array_shift($results);

        $reqIndex = 0;

        foreach ($this->pipeline[$dbnum] as $requestHash => $request) {
            $this->totalRequests += $request["count"];
            foreach ($request["clients"] as $i => $cid) {
                $this->outputData($cid, $results[$reqIndex]);
                unset($this->pipeline[$dbnum][$requestHash]["clients"][$i]);
                $this->pipeline[$dbnum][$requestHash]["count"]--;
                if ($this->pipeline[$dbnum][$requestHash]["count"] <= 0) {
                    unset($this->pipeline[$dbnum][$requestHash]);
                }
            }
            $reqIndex++;
        }

        $this->pipelineExecuted++;
        $this->commandsExecuted += count($cmds);

        $executionTime = microtime(true) - $start;
        Helper::printer("Pipelined in " . round($executionTime, 6) . " seconds");

        $this->totalExecTime += $executionTime;
        
        $this->TPC = round($this->totalExecTime / $this->commandsExecuted, 6);
        $this->TPR = round($this->totalExecTime / $this->totalRequests, 6);
    }

    protected function onOpen($cid)
    {
        $this->connections[$cid]["current_database"] = 0;
    }

    protected function onMessage($cid, $request)
    {
        $index = strpos($request, " ");
        $cmd = substr($request, 0, $index);
        $key = substr($request, $index + 1, strpos($request, " ", $index + 1) - $index - 1);

        if (!isset(REDIS_COMMANDS[$cmd])) {
            $this->outputData($cid, false);
            return;
        }
        if ($cmd == "SELECT") {
            if (!isset($this->pipeline[$key])) {
                $this->pipeline[$key] = [];
            }
            $this->connections[$cid]["current_database"] = $key;
        } 

        $dbnum = $this->connections[$cid]["current_database"];

        if ($cmd != "SELECT") {
            $requestHash = sha1("$cmd : $key");

            if (!isset($this->pipeline[$dbnum][$requestHash])) {
                $this->pipeline[$dbnum][$requestHash] = [
                    "clients"   => [$cid],
                    "count"     => 1,
                    "request"   => $request,
                ];   
            } else {
                $this->pipeline[$dbnum][$requestHash]["clients"][] = $cid;
                $this->pipeline[$dbnum][$requestHash]["count"]++;
            }
        } 
    }

    protected function onClose($cid)
    {

    }


    // ACTIONS

    protected function socketStatistics($cid)
    {
        return [
            "Socket Metrics" => parent::socketStatistics($cid) + [
                "Current Unique Requests"   => array_sum(array_map(function ($requests) {
                    return count($requests);
                }, $this->pipeline)),
                "Current Total Requests"    => array_sum(array_map(function ($requests) {
                    return array_sum(array_column($requests, "count"));
                }, $this->pipeline)),
                "Current Database Acrive"   => $this->currentDatabase,
                "Current Pipeline Width"    => $this->pipewidth,
                "Current Pipeline Size"     => count($this->pipeline),
                "Max PipelineWidth Reached" => $this->maxpipewidth,
                "Time Per Redis Command"    => $this->TPC,
                "Time Per Client Request"   => $this->TPR,
                "Total Execution Time"      => round($this->totalExecTime, 6),
                "Total Pipeline Executed"   => $this->pipelineExecuted,
                "Total Commands Executed"   => $this->commandsExecuted,
                "Total Requests Processed"  => $this->totalRequests,
            ],
            "Common Parameters" => [
                "PHP Memory Usage"          => Helper::formatBytes(memory_get_usage()),
                "CPU Usage"                 => (($load = Helper::getCPULoad()) ? round($load, 2) . "%" : "NaN"),
                "Websocket Hostname"        => $this->addr,
                "Compression Enabled"       => $this->compressEnabled ? "true" : "false",
                "Compression Min Length"    => Helper::formatBytes($this->compressMinLength),
                "Redis Connection Class"    => $this->redisConnectionClassName,
            ] + (is_object($this->redis) ? [
                "Redis TCP KeepAlive"       => $this->redis->TCPKeepAlive,
                "Redis Last Ping"           => date("Y-m-d H:i:s", $this->redis->lastPing),
                "Redis Hostname"            => $this->redis->hostname . ":" . $this->redis->port,
                "Redis Using Class"         => $this->redis->clientClassName,
                "Using Redis Format"        => $this->redis->useRedisFormat ? "true" : "false",
            ] : [
                "Redis"                     => "Not initialized",
            ]),
        ];
    }

}

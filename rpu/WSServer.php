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
    protected $connected = false;
    protected $pipeline = [];
    protected $pipewidth = 32;
    protected $pipelineMinClients = 50;

    // Stats
    protected $TPC = 0;
    protected $maxpipewidth = 0;
    protected $totalExecTime = 0;
    protected $pipelineExecuted = 0;
    protected $commandsExecuted = 0;
    protected $clientsProcessed = 0;

    public function __destruct()
    {
        Helper::printer($this->redis->close() ? "Redis connection closed" : "Redis connection closing failed");

        parent::__destruct();
    }

    public function run()
    {
        $this->pipeline[0] = [];
        parent::run();
    }

    protected function onLoop()
    {
        // usleep(1000);
        $connectionsCnt = count($this->connections);

        $this->pipewidth = ceil($connectionsCnt / 10);

        if ($this->maxpipewidth < $this->pipewidth) {
            $this->maxpipewidth = $this->pipewidth;
        }

        foreach ($this->pipeline as $dbnum => $requests) {
            if (!$requests) {
                continue;
            }
            if ($connectionsCnt < $this->pipelineMinClients || count($requests) >= $this->pipewidth) {
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

        if (!$this->connected) {
            
            $this->redis = new $this->redisConnectionClassName($this->redis);
            $this->connected = true;
        }

        
        $results = $this->redis->pipeline($cmds);

        if ($shift) array_shift($results);

        $reqIndex = 0;

        foreach ($this->pipeline[$dbnum] as $requestHash => $request) {
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
    }

    protected function onOpen($cid)
    {
        $this->connections[$cid]["current_database"] = 0;
    }

    protected function onMessage($cid, $request)
    {
        $parts = explode(" ", $request);
        $cmd = array_shift($parts);
        $key = array_shift($parts);
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
                $this->pipeline[$dbnum][sha1("$cmd : $key")] = [
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
        $this->clientsProcessed++;
    }


    // ACTIONS

    protected function socketStatistics($cid)
    {
        return parent::socketStatistics($cid) + [
            "Current Unique Requests "  => array_sum(array_map(function ($dbreq) {
                return count($dbreq);
            }, $this->pipeline)),
            "Max Pipeline Width      "  => $this->maxpipewidth,
            "Current Pipeline Width  "  => $this->pipewidth,
            "Current Pipeline Size   "  => count($this->pipeline),
            "Total Execution Time    "  => round($this->totalExecTime, 6),
            "Seconds Per Request     "  => $this->TPC,
            "Total Pipeline Executed "  => $this->pipelineExecuted,
            "Total Commands Executed "  => $this->commandsExecuted,
            "Total Clients Processed "  => $this->clientsProcessed,
        ];
    }

}

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
    protected $TPC = 0;
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
        $connectionsCnt = count($this->connections);

        // $this->pipewidth = ceil($connectionsCnt / 10);

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

        
        $results = $this->redis->pipeline($this->rebuildCommands($cmds));

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

        $currentTPC = (microtime(true) - $start) / count($cmds);
        Helper::printer("Pipelined in $currentTPC microseconds");

        if ($this->TPC) {
            $this->TPC = round(($currentTPC + $this->TPC) / 2, 1000000);
        } else {
            $this->TPC = round($currentTPC, 1000000);
        }
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
            "Current Pipeline Width  "  => $this->pipewidth,
            "Current Pipeline Size   "  => count($this->pipeline),
            "Seconds Per Request     "  => $this->TPC,
            "Total Pipeline Executed "  => $this->pipelineExecuted,
            "Total Commands Executed "  => $this->commandsExecuted,
            "Total Clients Processed "  => $this->clientsProcessed,
        ];
    }

    protected function rebuildCommands($cmds)
    {
        $endLine = "\r\n";

        foreach ($cmds as &$cmd) {
            $cmd = explode(" ", $cmd);
            $command = '*' . count($cmd) . $endLine;
            foreach ($cmd as $arg) {
                $command .= '$' . mb_strlen($arg, '8bit') . $endLine . $arg . $endLine;
            }
            $cmd = $command;
        }
        return $cmds;
    }

}

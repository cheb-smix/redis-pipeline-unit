<?php

namespace rpu;

use websocket\SmixConsoleLogger;
use websocket\SmixWebSocketClient;

class WSMonitor extends SmixWebSocketClient
{
    public function run($argv = [])
    {
        $config["debugMessagesOn"] = true;
        parent::run();

        while (true)
        {
            if (!$data = $this->send("monitoring")) {
                break;
            } else {
                SmixConsoleLogger::statistics(json_decode($data, true));
                sleep(1);
            }
        }
    }
}

<?php

namespace rpu;

use websocket\SmixConsoleLogger;
use websocket\SmixWebSocketClient;

class WSMonitor extends SmixWebSocketClient
{
    public function __construct(array &$config = [])
    {
        $config["debugMessagesOn"] = true;
        parent::__construct($config);
        
        while (true)
        {
            if (!$data = $this->send("monitoring")) {
                break;
            } else {
                SmixConsoleLogger::statistics($data);
                sleep(1);
            }
        }
    }
}

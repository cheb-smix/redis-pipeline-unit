<?php

namespace rpu;

use websocket\SmixWebSocketClient;

class WSClient extends SmixWebSocketClient
{
    public function __construct(array &$config = [])
    {
        parent::__construct($config);

        var_dump($this->send("GET wtf"));

        while (true)
        {
            $line = readline("Input your command: ");
            var_dump($this->send($line));
        }
    }
}

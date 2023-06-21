<?php

namespace rpu;

use websocket\SmixWebSocketClient;

class WSClient extends SmixWebSocketClient
{
    public $redis;

    public function run()
    {
        parent::run();

        $key = "MY_TEST_KEY";
        $value = str_repeat("1", 10000);

        var_dump($this->send("SET $key $value NX 36000"));
        var_dump($this->send("GET $key"));

        // while (true)
        // {
        //     $line = readline("Input your command: ");
        //     var_dump($this->send($line));
        // }
    }
}

<?php

namespace rpu;

use websocket\SmixWebSocketClient;

class WSClient extends SmixWebSocketClient
{
    public $redis;

    public function run($argv = [])
    {
        parent::run();

        if (isset($argv["key"])) {
            $key = $argv["key"];
        } else {
            $key = "MY_TEST_KEY" . rand(1 , 4);
        }

        $value = str_repeat("1", 10000);

        // Helper::printer($this->send("SET $key $value" . date("0YmdHis") . " NX 36000"));
        // Helper::printer($this->send("GET $key"));
        Helper::printer($this->send("GET $key"));

        // while (true)
        // {
        //     $line = readline("Input your command: ");
        //     Helper::printer($this->send($line));
        // }
    }
}

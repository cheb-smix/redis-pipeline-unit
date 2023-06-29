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
        $value = json_encode(["SIMPLE EXAMPLE" => "SOME TEXT \r\n JUST with '' and & всяким так дерьмом"], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);

        $this->logger->info($this->send("SET $key '$value' EX 36000"));
        $this->logger->info($this->send("GET $key"));

        // while (true)
        // {
        //     $line = readline("Input your command: ");
        //     $this->logger->info($this->send($line));
        // }
    }
}
// JSON_HEX_APOS
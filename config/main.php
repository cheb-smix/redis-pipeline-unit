<?php

require __DIR__ . "/params.php";

return [
    "redis" => [
        "hostname"  => 'localhost',
        "port"      => 6379,
    ],
    "redisConnectionClassName" => '\redis\SocketConnection',
    "protocol"  => "tcp",
    "hostname"  => "127.0.0.1",
    "port"      => 1988,
    "pipewidth" => 2,
    "pipelineMinClients" => 1,
    "compressEnabled" => true,
    "client_debug_messages_on" => false,
    "server_debug_messages_on" => false,
];
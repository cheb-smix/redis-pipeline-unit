<?php

require __DIR__ . "/params.php";

return [
    "redis" => [
        "hostname"  => 'localhost',
        "port"      => 6379,
    ],
    "protocol"  => "tcp",
    "hostname"  => "127.0.0.1",
    "port"      => 1988,
    "chunkSize" => 8192,
    "pipewidth" => 2,
    "pipelineMinClients" => 1,
    "compressEnabled" => false,
];
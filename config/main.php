<?php

require __DIR__ . "/params.php";

return [
    "socket" => [
        "protocol"          => "tcp",
        "clientprotocol"    => "ws",
        "ip"                => "127.0.0.1",
        "port"              => 1988,
    ],
    "pipewidth" => 2,
    "pipelineMinClients" => 1,
];
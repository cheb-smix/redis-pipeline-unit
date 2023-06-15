<?php
return [
    "socket" => [
        "protocol"          => "tcp",
        "clientprotocol"    => "ws",
        "ip"                => "127.0.0.1",
        "port"              => 1988,
        "monitorAllowedIpPool" => ["192.168.0.0/16", "127.0.0.1/32"],
    ],
    "cacheInstances" => [
        0 => "cache",
        1 => "fileCache",
        4 => "federalEpgCache",
    ],
    "pipewidth" => 2,
    "pipelineMinClients" => 1,
];
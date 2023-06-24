<?php

namespace redis;

use rpu\Helper;
use websocket\StreamClient;

class StreamConnection extends CommonConnection
{
    public $socketClientFlags = STREAM_CLIENT_CONNECT;
    public $clientClassName   = "websocket\StreamClient";
}

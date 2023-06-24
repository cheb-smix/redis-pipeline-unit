<?php

namespace redis;

use rpu\Helper;
use websocket\SocketClient;

class SocketConnection extends CommonConnection
{
    public $clientClassName = "websocket\SocketClient";
}

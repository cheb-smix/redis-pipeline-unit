<?php

namespace redis;

use rpu\Helper;
use websocket\SocketClient;

class SocketConnection extends CommonConnection
{
    protected $connectionCharsAdd = 3;
    protected $clientClassName = "websocket\SocketClient";
}

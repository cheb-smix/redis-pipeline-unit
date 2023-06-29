<?php

namespace websocket;

use rpu\Helper;
use websocket\SocketClient as Client;

class SmixWebSocketClient
{
    public $compressEnabled = false;
    public $compressMinLength = 2080;
    public $protocol = "tcp";
    public $hostname = "localhost";
    public $port = 1988;
    public $connectionTimeout = 5;
    public $secretkey = "jhdfgjkdhg;ldhg;ohgoheoghnherd75d449dtp84su8lj98t4hnm9";
    public $debugMessagesOn = true;
    public $lengthInitiatorNumber = 9;
    
    public $logger;

    protected $addr;
    protected $socket;
    protected $errno;
    protected $errstr;

    public function init(array &$config = [])
    {
        foreach ($config as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }

        $this->addr = "$this->protocol://$this->hostname:$this->port";
        $this->secretkey = base64_encode(sha1($this->secretkey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

        return $this;
    }

    public function run()
    {
        $this->logger->success(get_class($this) . " started on $this->addr");

        if (!$this->socket) {
            try {
                $this->socket = Client::connect($this->hostname, $this->port, $this->errno, $this->errstr, $this->connectionTimeout);
                if ($this->socket) {
                    $this->handshake();
                } else {
                    $this->logger->error("Connection error");
                }
            } catch (Exception $e) {
                $this->logger->error("Failed socket connection [$this->errno] $this->errstr");
                return false;
            }
        } else {
            $this->logger->info("Socket is already opened");
        }
    }

    private function handshake()
    {
        $head = implode("\r\n", [
            "GET / HTTP/1.1",
            "Host: $this->protocol://$this->hostname",
            "Origin: $this->protocol://$this->hostname",
            "Upgrade: websocket",
            "Connection: Keep-Alive, Upgrade",
            "Sec-WebSocket-Extensions: permessage-deflate",
            "Sec-WebSocket-Key: $this->secretkey",
            "Sec-Fetch-Dest: websocket",
            "Sec-Fetch-Mode: websocket",
            "Sec-WebSocket-Version: 13",
            "Cache-Control: no-cache",
        ]) . "\r\n\r\n";

        if (!Client::send($this->socket, $head, $this->lengthInitiatorNumber)) {
            die('Handshake error');
        }
    }

    public function send($data)
    {
        if (!$this->socket) {
            $this->logger->exception("Socket error");
            return false;
        } else {
            if (!is_string($data)) {
                $data = json_encode($data);
            }

            $response = Client::send($this->socket, $data, $this->lengthInitiatorNumber);

            if ($response === false) {
                $this->logger->error("Looks like connection lost");
                return false;
            }

            return $response;
        }
    }
}
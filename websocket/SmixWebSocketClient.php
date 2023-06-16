<?php

namespace websocket;

class SmixWebSocketClient
{
    private $config = [];
    private $addr = "";
    private $socket = null;
    private $protocol = "tcp";
    private $host = "tcp://localhost";
    private $ip = "localhost";
    private $port = 1988;
    private $connectionTimeout = 5;
    private $secretkey = "jhdfgjkdhg;ldhg;ohgoheoghnherd75d449dtp84su8lj98t4hnm9";
    private $secWSaccept;
    private $errno;
    private $errstr;
    private $debugMessagesOn = false;

    public function __construct(array &$config = [])
    {
        $this->config = &$config;
        $this->addr = $this->config["socket"]["protocol"] . "://" . $this->config["socket"]["ip"] . ":" . $this->config["socket"]["port"];

        foreach (["protocol", "ip", "port", "connectionTimeout", "secretkey"] as $key) {
            if (isset($this->config["socket"][$key])) {
                $this->$key = $this->config["socket"][$key];
            }
        }
        if (isset($this->config["debugMessagesOn"])) {
            $this->debugMessagesOn = $this->config["debugMessagesOn"];
        }
        $this->host = "{$this->protocol}://{$this->ip}";
        $this->secretkey = base64_encode(sha1($this->secretkey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

        $this->printer(get_class($this) . " started on $this->host:$this->port");

        if (!$this->socket) {
            try {
                // $this->socket = @fsockopen($this->host, $this->port, $this->errno, $this->errstr, $this->connectionTimeout);
                $this->socket = stream_socket_client($this->addr, $this->errno, $this->errstr, $this->connectionTimeout);
                if ($this->socket) {
                    $this->handshake();
                }
            } catch (Exception $e) {
                $this->printer("Failed socket connection [$this->errno] $this->errstr");
                return false;
            }
        }
    }

    public function __destruct()
    {
        $this->printer("DESCTRUCT");
        $this->close();
    }

    private function handshake()
    {
        $head = implode("\r\n", [
            "GET / HTTP/1.1",
            "Host: $this->host",
            "Origin: $this->host",
            "Upgrade: websocket",
            "Connection: Keep-Alive, Upgrade",
            "Sec-WebSocket-Extensions: permessage-deflate",
            "Sec-WebSocket-Key: $this->secretkey",
            "Sec-Fetch-Dest: websocket",
            "Sec-Fetch-Mode: websocket",
            "Sec-WebSocket-Version: 13",
            "Cache-Control: no-cache",
        ]) . "\r\n\r\n";

        fwrite($this->socket, $head) or die('error:'.$this->errno.':'.$this->errstr);

        $length = fread($this->socket, 3);
        fread($this->socket, $length);
    }

    public function send($data)
    {
        if (!$this->socket) {
            $this->printer("Socket error");
            return false;
        } else {
            if (!is_string($data)) {
                $data = json_encode($data);
            }

            if (!fwrite($this->socket, $data)) {
                $this->printer("Failed socket writing");
                return false;
            }

            $length = (int) fread($this->socket, 8);
            if (!$length) {
                $this->close();
                $this->printer("Looks like connection lost");
                return false;
            }
            $response = fread($this->socket, $length);

            return json_decode($response, true);
        }
    }

    public function close()
    {
        if ($this->socket) {
            if (fclose($this->socket)) {
                $this->socket = null;
                return true;
            }
            return false;
        }
        return null;
    }

    public function printer($s, $obj = false, $die = false)
    {
        if (!$this->debugMessagesOn) return;

        print date("Y-m-d | H:i:s >> ");

        if ($obj) {
            print_r($s);
        } else {
            print $s;
        }
        print "\n";

        if ($die) {
            exit;
        }
    }
}
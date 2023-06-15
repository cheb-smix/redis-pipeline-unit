<?php

namespace console\components\websocket;

class SmixWebSocketClient
{
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


    public function __construct($config = [])
    {
        foreach (["protocol", "ip", "port", "connectionTimeout", "secretkey"] as $key) {
            if (isset($config[$key])) {
                $this->$key = $config[$key];
            }
        }

        $this->host = "{$this->protocol}://{$this->ip}";
        $this->secretkey = base64_encode(sha1($this->secretkey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

        // print "WSClient started on $this->host:$this->port\n";

        if (!$this->socket) {
            try {
                $this->socket = @fsockopen($this->host, $this->port, $this->errno, $this->errstr, $this->connectionTimeout);
                if ($this->socket) {
                    $this->handshake();
                }
            } catch (Exception $e) {
                return $this->error("Failed to open socket connection: " . $e->getMessage(), 500);
            }
        }
    }

    public function __destruct()
    {
        // print "DESCTRUCT\n";
        $this->close();
    }

    private function handshake()
    {
        $head = [
            "GET / HTTP/1.1",
            "Host: $this->host",
            "Origin: $this->host",
            "Upgrade: websocket",
            "Connection: Keep-Alive, Upgrade",
            // "Content-Length: 0",
            "Sec-WebSocket-Extensions: permessage-deflate",
            "Sec-WebSocket-Key: $this->secretkey",
            "Sec-Fetch-Dest: websocket",
            "Sec-Fetch-Mode: websocket",
            // "Sec-WebSocket-Protocol: wamp",
            "Sec-WebSocket-Version: 13",
            "Cache-Control: no-cache",
        ];

        fwrite($this->socket, implode("\r\n", $head) . "\r\n\r\n") or die('error:'.$this->errno.':'.$this->errstr);
        $headers = fread($this->socket, 2000);
    }

    public function send($data)
    {
        if (!$this->socket) {
            return $this->error("$this->errstr ($this->errno)", 500);
        } else {
            if (!is_string($data)) {
                $data = json_encode($data);
            }
            $data = WSL::encode($data, "text", true);

            if (!fwrite($this->socket, $data)) {
                return "$this->errstr ($this->errno)";
            }

            $retdata = "";

            while (!feof($this->socket)) {
                $retdata .= fgets($this->socket, 256);
            }

            $retdata = substr($retdata, strpos($retdata, "{"));

            return json_decode($retdata, true);
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

    private function error($message = "", $status = 400)
    {
        return false;
        // return [
        //     "status"    => $status,
        //     "success"   => false,
        //     "message"   => $message,
        //     "data"      => [],
        // ];
    }
}
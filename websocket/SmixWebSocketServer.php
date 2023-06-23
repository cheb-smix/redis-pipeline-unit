<?php

namespace websocket;

use rpu\Helper;
use websocket\SocketServer as Server;

class SmixWebSocketServer
{
    public $compressEnabled = false;
    public $compressMinLength = 2048;
    public $scheme = "tcp";
    public $hostname = "localhost";
    public $port = 1988;
    public $connectionTimeout = 5;
    public $chunkSize = 8192;
    public $secretkey = "jhdfgjkdhg;ldhg;ohgoheoghnherd75d449dtp84su8lj98t4hnm9";
    public $debugMessagesOn = true;
    public $lengthInitiatorNumber = 9;

    protected $socket;
    protected $origin = "";
    protected $addr = "";
    protected $maxConnections = 0;
    protected $connections = [];
    protected $active = [];

    public function __destruct()
    {
        foreach ($this->connections as $c) {
            Helper::printer($c["peer_name"] . (Server::close($c["connection"]) ? " has been disconnected" : " disconnection failed"));
        }
        Helper::printer(Server::close($this->socket) ? "Main socket closed" : "Main socket closing failed");
        Helper::printer("SERVER DESTRUCT");
    }

    public function init(array &$config = [])
    {
        foreach ($config as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }

        $this->origin = "$this->scheme://$this->hostname";
        $this->addr = "$this->origin:$this->port";

        return $this;
    }

    public function run()
    {
        // $oldChunkSize = $this->chunkSize;

        $errno = $errstr = null;

        $this->socket = Server::create($this->hostname, $this->port, $errno, $errstr);

        if (!$this->socket) {
            Helper::printer("Socket error $errstr ($errno)", false, true);
        } else {
            Helper::printer("Socket started at $this->addr");
        }

        // $this->chunkSize = Server::set_chunk_size($this->socket, $this->chunkSize);

        // if ($oldChunkSize == $this->chunkSize) {
        //     Helper::printer("Unable to change chunkSize of socket stream");
        // }

        // unset($oldChunkSize);
        $connections = [];

        while (true) {
            $read = $connections;
            $read[] = $this->socket;
            $write = $except = null;

            if (!Server::select($read, $write, $except, $this->connectionTimeout)) {
                continue;
            }

            if (in_array($this->socket, $read)) {
                if (
                    ($connection = Server::accept($this->socket, $this->connectionTimeout, $peer_name)) 
                    && 
                    $info = Server::handshake($connection, $this->lengthInitiatorNumber)
                ) {
                    $connections[] = $connection;

                    $cid = $this->getConnectionID($connection);

                    $this->connections[$cid] = [
                        "resource"  => $connection,
                        "peer_name" => $peer_name,
                    ];

                    $this->onSocketOpen($connection);
                }
                unset($read[ array_search($this->socket, $read) ]);
            }

            foreach($read as $connection) {

                $data = Server::read($connection, $this->lengthInitiatorNumber);

                $cid = $this->getConnectionID($connection);

                if (!$data) {
                    Server::close($connection);
                    unset($connections[array_search($connection, $connections)]);
                    $this->onSocketClose($connection);
                    continue;
                }

                $this->onSocketMessage($connection, $data);
            }

            $this->onSocketLoop();
        }
    }

    private function onSocketLoop()
    {
        sleep(1);
        $this->onLoop();
    }

    private function onSocketOpen($connection)
    {
        $cid = $this->getConnectionID($connection);
        $currentCnt = count($this->connections);
        if ($this->maxConnections < $currentCnt) {
            $this->maxConnections = $currentCnt;
        }
        Helper::printer("New connection! [$cid on {$this->connections[$cid]["peer_name"]}]");
        $this->onOpen($cid);
    }
    
    private function onSocketClose($connection)
    {
        $cid = $this->getConnectionID($connection);
        $this->onClose($cid);
        unset($this->connections[$cid], $this->active[$cid]);
        Helper::printer("Connection $cid has disconnected");
    }
    
    private function onSocketMessage($connection, $message)
    {
        $cid = $this->getConnectionID($connection);
        $this->active[$cid] = $cid;
        if ($message == "monitoring") {
            $this->outputData($cid, json_encode($this->socketStatistics($cid), JSON_UNESCAPED_UNICODE), false);
        } else {
            Helper::printer("Message from $cid [" . strlen($message) . "]: $message");
            $this->onMessage($cid, $message);
        }
    }

    protected function onLoop()
    {
        
    }

    protected function onOpen($cid)
    {

    }

    protected function onMessage($cid, $request)
    {

    }

    protected function onClose($cid)
    {

    }

    protected function socketStatistics($cid)
    {
        return [
            "SocketConnections"    => count($this->connections),
            "SocketActive"         => count($this->active),
            "MaxSocketConnections" => $this->maxConnections,
        ];
    }

    private function getConnectionID($connection)
    {
        preg_match("/[0-9]{1,4}/", (string) $connection, $m);
        return (int) $m[0];
    }

    public function printer($s, $obj = false, $die = false)
    {
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

    public function outputData($cid, $data, $logging = true)
    {
        Server::write($this->connections[$cid]['resource'], $data, $this->lengthInitiatorNumber);

        if ($logging) Helper::printer("Response: $data");

        unset($this->active[$cid]);

        return true;
    }

    private function getChunksSizes($length = 0)
    {
        $a = [];
        while ($length > 0) {
            if ($length >= $this->chunkSize) {
                $a[] = $this->chunkSize;
            } else {
                $a[] = $length;
            }
            $length -= $this->chunkSize;
        }
        return $a;
    }

}


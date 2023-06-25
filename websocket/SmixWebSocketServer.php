<?php

namespace websocket;

use rpu\Helper;
use websocket\SocketServer as Server;

class SmixWebSocketServer
{
    public $compressEnabled = false;
    public $compressMinLength = 3564;
    public $scheme = "tcp";
    public $hostname = "localhost";
    public $port = 1988;
    public $connectionTimeout = 5;
    public $secretkey = "jhdfgjkdhg;ldhg;ohgoheoghnherd75d449dtp84su8lj98t4hnm9";
    public $debugMessagesOn = true;
    public $lengthInitiatorNumber = 9;

    protected $socket;
    protected $origin = "";
    protected $addr = "";
    protected $maxConnections = 0;
    protected $connections = [];
    protected $active = [];

    // Metrics
    protected $monitorers = [];
    protected $started = 0;
    protected $avgSessionTime = 0;
    protected $clientsProcessed = 0;

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
        $this->started = time();

        $errno = $errstr = null;

        $this->socket = Server::create($this->hostname, $this->port, $errno, $errstr);

        if (!$this->socket) {
            Helper::printer("Socket error $errstr ($errno)", false, true);
        } else {
            Helper::printer("Socket started at $this->addr");
        }

        $connections = [];

        while (true) {

            $this->onSocketBeforeLoop();

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
                        "resource"      => $connection,
                        "peer_name"     => $peer_name,
                        "session_start" => microtime(true),
                        "monitorer"     => false,
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

    private function onSocketBeforeLoop()
    {
        $this->onBeforeLoop();
    }

    private function onSocketLoop()
    {
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

        if ($this->connections[$cid]["monitorer"]) {

            unset($this->connections[$cid], $this->monitorers[$cid]);
            Helper::printer("Monitorer $cid has disconnected");

        } else {

            if ($this->avgSessionTime) {
                $this->avgSessionTime = round(($this->avgSessionTime + microtime(true) - $this->connections[$cid]["session_start"]) / 2, 6);
            } else {
                $this->avgSessionTime = microtime(true) - $this->connections[$cid]["session_start"];
            }
            $this->clientsProcessed++;

            $this->onClose($cid);
            unset($this->connections[$cid], $this->active[$cid]);
            Helper::printer("Client $cid has disconnected");

        }
    }
    
    private function onSocketMessage($connection, $message)
    {
        $cid = $this->getConnectionID($connection);

        if ($message == "monitoring") {
            if (!isset($this->monitorers[$cid])) {
                $this->monitorers[$cid] = $cid;
                $this->connections[$cid]["monitorer"] = true;
            }
            $this->outputData($cid, json_encode($this->socketStatistics($cid), JSON_UNESCAPED_UNICODE), false);
        } else {
            $this->active[$cid] = $cid;
            Helper::printer("Message from $cid [" . strlen($message) . "]: " . substr($message, 0, 100));
            $this->onMessage($cid, $message);
        }
    }

    protected function onBeforeLoop()
    {

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
            "Websocket Daemon Alive  "  => Helper::formatTime(time() - $this->started),
            "Max Socket Connections  "  => $this->maxConnections,
            "Socket Used Connections "  => count($this->connections) - count($this->monitorers),
            "Socket Active Connection"  => count($this->active),
            "Average Session Time"      => $this->avgSessionTime,
            "Total Clients Processed"   => $this->clientsProcessed,
            "Monitorers Online"         => count($this->monitorers),
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

        if ($logging) Helper::printer("Response: " . \substr($data, 0, 100));

        unset($this->active[$cid]);

        return true;
    }

}


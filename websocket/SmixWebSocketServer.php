<?php

namespace websocket;

class SmixWebSocketServer
{
    protected $config = [];
    protected $addr = "";
    protected $maxConnections = 0;
    protected $connections = [];
    protected $active = [];

    public function __construct(array &$config = [])
    {
        $this->config = &$config;
        $this->addr = $this->config["socket"]["protocol"] . "://" . $this->config["socket"]["ip"] . ":" . $this->config["socket"]["port"];

        $socket = stream_socket_server($this->addr, $errno, $errstr);

        if (!$socket) {
            $this->printer("Socket error $errstr ($errno)", false, true);
        }

        $connections = [];
        $this->printer("Socket started at $this->addr");

        while (true) {
            $read = $connections;
            $read[] = $socket;
            $write = $except = null;

            if (!stream_select($read, $write, $except, null)) {
                continue;
            }

            if (in_array($socket, $read)) {
                if (($connection = stream_socket_accept($socket, -1, $peer_name)) && $info = WSL::handshake($connection)) {
                    $connections[] = $connection;

                    $cid = $this->getConnectionID($connection);

                    $this->connections[$cid] = [
                        "resource"  => $connection,
                        "peer_name" => $peer_name,
                        "allowed"   => true,
                    ];

                    $this->onSocketOpen($connection, $info);
                }
                unset($read[ array_search($socket, $read) ]);
            }

            foreach($read as $connection) {
                $data = fread($connection, 100000);
                $cid = $this->getConnectionID($connection);
                if (!$data or !$this->connections[$cid]["allowed"]) {
                    fclose($connection);
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
        $this->onLoop();
    }

    private function onSocketOpen($connection, $info)
    {
        $cid = $this->getConnectionID($connection);
        $currentCnt = count($this->connections);
        if ($this->maxConnections < $currentCnt) {
            $this->maxConnections = $currentCnt;
        }
        $this->printer("New connection! [$cid on {$this->connections[$cid]["peer_name"]}]");
        $this->onOpen($cid);
    }
    
    private function onSocketClose($connection)
    {
        $cid = $this->getConnectionID($connection);
        $this->onClose($cid);
        unset($this->connections[$cid], $this->active[$cid]);
        $this->printer("Connection $cid has disconnected");
    }
    
    private function onSocketMessage($connection, $message)
    {
        $cid = $this->getConnectionID($connection);
        $this->active[$cid] = $cid;
        if ($message == "monitoring") {
            $this->outputData($cid, $this->socketStatistics($cid), false);
        } else {
            $this->printer("Message from $cid: $message");
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

    public function outputData($cid, $data = [], $logging = true)
    {
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $data = sprintf("%08d", strlen($data)) . $data;
        fwrite($this->connections[$cid]['resource'], $data);

        if ($logging) $this->printer("Response: $data");

        unset($this->active[$cid]);

        return true;
    }

}


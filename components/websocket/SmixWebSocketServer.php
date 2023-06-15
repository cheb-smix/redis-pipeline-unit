<?php

namespace console\components\websocket;

class SmixWebSocketServer
{
    protected $monitorAllowedIpPool = [];
    protected $connections = [];
    protected $active = [];
    protected $systemtime = 0;

    public function __construct($addr = "tcp://localhost:8080", $monitorAllowedIpPool = [])
    {
        $socket = stream_socket_server($addr, $errno, $errstr);

        if (!$socket) {
            $this->printer("Socket error $errstr ($errno)", false, true);
        }

        if ($monitorAllowedIpPool) {
            foreach ($monitorAllowedIpPool as $net) {
                $net = explode("/", $net);
                if (count($net) == 1) $net[1] = 32;
                $startIP = ip2long($net[0]);
                $this->monitorAllowedIpPool[] = [
                    "sip" => $startIP,
                    "eip" => $startIP + pow(2, 32 - $net[1]),
                ];
            }
        }

        $connections = [];
        $this->printer("Socket started at $addr");

        while (true) {
            $this->systemtime = time();
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
        print "Common loop\n";
        $this->onLoop();
    }

    private function onSocketOpen($connection, $info)
    {
        $cid = $this->getConnectionID($connection);
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
    
    private function onSocketMessage($connection, $data)
    {
        $cid = $this->getConnectionID($connection);
        $this->active[$cid] = $cid;
        $message = WSL::decode($data)['payload'];

        if ($message != "monitoring") $this->printer("Message from $cid: $message");

        $this->onMessage($cid, $message);
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
        $ip = substr($this->connections[$cid]["peer_name"], 0, strpos($this->connections[$cid]["peer_name"], ":"));

        if (!$this->monitoringAllowed($ip)) {
            $this->printer("{$ip} is not in allowed ip pool for monitoring requests");
            $this->connections[$cid]["allowed"] = false;
            return [];
        }

        return [
            "SocketsConnections" => count($this->connections),
            "SocketsActive" => count($this->active),
        ];
    }

    private function getConnectionID($connection)
    {
        preg_match("/[0-9]{1,4}/", (string) $connection, $m);
        return (int) $m[0];
    }

    private function monitoringAllowed($ip)
    {
        return true; // потом разберемся

        if (!$this->monitorAllowedIpPool) {
            return false;
        }

        foreach ($this->monitorAllowedIpPool as $net) {
            $ip = ip2long($ip);
            if ($ip >= $net["sip"] && $ip <= $net["eip"]) {
                return true;
            }
        }

        return false;
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

    public function outputData($cid, $message = "OK", $data = [])
    {
        fwrite($this->connections[$cid]['resource'], WSL::encode(json_encode($data, JSON_UNESCAPED_UNICODE)));

        unset($this->active[$cid]);

        return true;
    }

    public function outputError($cid, $message = "ERROR", $status = 400, $data = [])
    {
        fwrite($this->connections[$cid]['resource'], WSL::encode(json_encode($data, JSON_UNESCAPED_UNICODE)));

        unset($this->active[$cid]);

        return true;
    }
}


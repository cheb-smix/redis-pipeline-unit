<?php

namespace redis;

use rpu\Helper;
use websocket\SocketClient;
use websocket\StreamClient;

class CommonConnection
{
    public $hostname = 'localhost';
    public $port = 6379;
    public $unixSocket;
    public $password;
    public $database = 0;
    public $connectionTimeout;
    public $dataTimeout;
    public $retries = 0;
    public $retryInterval = 0;
    public $socketClientFlags;

    protected $connectionCharsAdd = 2;
    protected $clientClassName;
    protected $connectionString;
    protected $socket = false;
    protected $_pool = [];
    protected $lastErrorCode;
    protected $lastErrorDescr;

    public function __construct($config = [])
    {
        foreach ($config as $k => $v) {
            if (isset($this->$k)) $this->$k = $v;
        }
        $this->connectionString = $this->getConnectionString();
    }

    public function getConnectionString()
    {
        if ($this->unixSocket) {
            return 'unix://' . $this->unixSocket;
        }

        return "tcp://$this->hostname:$this->port";
    }

    public function open()
    {
        if ($this->socket !== false) {
            return;
        }

        $errno = $errstr = null;

        $this->socket = $this->clientClassName::connect(
            $this->hostname, 
            $this->port, 
            $this->lastErrorCode, 
            $this->lastErrorDescr, 
            $this->connectionTimeout ?: ini_get('default_socket_timeout'), 
            $this->socketClientFlags
        );

        if ($this->socket) {
            $this->_pool[ $this->connectionString ] = $this->socket;
            if ($this->password !== null) {
                $this->pipeline(['AUTH ' . $this->password]);
            }
            Helper::printer("Redis connected to $this->hostname/$this->port using $this->clientClassName class");
        } else {
            throw new \Exception("Failed to open DB connection. $errstr [$errno]");
        }
    }

    public function close()
    {
        $success = null;
        foreach ($this->_pool as $socket) {
            try {
                // $this->pipeline(['QUIT']);
            } catch (\Exception $e) {
                $success = false;
            }

            // if ($this->clientClassName::close($socket)) {
            //     $success = $success ?? true;
            // } else {
            //     $success = $success ?? false;
            // }
            $success = $success ?? (bool) $this->clientClassName::close($socket);
        }

        $this->_pool = [];

        return $success ?? true;
    }

    public function pipeline($commands)
    {
        $this->open();

        $cnt = count($commands);

        $command = implode("\n", $commands) . "\n";

        $written = $this->clientClassName::write($this->socket, $command, 0, true);

        Helper::printer("Written: [$written] " . \substr($command, 0, 100));

        if ($written === false) {
            throw new \Exception("Failed to write to socket.\nRedis command was: " . $command);
        }
        if ($written !== ($len = mb_strlen($command, '8bit'))) {
            throw new \Exception("Failed to write to socket. $written of $len bytes written.\nRedis command was: " . $command);
        }

        return $this->parsePipeline($command, $cnt);
    }

    protected function parsePipeline($command = null, $cnt = 0)
    {
        $result = [];

        while ($cnt) {
            $line = trim($this->clientClassName::readline($this->socket), "\r\n");

            if ($line === false) {
                return null;
            }

            $type = $line[0];
            $line = mb_substr($line, 1, null, '8bit');

            if ($type == '+') {
                if ($line === 'OK' || $line === 'PONG') {
                    $result[] = true;
                } else {
                    $result[] = $line;
                }
            } elseif ($type == '-') {
                Helper::printer("Redis responce error: $line");
                $result[] = null;
            } elseif ($type == ':') {
                $result[] = $line;
            } elseif ($type == '$') {

                if ($line == '-1') {
                    $result[] = null;
                } else {
                    $length = (int)$line + $this->connectionCharsAdd;
                    $data = '';
                    while ($length > 0) {
                        if (($block = $this->clientClassName::read($this->socket, $length, true)) === false) {
                            Helper::printer("Failed to read from socket.\nRedis command was: " . "[" . strlen($command) . "]" . $command);
                            $data = null;
                            break;
                        } else {
                            if ($block[0] == "\n") {
                                $data .= mb_substr($block, 1, null, '8bit');
                            } else {
                                $data .= $block;
                            }
                            $length -= mb_strlen($block, '8bit');
                        }
                    }
    
                    if ($data === null) {
                        $result[] = $data;
                    } else {
                        $result[] = mb_substr($data, 0, -2, '8bit');
                    }
                }

            } elseif ($type == '*') {
                $count = (int) $line;
                $data = [];
                for ($i = 0; $i < $count; $i++) {
                    $data[] = $this->parsePipeline($command, $cnt);
                }
                $result[] = $data;
            } else {
                Helper::printer("Redis incorrect command: $line");
                $result[] = null;
            }

            $cnt--;
        }
        
        return $result;
    }
}

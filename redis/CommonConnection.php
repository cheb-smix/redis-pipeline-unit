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
    public $TCPTimeout = 10;
    public $TCPKeepAlive = 0;
    public $retries = 0;
    public $retryInterval = 0;
    public $useRedisFormat = false;
    public $socketClientFlags;

    public $clientClassName;
    public $lastPing = 0;

    protected $address;
    protected $socket = false;
    protected $_pool = [];
    protected $lastErrorCode;
    protected $lastErrorDescr;
    protected $logStringLimit = 0;

    public function __construct($config = [])
    {
        foreach ($config as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
        $this->setConnectionString();
        $this->lastPing = time();
        $this->open();
    }

    public function setConnectionString()
    {
        if ($this->unixSocket && is_readable($this->unixSocket) && is_writeable($this->unixSocket)) {
            $this->address = 'unix://' . $this->unixSocket;
            $this->port = null;
        } else {
            $this->address = $this->hostname;
        }
    }

    public function open($reconnect = false)
    {
        if (!$reconnect && $this->socket !== false) {
            return;
        }

        $errno = $errstr = null;

        $this->socket = $this->clientClassName::connect(
            $this->address, 
            $this->port, 
            $this->lastErrorCode, 
            $this->lastErrorDescr, 
            $this->TCPTimeout ?: ini_get('default_socket_timeout'), 
            $this->socketClientFlags
        );

        if ($this->socket) {
            $this->_pool[ $this->port ? "tcp://$this->address:$this->port" : $this->address ] = $this->socket;

            if ($this->password !== null) {
                $this->pipeline(['AUTH ' . $this->password]);
            }

            if (!$reconnect && $KeepAlive = $this->pipeline(['CONFIG GET timeout'])) {
                $KeepAlive = (int) $KeepAlive[0][1];
                if ($KeepAlive) {
                    $this->TCPKeepAlive = (int) ($KeepAlive * 0.9);
                }
            }

            Helper::printer("Redis " . ($reconnect ? "re" : "") . "connected to $this->address:$this->port using $this->clientClassName class");
            Helper::printer("Current Redis Timeout: $this->TCPKeepAlive");
        } else {
            throw new \Exception("Failed to open DB connection [$this->address:$this->port]. $errstr [$errno]");
        }
    }

    public function keepAlive()
    {
        if ($this->TCPKeepAlive && $this->lastPing < time() - $this->TCPKeepAlive) {
            $this->lastPing = time();
            $res = $this->pipeline(['PING']);
            if (!$res || !$res[0]) {
                $this->open(true);
            }
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

        $command = $this->buildCommand($commands);

        $written = $this->clientClassName::write($this->socket, $command, 0, true);

        Helper::printer("Written: [$written] " . ($this->logStringLimit ? \substr($command, 0, $this->logStringLimit) : $command));

        if ($written === false) {
            throw new \Exception("Failed to write to socket.\nRedis command was: " . $command);
        }
        if ($written !== ($len = mb_strlen($command, '8bit'))) {
            throw new \Exception("Failed to write to socket. $written of $len bytes written.\nRedis command was: " . $command);
        }

        $this->lastPing = time();

        return $this->parsePipeline($command, $cnt);
    }

    protected function parsePipeline($command = null, $cnt = 0)
    {
        $result = [];

        while ($cnt--) {
            $line = $this->clientClassName::readline($this->socket);

            $lineBreaker = mb_substr($line, -1, 1, '8bit') == "\n";
  
            $line = trim($line, "\r\n");

            if (!$line) {
                $cnt++;
                Helper::printer("Redis EMPTY responce!");
                while ($cnt--) {
                    $result[] = null;
                }
                break;
            }

            $type = $line[0];
            $line = mb_substr($line, 1, null, '8bit');

            Helper::printer("Redis responce inbound: [ type: " . var_export($type, true) . ", line: " . var_export($line, true) . "]");

            if (!$lineBreaker) {
                $this->clientClassName::read($this->socket, 1, true); // just for reading next \n symbol after \r
            }

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
                    $length = (int)$line + 2;
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

                $result[] = $this->parsePipeline($command, (int) $line);

            } else {

                Helper::printer("Redis unhandled responce: [ type: " . var_export($type, true) . ", line: " . var_export($line, true) . "]");
                $result[] = null;

            }
        }
        
        return $result;
    }

    protected function buildCommand($cmds)
    {
        $endLine = "\n";

        if (!$this->useRedisFormat) {
            return implode($endLine, $cmds) . $endLine;
        }

        $command = "";
        foreach ($cmds as $cmd) {
            $cmd = explode(" ", $cmd);
            $command .= '*' . count($cmd) . $endLine;
            foreach ($cmd as $arg) {
                $command .= '$' . mb_strlen($arg, '8bit') . $endLine . $arg . $endLine;
            }
        }

        return $command;
    }
}

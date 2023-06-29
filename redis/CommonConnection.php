<?php

namespace redis;

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

    public $logger;
    public $clientClassName;
    public $lastPing = 0;
    public $commandsStat = [
        '+' => 0,
        '-' => 0,
        ':' => 0,
        '$' => 0,
        '*' => 0,
        '?' => 0,
    ];
    public $lastErrorCommand;
    public $lastErrorDescription;

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

            $this->logger->success("Redis " . ($reconnect ? "re" : "") . "connected to $this->address:$this->port using $this->clientClassName class");
            $this->logger->info("Current Redis Timeout: $this->TCPKeepAlive");
        } else {
            $this->logger->exception("Failed to open DB connection [$this->address:$this->port]. $errstr [$errno]");
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

        $this->logger->info("Written: [$written] " . ($this->logStringLimit ? \substr($command, 0, $this->logStringLimit) : $command));

        if ($written === false) {
            $this->logger->exception("Failed to write to socket.\nRedis command was: " . $command);
        }
        if ($written !== ($len = mb_strlen($command, '8bit'))) {
            $this->logger->exception("Failed to write to socket.\nRedis command was: " . $command);
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
                $this->logger->error("Redis EMPTY responce!");
                while ($cnt--) {
                    $result[] = null;
                }
                break;
            }

            $type = $line[0];
            $line = mb_substr($line, 1, null, '8bit');

            $this->logger->info("Redis responce inbound: [ type: " . var_export($type, true) . ", line: " . var_export($line, true) . "]");

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

                $this->logger->error("Redis responce error: $line on command: $command");
                $result[] = null;
                $this->lastErrorCommand = $command;
                $this->lastErrorDescription = $line;

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
                            $this->logger->error("Failed to read from socket.\nRedis command was: " . "[" . strlen($command) . "]" . $command);
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

                $this->logger->error("Redis unhandled responce: [ type: " . var_export($type, true) . ", line: " . var_export($line, true) . "]");
                $result[] = null;
                $type = "?";

            }

            $this->commandsStat[$type]++;
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

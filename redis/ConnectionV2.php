<?php

namespace redis;

use rpu\Helper;
use websocket\SocketClient as Client;

class ConnectionV2
{
    public $hostname = 'localhost';
    public $port = 6379;
    public $unixSocket;
    public $password;
    public $database = 0;
    public $connectionTimeout;
    public $dataTimeout;
    public $useSSL = false;
    public $socketClientFlags = STREAM_CLIENT_CONNECT;
    public $retries = 0;
    public $retryInterval = 0;

    private $connectionString;
    private $socket = false;
    private $_pool = [];

    public function __construct($config = [])
    {
        foreach ($config as $k => $v) {
            if (isset($this->$k)) $this->$k = $v;
        }
        $this->connectionString = $this->getConnectionString();
    }

    public function __sleep()
    {
        $this->close();
        return array_keys(get_object_vars($this));
    }

    public function __call($name, $params)
    {
        $redisCommand = strtoupper($this->camel2words($name, false));
        if (isset(REDIS_COMMANDS[$redisCommand])) {
            return $this->executeCommand($redisCommand, $params);
        }

        return parent::__call($name, $params);
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

        if ($this->socket = Client::connect($this->hostname, $this->port, $errno, $errstr)) {

            $this->_pool[ $this->connectionString ] = $this->socket;

            if ($this->password !== null) {
                $this->pipeline(['AUTH ' .$this->password]);
            }

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

            // if (Client::close($socket)) {
            //     $success = $success ?? true;
            // } else {
            //     $success = $success ?? false;
            // }
            $success = $success ?? (bool) Client::close($socket);
        }

        $this->_pool = [];

        return $success ?? true;
    }

    public function pipeline($commands)
    {
        $this->open();

        $cnt = count($commands);

        $command = " " . implode("\r\n ", $commands);

        $written = Client::write($this->socket, $command, 0, true);

        Helper::printer("Written: [$written] $command");

        if ($written === false) {
            throw new \Exception("Failed to write to socket.\nRedis command was: " . $command);
        }
        if ($written !== ($len = mb_strlen($command, '8bit'))) {
            throw new \Exception("Failed to write to socket. $written of $len bytes written.\nRedis command was: " . $command);
        }

        return $this->parsePipeline($command, $cnt);
    }

    private function parsePipeline($command = null, $cnt = 0)
    {
        $result = [];

        while ($cnt) {
            Helper::printer("Reading: $cnt");

            $line = preg_replace("/[\r\n]/", "", Client::readline($this->socket));

            if ($line === false) {
                $result[] = null;
            }

            $type = $line[0];
            $line = substr($line, 1);

            Helper::printer("Readline result: $line, type: $type");

            if ($type == '+') {
                if ($line === 'OK' || $line === 'PONG') {
                    $result[] = true;
                } else {
                    $result[] = $line;
                }
            } elseif ($type == '-') {
                $result[] = null;
            } elseif ($type == ':') {
                $result[] = $line;
            } elseif ($type == '$') {

                if ($line == '-1') {
                    $result[] = null;
                } else {
                    Client::readline($this->socket);
                    $result[] = Client::readline($this->socket);
                    Client::readline($this->socket);
                }
                Helper::printer("BULK");
                Helper::printer($result, true);

            } elseif ($type == '*') {
                $count = (int) $line;
                $data = [];
                for ($i = 0; $i < $count; $i++) {
                    $data[] = $this->parsePipeline($command, $cnt);
                }
                $result[] = $data;
            } else {
                $result[] = null;
            }

            

            $cnt--;
        }
        
        return $result;
    }

    public function camel2words($name, $ucwords = true)
    {
        $label = mb_strtolower(trim(str_replace([
            '-',
            '_',
            '.',
        ], ' ', preg_replace('/(?<!\p{Lu})(\p{Lu})|(\p{Lu})(?=\p{Ll})/u', ' \0', $name))), self::encoding());

        return $ucwords ? $this->mb_ucwords($label, self::encoding()) : $label;
    }

    public function mb_ucwords($string, $encoding = 'UTF-8')
    {
        $words = preg_split("/\s/u", $string, -1, PREG_SPLIT_NO_EMPTY);

        $titelized = array_map(function ($word) use ($encoding) {
            return static::mb_ucfirst($word, $encoding);
        }, $words);

        return implode(' ', $titelized);
    }
}

<?php

namespace redis;

class Connection
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

        $connection = $this->connectionString . ', database=' . $this->database;
        $this->socket = @stream_socket_client(
            $this->connectionString,
            $errorNumber,
            $errorDescription,
            $this->connectionTimeout ?: ini_get('default_socket_timeout'),
            $this->socketClientFlags
        );

        if ($this->socket) {
            $this->_pool[ $this->connectionString ] = $this->socket;

            if ($this->dataTimeout !== null) {
                stream_set_timeout($this->socket, $timeout = (int) $this->dataTimeout, (int) (($this->dataTimeout - $timeout) * 1000000));
            }
            if ($this->useSSL) {
                stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if ($this->password !== null) {
                $this->executeCommand('AUTH', [$this->password]);
            }
            // if ($this->database !== null) {
            //     $this->executeCommand('SELECT', [$this->database]);
            // }
        } else {
            throw new \Exception("Failed to open DB connection. $errorDescription [$errorNumber]");
        }
    }

    public function close()
    {
        foreach ($this->_pool as $socket) {
            $connection = $this->connectionString . ', database=' . $this->database;
            try {
                $this->executeCommand('QUIT');
            } catch (\Exception $e) {

            }
            fclose($socket);
        }

        $this->_pool = [];
    }

    public function executeCommand($name, $params = [])
    {
        $this->open();

        $params = array_merge(explode(' ', $name), $params);
        $command = '*' . count($params) . "\r\n";
        foreach ($params as $arg) {
            $command .= '$' . mb_strlen($arg, '8bit') . "\r\n" . $arg . "\r\n";
        }

        if ($this->retries > 0) {
            $tries = $this->retries;
            while ($tries-- > 0) {
                try {
                    return $this->sendCommandInternal($command, $params);
                } catch (\Exception $e) {
                    $retries = $this->retries;
                    $this->retries = 0;
                    $this->close();
                    if ($this->retryInterval > 0) {
                        usleep($this->retryInterval);
                    }
                    $this->open();
                    $this->retries = $retries;
                }
            }
        }
        return $this->sendCommandInternal($command, $params);
    }

    private function sendCommandInternal($command, $params)
    {
        $written = @fwrite($this->socket, $command);
        if ($written === false) {
            throw new \Exception("Failed to write to socket.\nRedis command was: " . $command);
        }
        if ($written !== ($len = mb_strlen($command, '8bit'))) {
            throw new \Exception("Failed to write to socket. $written of $len bytes written.\nRedis command was: " . $command);
        }

        return $this->parseResponse($params, $command);
    }

    private function parseResponse($params, $command = null)
    {
        $prettyCommand = implode(' ', $params);

        if (($line = fgets($this->socket)) === false) {
            throw new \Exception("Failed to read from socket.\nRedis command was: " . $prettyCommand);
        }
        $type = $line[0];
        $line = mb_substr($line, 1, -2, '8bit');
        switch ($type) {
            case '+': // Status reply
                if ($line === 'OK' || $line === 'PONG') {
                    return true;
                }

                return $line;
            case '-': // Error reply
                throw new \Exception("Redis error: " . $line . "\nRedis command was: " . $prettyCommand);
            case ':': // Integer reply
                // no cast to int as it is in the range of a signed 64 bit integer
                return $line;
            case '$': // Bulk replies
                if ($line == '-1') {
                    return null;
                }
                $length = (int)$line + 2;
                $data = '';
                while ($length > 0) {
                    if (($block = fread($this->socket, $length)) === false) {
                        throw new \Exception("Failed to read from socket.\nRedis command was: " . $prettyCommand);
                    }
                    $data .= $block;
                    $length -= mb_strlen($block, '8bit');
                }

                return mb_substr($data, 0, -2, '8bit');
            case '*': // Multi-bulk replies
                $count = (int) $line;
                $data = [];
                for ($i = 0; $i < $count; $i++) {
                    $data[] = $this->parseResponse($params);
                }

                return $data;
            default:
                throw new \Exception('Received illegal data from redis: ' . $line . "\nRedis command was: " . $prettyCommand);
        }
    }

    public function pipeline($commands, $params = [])
    {
        $this->open();

        $cnt = count($commands);

        $command = implode("\r\n", $commands) . "\r\n";

        $written = @fwrite($this->socket, $command);

        if ($written === false) {
            throw new \Exception("Failed to write to socket.\nRedis command was: " . $command);
        }
        if ($written !== ($len = mb_strlen($command, '8bit'))) {
            throw new \Exception("Failed to write to socket. $written of $len bytes written.\nRedis command was: " . $command);
        }

        return $this->parsePipeline($params, $command, $cnt);
    }

    private function parsePipeline($params, $command = null, $cnt = 0)
    {
        $prettyCommand = $command . ' ' . implode(' ', $params);

        $result = [];

        while ($cnt) {

            $line = fgets($this->socket);

            if ($line === false) {
                throw new \Exception("Failed to read from socket.\nRedis command was: " . $prettyCommand);
            }

            $type = $line[0];
            $line = mb_substr($line, 1, -2, '8bit');

            switch ($type) {
                case '+': 
                    if ($line === 'OK' || $line === 'PONG') {
                        $result[] = true;
                        break;
                    }
    
                    $result[] = $line;
                    break;
                case '-': 
                    throw new \Exception("Redis error: " . $line . "\nRedis command was: " . $prettyCommand);
                case ':': 
                    $result[] = $line;
                    break;
                case '$': // Bulk replies
                    if ($line == '-1') {
                        $result[] = null;
                        break;
                    }
                    $length = (int)$line + 2;
                    $data = '';
                    while ($length > 0) {
                        if (($block = fread($this->socket, $length)) === false) {
                            throw new \Exception("Failed to read from socket.\nRedis command was: " . $prettyCommand);
                        }
                        $data .= $block;
                        $length -= mb_strlen($block, '8bit');
                    }
    
                    $result[] = mb_substr($data, 0, -2, '8bit');
                    break;
                case '*': 
                    $count = (int) $line;
                    $data = [];
                    for ($i = 0; $i < $count; $i++) {
                        $data[] = $this->parsePipeline($params);
                    }
    
                    $result[] = $data;
                    break;
                default:
                    throw new \Exception('Received illegal data from redis: ' . $line . "\nRedis command was: " . $prettyCommand);
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

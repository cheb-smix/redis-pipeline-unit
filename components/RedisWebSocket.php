<?php

namespace console\components;

use Yii;
use console\components\websocket\SmixWebSocketClient;

class RedisWebSocket extends SmixWebSocketClient
{
    public function __call($name, $params)
    {
        return $this->send($this->buildCommand($name, $params));
    }

    public function flush()
    {
        return $this->send($this->buildCommand('FLUSH'));
    }

    public function set($key, $value, $expire = 0, $mode = "SET")
    {
        if ($expire == 0) {
            $setArr = [ $key, $this->prepareValue($value) ];
        } else {
            $setArr = [ $key, $this->prepareValue($value), 'EX', (int) $expire ];
        }

        return $this->send($this->buildCommand('SET', $setArr));
    }

    public function get($key)
    {
        return $this->send($this->buildCommand('GET', [$key]));
    }

    public function multiSet($items, $expire = 0)
    {
        $args = [];
        foreach ($items as $key => $value) {
            $args[] = $key;
            $args[] = $this->prepareValue($value);
        }

        $failedKeys = [];
        if ($expire == 0) {
            $this->send($this->buildCommand('MSET', $args));
        } else {
            $expire = (int) ($expire * 1000);
            $this->send($this->buildCommand('MULTI'));
            $this->send($this->buildCommand('MSET', $args));
            $index = [];
            foreach ($items as $key => $value) {
                $this->send($this->buildCommand('PEXPIRE', [$key, $expire]));
                $index[] = $key;
            }
            $result = $this->send($this->buildCommand('EXEC'));
            array_shift($result);
            foreach ($result as $i => $r) {
                if ($r != 1) {
                    $failedKeys[] = $index[$i];
                }
            }
        }

        return $failedKeys;
    }

    public function multiGet($keys)
    {
        return $this->send($this->buildCommand('MGET', $keys));
    }

    public function keys($pattern)
    {
        return $this->send($this->buildCommand("KEYS", [$pattern]));
    }

    public function getRange($key, $index = 0, $length = 1)
    {
        return $this->send($this->buildCommand("GETRANGE", [$key, $index, $length]));
    }

    public function del($key)
    {
        return $this->send($this->buildCommand("DEL", [$key]));
    }

    private function prepareValue($value = "")
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    
    private function buildCommand($name, $params = [])
    {
        return implode(" ", array_merge([$name], $params));
    }
}

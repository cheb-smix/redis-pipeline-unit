<?php
namespace websocket;

use rpu\Helper;

class StreamCommon implements \websocket\CommonInterface
{
    public static function readline(&$socket)
    {
        return @fgets($socket);
    }

    public static function read(&$socket, int $lengthInitiatorNumber = 9, bool $clearly = false)
    {
        if ($clearly) {
            return fread($socket, $lengthInitiatorNumber);
        }

        if ($length = (int) fread($socket, $lengthInitiatorNumber)) {
            return fread($socket, $length);
        }
        
        return false;
    }

    public static function write(&$socket, $data, int $lengthInitiatorNumber = 9, bool $clearly = false)
    {
        if ($clearly) {
            return fwrite($socket, $data, \strlen($data));
        }

        if (!$data) $data = "0";

        if ($length = strlen($data)) {
            return fwrite($socket, sprintf("%0{$lengthInitiatorNumber}d", $length) . $data, $length + $lengthInitiatorNumber);
        }
        
        return false;
    }

    public static function send(&$socket, $data, int $lengthInitiatorNumber = 9, bool $clearly = false)
    {
        if ($d = self::write($socket, $data, $lengthInitiatorNumber, $clearly)) {
            return self::read($socket, $lengthInitiatorNumber, $clearly);
        }

        return false;
    }

    public static function close(&$socket)
    {
        return $socket ? fclose($socket) : false;
    }

    public static function handshake(&$socket, int $lengthInitiatorNumber = 9)
    {
        $info = [];

        $data = self::read($socket, $lengthInitiatorNumber);

        if (!$data) return $info;

        $data = explode("\n", $data);
        $header = explode(' ', array_shift($data));
        $info['method'] = $header[0];
        $info['uri'] = $header[1] ?? "";

        foreach ($data as $line) {
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $info[$matches[1]] = $matches[2];
            } else {
                break;
            }
        }
    
        if (empty($info['Sec-WebSocket-Key'])) {
            return false;
        }

        $SecWebSocketAccept = base64_encode(pack('H*', sha1($info['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept:$SecWebSocketAccept\r\n\r\n";

        if (self::write($socket, $upgrade, $lengthInitiatorNumber)) {
            return $info;
        }
    
        return false;
    }
}

class StreamServer extends StreamCommon implements ServerInterface
{
    public static function create($hostname, $port, &$errno, &$errstr)
    {
        return stream_socket_server("tcp://$hostname:$port", $errno, $errstr);
    }

    public static function set_buffers(&$socket, $size = 8192)
    {
        return false;
    }

    public static function set_chunk_size(&$socket, $size = 8192)
    {
        return stream_set_chunk_size($socket, $size);
    }

    public static function select(&$read, &$write = null, &$except = null, $timeout = 0)
    {
        return stream_select($read, $write, $except, $timeout);
    }

    public static function accept(&$socket, $timeout = 0, &$peer_name)
    {
        return stream_socket_accept($socket, $timeout, $peer_name);
    }
}

class StreamClient extends StreamCommon implements \websocket\ClientInterface
{
    public static function connect($address, $port = null, &$errno, &$errstr, $timeout = 0, $flags = null)
    {
        if (!$port) {
            return @stream_socket_client($address, $errno, $errstr, $timeout, $flags);
        } else {
            return @stream_socket_client("tcp://$address:$port", $errno, $errstr, $timeout, $flags);
        }
    }
}
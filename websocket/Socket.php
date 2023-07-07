<?php
namespace websocket;

use rpu\Helper;

class SocketCommon implements \websocket\CommonInterface
{
    public static function readline(&$socket)
    {
        return @socket_read($socket, 1024, PHP_NORMAL_READ);
    }

    public static function read(&$socket, int $lengthInitiatorNumber = 9, bool $clearly = false)
    {
        if ($clearly) {
            return socket_read($socket, $lengthInitiatorNumber);
        }

        if ($length = (int) socket_read($socket, $lengthInitiatorNumber)) {
            return @socket_read($socket, $length);
        }
        
        return false;
    }

    public static function write(&$socket, $data, int $lengthInitiatorNumber = 9, bool $clearly = false)
    {
        if ($clearly) {
            return socket_send($socket, $data, \strlen($data), MSG_EOF);
        }

        if (!$data) $data = "0";

        if ($length = strlen($data)) {
            return @socket_write($socket, sprintf("%0{$lengthInitiatorNumber}d", $length) . $data, $length + $lengthInitiatorNumber);
        }

        return false;
    }

    public static function send(&$socket, $data, int $lengthInitiatorNumber = 9)
    {
        if (self::write($socket, $data, $lengthInitiatorNumber)) {
            return self::read($socket, $lengthInitiatorNumber);
        }

        return false;
    }

    public static function close(&$socket)
    {
        if ($socket) {
            @socket_shutdown($socket, 2);
            socket_close($socket);
            unset($socket);
        }
        return true;
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

    public static function set_buffers(&$socket, $size = 8192)
    {
        return (
            socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, $size)
            &&
            socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, $size)
        );
    }
}


class SocketServer extends SocketCommon implements \websocket\ServerInterface
{
    public static function create($hostname, $port, &$errno, &$errstr)
    {
        if (!$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
            return false;
        }
        if (!socket_bind($socket, $hostname, $port)) {
            return false;
        }
        if (!socket_listen($socket)) {
            return false;
        }

        return $socket;
    }

    public static function set_chunk_size(&$socket, $size = 8192)
    {
        return false;
    }

    public static function select(&$read, &$write = null, &$except = null, $timeout = 0)
    {
        return socket_select($read, $write, $except, $timeout);
    }

    public static function accept(&$socket, $timeout = null, &$peer_name = null)
    {
        return socket_accept($socket);
    }
}


class SocketClient extends SocketCommon implements \websocket\ClientInterface
{
    public static function connect($address, $port = null, &$errno, &$errstr, $timeout = 0, $flags = null)
    {
        if (!$port) {
            $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        } else {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        }

        if (!$socket) {
            $errno = socket_last_error($socket);
            $errstr = socket_strerror($errno);
            return false;
        }
        if (!socket_connect($socket, $address, $port)) {
            $errno = socket_last_error($socket);
            $errstr = socket_strerror($errno);
            return false;
        }

        return $socket;
    }
}


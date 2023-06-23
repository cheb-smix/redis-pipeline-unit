<?php
namespace websocket;

interface CommonInterface
{
    public static function readline(&$socket);
    public static function read(&$socket, int $lengthInitiatorNumber = 9, bool $clearly = false);
    public static function write(&$socket, $data, int $lengthInitiatorNumber = 9, bool $clearly = false);
    public static function send(&$socket, $data, int $lengthInitiatorNumber = 9);
    public static function close(&$connection);
    public static function handshake(&$socket, int $lengthInitiatorNumber = 9);
}

interface ServerInterface
{
    public static function create($hostname, $port, &$errno, &$errstr);
    public static function set_buffers(&$socket, $size = 8192);
    public static function set_chunk_size(&$socket, $size = 8192);
    public static function select(&$read, &$write = null, &$except = null, $timeout = 0);
    public static function accept(&$socket, $timeout = 0, &$peer_name);
}

interface ClientInterface
{
    public static function connect($hostname, $port, &$errno, &$errstr, $timeout = 0, $flags = null);
}
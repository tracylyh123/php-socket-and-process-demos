<?php
namespace Demos\Channel;

/**
 * Class Daemon
 * @package Demos\Channel
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start client: php Broadcast.php {broadcast number} {local address} {local port}
 */
class Broadcast
{
    const LINE_LENGTH = 1024;

    protected $id;

    protected $broadcast;

    protected $address;

    protected $port;

    public function __construct($broadcast, $address, $port)
    {
        $this->broadcast = $broadcast;
        $this->address = $address;
        $this->port = $port;
        $this->id = md5(uniqid(posix_getpid()));
    }

    public function start()
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (false === $socket) {
            die('socket_create() failed');
        }
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (false === socket_bind($socket, $this->address, $this->port)) {
            die('socket_bind() failed');
        }
        $pid = pcntl_fork();
        if ($pid < 0) {
            die('pcntl_fork() failed');
        }
        if ($pid == 0) {
            $lineLength = self::LINE_LENGTH + strlen($this->id);
            $recvLength = intval($lineLength / 3) * 4;
            if ($lineLength % 3 != 0) {
                $recvLength += 4;
            }
            while (true) {
                $bytes = socket_recvfrom($socket, $encoded, $recvLength, 0, $this->address, $this->port);
                if (false !== $bytes) {
                    $decoded = base64_decode($encoded);
                    if ($this->id !== substr($decoded, 0, 32)) {
                        echo substr($decoded, 32);
                    }
                } else {
                    echo 'socket_recvfrom() failed, reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL;
                }
            }
        }
        while (true) {
            $line = stream_get_line(STDIN, self::LINE_LENGTH, PHP_EOL);
            if (false !== $line) {
                $encoded = base64_encode($this->id . $line . PHP_EOL);
                socket_sendto($socket, $encoded, strlen($encoded), 0, $this->broadcast, $this->port);
            } else {
                echo 'stream_get_line() failed' . PHP_EOL;
            }
        }
    }
}

$broadcast = $argv[1] ?? '192.168.33.255';
$address = $argv[2] ?? '0.0.0.0';
$port = $argv[3] ?? 12345;
$server = new Broadcast($broadcast, $address, $port);
$server->start();

<?php
namespace Demos\Channel;

/**
 * Class TcpServer
 * @package Demos\Channel
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php TcpServer.php {your local ip address} {your server port}
 * client usage: telnet {ip} {port}
 */
class TcpServer
{
    protected $address;

    protected $port;

    public function __construct(string $address, int $port)
    {
        $this->address = $address;
        $this->port = $port;
    }

    public function start()
    {
        if (false === ($serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            die('socket_create() failed, reason: ' . socket_strerror(socket_last_error()) . PHP_EOL);
        }
        if (false === socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            die('socket_set_option() failed, reason: ' . socket_strerror(socket_last_error($serverSocket)) . PHP_EOL);
        }
        if (false === socket_bind($serverSocket, $this->address, $this->port)) {
            die('socket_bind() failed, reason: ' . socket_strerror(socket_last_error($serverSocket)) . PHP_EOL);
        }
        if (false === socket_listen($serverSocket)) {
            die('socket_listen() failed, reason: ' . socket_strerror(socket_last_error($serverSocket)) . PHP_EOL);
        }
        echo "Socket server({$this->address}:{$this->port}) started and waiting for the client connections coming..." . PHP_EOL;
        $watchedSockets = [$serverSocket];
        $segmentLength = 1024;
        while (true) {
            $read = $watchedSockets;
            if (false === socket_select($read, $write, $except, null)) {
                echo 'socket_select() failed, reason: ' . socket_strerror(socket_last_error()) . PHP_EOL;
                continue;
            }
            foreach ($read as $reactedSocket) {
                if ($reactedSocket === $serverSocket) {
                    if (false !== ($acceptedSocket = socket_accept($reactedSocket))) {
                        $acceptedSocketId = intval($acceptedSocket);
                        foreach ($watchedSockets as $key => $watchedSocket) {
                            if ($watchedSocket === $serverSocket) {
                                continue;
                            }
                            if (false === ($written = socket_write($watchedSocket, "Client({$acceptedSocketId}) joined" . PHP_EOL))) {
                                echo 'socket_write() failed, reason: ' . socket_strerror(socket_last_error($watchedSocket)) . PHP_EOL;
                                socket_close($watchedSocket);
                                unset($watchedSockets[$key]);
                            }
                        }
                        $watchedSockets[] = $acceptedSocket;
                        echo sprintf('Accepted a new client connection(%d)', intval($acceptedSocket)) . PHP_EOL;
                    } else {
                        echo 'socket_accept() failed, reason: ' . socket_strerror(socket_last_error($reactedSocket)) . PHP_EOL;
                    }
                    continue;
                }
                $reactedSocketId = intval($reactedSocket);
                $firstSegment = true;
                while (($bytes = socket_recv($reactedSocket, $data, $segmentLength, 0)) > 0) {
                    if (false === $bytes) {
                        echo 'socket_recv() failed, reason: ' . socket_strerror(socket_last_error($reactedSocket)) . PHP_EOL;
                        break 2;
                    } else {
                        echo sprintf('Received %d bytes data from the client connection(%d)', $bytes, $reactedSocketId) . PHP_EOL;
                    }
                    foreach ($watchedSockets as $key => $watchedSocket) {
                        if ($watchedSocket === $reactedSocket || $watchedSocket === $serverSocket) {
                            continue;
                        }
                        if ($firstSegment) {
                            $written = socket_write($watchedSocket, "Client({$reactedSocketId}) said: {$data}");
                        } else {
                            $written = socket_write($watchedSocket, $data);
                        }
                        if (false === $written) {
                            echo 'socket_write() failed, reason: ' . socket_strerror(socket_last_error($watchedSocket)) . PHP_EOL;
                            socket_close($watchedSocket);
                            unset($watchedSockets[$key]);
                        } else {
                            echo sprintf('Written %d bytes data to the client connection(%d)', $written, intval($watchedSocket)) . PHP_EOL;
                        }
                    }
                    if (false !== strpos($data, PHP_EOL)) {
                        break 2;
                    }
                    $firstSegment = false;
                }
                if (false !== ($index = array_search($reactedSocket, $watchedSockets))) {
                    unset($watchedSockets[$index]);
                }
                socket_close($reactedSocket);
                echo sprintf('Disconnected the client connection(%d)', $reactedSocketId) . PHP_EOL;
                foreach ($watchedSockets as $key => $watchedSocket) {
                    if ($watchedSocket === $serverSocket) {
                        continue;
                    }
                    if (false === ($written = socket_write($watchedSocket, "Client({$reactedSocketId}) left" . PHP_EOL))) {
                        echo 'socket_write() failed, reason: ' . socket_strerror(socket_last_error($watchedSocket)) . PHP_EOL;
                        socket_close($watchedSocket);
                        unset($watchedSockets[$key]);
                    }
                }
            }
        }
    }
}

$address = $argv[1] ?? '0.0.0.0';
$port = $argv[2] ?? random_int(1024, 65535);
$server = new TcpServer($address, $port);
$server->start();

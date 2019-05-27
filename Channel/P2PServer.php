<?php
namespace Demos\Channel;

/**
 * Class P2PServer
 * @package Demos\Channel
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php P2PServer.php {address} {port}
 * client usage: php P2PClient.php {address} {port}
 */
class P2PServer
{
    protected $address;

    protected $port;

    public function __construct($address, $port)
    {
        $this->address = $address;
        $this->port = $port;
    }

    protected function getErrorReason($socket = null)
    {
        return socket_strerror(socket_last_error($socket));
    }

    public function start()
    {
        if (false === ($ssocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            die('socket_create() failed, reason: ' . $this->getErrorReason() . PHP_EOL);
        }
        if (false === socket_set_option($ssocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            die('socket_set_option() failed, reason: ' . $this->getErrorReason($ssocket) . PHP_EOL);
        }
        if (false === socket_bind($ssocket, $this->address, $this->port)) {
            die('socket_bind() failed, reason: ' . $this->getErrorReason($ssocket) . PHP_EOL);
        }
        if (false === socket_listen($ssocket)) {
            die('socket_listen() failed, reason: ' . $this->getErrorReason($ssocket) . PHP_EOL);
        }
        $sockets = [$ssocket];
        while (true) {
            $read = $sockets;
            if (false === socket_select($read, $write, $except, null)) {
                echo 'socket_select() failed, reason: ' . $this->getErrorReason() . PHP_EOL;
                continue;
            }
            foreach ($read as $reacted) {
                if ($reacted !== $ssocket) {
                    if (false === socket_getpeername($reacted, $address, $port)) {
                        echo 'socket_getpeername() failed, reason: ' . $this->getErrorReason($reacted) . PHP_EOL;
                    } else {
                        foreach ($sockets as $key => $socket) {
                            if ($ssocket === $socket || $reacted === $socket) {
                                continue;
                            }
                            if (false === socket_write($socket, "{$address}:{$port}\n")) {
                                echo 'socket_write() failed, reason: ' . $this->getErrorReason($socket) . PHP_EOL;
                            }
                        }
                    }
                    socket_close($reacted);
                    $key = array_search($reacted, $sockets);
                    if (false !== $key) {
                        unset($sockets[$key]);
                    }
                    continue;
                }
                if (false === ($accepted = socket_accept($reacted))) {
                    echo 'socket_accept() failed, reason: ' . $this->getErrorReason($reacted) . PHP_EOL;
                    continue;
                }
                if (false === socket_getpeername($accepted, $address, $port)) {
                    echo 'socket_getpeername() failed, reason: ' . $this->getErrorReason($accepted) . PHP_EOL;
                    socket_close($accepted);
                    continue;
                }
                $remotes = [];
                foreach ($sockets as $key => $socket) {
                    if ($ssocket === $socket || $reacted === $socket) {
                        continue;
                    }
                    if (false === socket_write($socket, "{$address}:{$port}\n")) {
                        echo 'socket_write() failed, reason: ' . $this->getErrorReason($socket) . PHP_EOL;
                        socket_close($socket);
                        unset($sockets[$key]);
                    } else {
                        if (false === socket_getpeername($socket, $address, $port)) {
                            echo 'socket_getpeername() failed, reason: ' . $this->getErrorReason($socket) . PHP_EOL;
                            socket_close($socket);
                            unset($sockets[$key]);
                        } else {
                            $remotes[] = "{$address}:{$port}";
                        }
                    }
                }
                if (false === socket_write($accepted, implode(',', $remotes) . "\n")) {
                    echo 'socket_write() failed, reason: ' . $this->getErrorReason($accepted) . PHP_EOL;
                    socket_close($accepted);
                    continue;
                }
                $sockets[] = $accepted;
            }
        }
    }
}

$address = $argv[1] ?? '0.0.0.0';
$port = $argv[2] ?? random_int(1024, 65535);
$s = new P2PServer($address, $port);
$s->start();

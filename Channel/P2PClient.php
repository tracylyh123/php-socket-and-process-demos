<?php
namespace Demos\Channel;

/**
 * Class P2PClient
 * @package Demos\Channel
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start client: php P2PClient.php {server address} {server port}
 */
class P2PClient
{
    protected $serverAddress;

    protected $serverPort;

    public function __construct($serverAddress, $serverPort)
    {
        $this->serverAddress = $serverAddress;
        $this->serverPort = $serverPort;
    }

    protected function getErrorReason($socket = null)
    {
        return socket_strerror(socket_last_error($socket));
    }

    public function start()
    {
        if (false === socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            die('socket_create_pair() failed, reason: ' . $this->getErrorReason() . PHP_EOL);
        }
        $pid = pcntl_fork();
        if ($pid < 0) {
            die('pcntl_fork() failed' . PHP_EOL);
        } elseif ($pid == 0) {
            socket_close($sockets[1]);
            if (false === ($server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
                die('socket_create() failed, reason: ' . $this->getErrorReason() . PHP_EOL);
            }
            if (false === socket_connect($server, $this->serverAddress, $this->serverPort)) {
                die('socket_connect() failed, reason: ' . $this->getErrorReason($server) . PHP_EOL);
            }
            if (false === socket_getsockname($server, $address, $port)) {
                die('socket_getsockname() failed, reason: ' . $this->getErrorReason($server) . PHP_EOL);
            }
            if (false === ($client = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP))) {
                die('socket_create() failed, reason: ' . $this->getErrorReason($client) . PHP_EOL);
            }
            if (false === socket_bind($client, $address, $port)) {
                die('socket_bind() failed, reason: ' . $this->getErrorReason($client) . PHP_EOL);
            }
            $remotes = [];
            $buf = '';
            $watched = [$server, $client, $sockets[0]];
            while (true) {
                $read = $watched;
                if (false === socket_select($read, $write, $except, null)) {
                    echo 'socket_select() failed, reason: ' . $this->getErrorReason() . PHP_EOL;
                    continue;
                }
                foreach ($read as $socket) {
                    if ($socket === $server) {
                        $bytes = socket_recv($socket, $distab, 1024, 0);
                        if (false !== $bytes) {
                            $buf .= $distab;
                            if (0 !== $bytes && "\n" !== $distab[-1]) {
                                continue;
                            } elseif (0 === $bytes) {
                                $key = array_search($socket, $watched);
                                if (false !== $key) {
                                    unset($watched[$key]);
                                }
                                continue;
                            }
                            foreach (explode(',', trim($buf)) as $item) {
                                if (!in_array($item, $remotes) && false !== strpos($item, ':')) {
                                    $remotes[] = $item;
                                } else {
                                    $key = array_search($item, $remotes);
                                    if (false !== $key) {
                                        unset($remotes[$key]);
                                    }
                                }
                            }
                            $buf = '';
                        } else {
                            $errno = socket_last_error($socket);
                            if ($errno == SOCKET_EAGAIN) {
                                continue;
                            }
                            echo 'socket_recv() failed, reason: ' . $this->getErrorReason($socket) . PHP_EOL;
                        }
                    } elseif ($socket === $client) {
                        foreach ($remotes as $remote) {
                            list($address, $port) = explode(':', $remote);
                            $bytes = socket_recvfrom($socket, $message, 1024, 0, $address, $port);
                            if (false !== $bytes) {
                                echo $message;
                            } else {
                                $errno = socket_last_error($socket);
                                if ($errno == SOCKET_EAGAIN) {
                                    continue;
                                }
                                echo 'socket_recvfrom() failed, reason: ' . $this->getErrorReason($socket) . PHP_EOL;
                            }
                        }
                    } elseif ($socket === $sockets[0]) {
                        $bytes = socket_recv($socket, $message, 1024, 0);
                        if (false !== $bytes) {
                            foreach ($remotes as $remote) {
                                list($address, $port) = explode(':', $remote);
                                if (false === socket_sendto($client, $message, $bytes, 0, $address, $port)) {
                                    echo 'socket_sendto() failed, reason: ' . $this->getErrorReason($socket) . PHP_EOL;
                                }
                            }
                        } else {
                            $errno = socket_last_error($socket);
                            if ($errno == SOCKET_EAGAIN) {
                                continue;
                            }
                            echo 'socket_recv() failed, reason: ' . $this->getErrorReason($socket) . PHP_EOL;
                        }
                    }
                }
            }
        }
        socket_close($sockets[0]);
        while (true) {
            $line = stream_get_line(STDIN, 1024, PHP_EOL) . PHP_EOL;
            if (false === $line) {
                echo 'stream_get_line() failed' . PHP_EOL;
                continue;
            }
            socket_write($sockets[1], $line, strlen($line));
            $cid = pcntl_waitpid($pid, $status, WNOHANG);
            if ($cid > 0) {
                break;
            } elseif (-1 === $cid) {
                echo 'pcntl_waitpid() failed, reason: ' . PHP_EOL;
            }
        }
    }
}

if (!isset($argv[1]) || !isset($argv[2])) {
    die('server address and server port is required' . PHP_EOL);
}
$serverAddress = $argv[1];
$serverPort = $argv[2];
$client = new P2PClient($serverAddress, $serverPort);
$client->start();

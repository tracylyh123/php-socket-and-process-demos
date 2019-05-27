<?php
namespace Demos\Channel;

/**
 * Class WsServer
 * @package Demos\Channel
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php WsServer.php {your local ip address} {your server port}
 * client usage: wscat -c ws://{ip}:{port}
 */
class WsServer
{
    const MESSAGE_TYPE_TEXT = 1;
    const MESSAGE_TYPE_CLOSE = 8;
    const MESSAGE_TYPE_PING = 9;
    const MESSAGE_TYPE_PONG = 10;

    const OUTPUT_CONSOLE = 0;
    const OUTPUT_NULL = 1;
    const OUTPUT_LOG = 2;

    protected $address;

    protected $port;

    protected $watchedSockets = [];

    protected $output;

    public function __construct(string $address, int $port, int $output = self::OUTPUT_CONSOLE)
    {
        $this->address = $address;
        $this->port = $port;
        $this->output = $output;
    }

    protected function output(string $message)
    {
        switch ($this->output) {
            case self::OUTPUT_CONSOLE:
                echo $message . PHP_EOL;
                break;
            case self::OUTPUT_LOG:
                file_put_contents('/tmp/ws-server.log', $message . PHP_EOL);
                break;
            case self::OUTPUT_NULL:
                break;
            default:
                echo $message . PHP_EOL;
                break;
        }
    }

    protected function isColse(string $frames)
    {
        return (ord($frames[0]) & self::MESSAGE_TYPE_CLOSE) === self::MESSAGE_TYPE_CLOSE;
    }

    protected function isPing(string $frames)
    {
        return (ord($frames[0]) & self::MESSAGE_TYPE_PING) === self::MESSAGE_TYPE_PING;
    }

    protected function getPayloadLength(string $frames)
    {
        return ord($frames[1]) & 127;
    }

    protected function getDataLength(string $frames)
    {
        $length = $this->getPayloadLength($frames);
        if($length == 126) {
            $unpack = unpack('nlen', substr($frames, 2, 2));
            return $unpack['len'];
        } elseif ($length == 127) {
            $unpack = unpack('Jlen', substr($frames, 2, 8));
            return $unpack['len'];
        }
        return $length;
    }

    protected function getRemainLength(string $framses)
    {
        $length = $this->getPayloadLength($framses);
        if ($length == 126) {
            return 6;
        } elseif ($length == 127) {
            return 12;
        }
        return 4;
    }

    protected function decode(string $frames)
    {
        $length = $this->getPayloadLength($frames);
        if($length == 126) {
            $masks = substr($frames, 4, 4);
            $payload = substr($frames, 8);
        } elseif($length == 127) {
            $masks = substr($frames, 10, 4);
            $payload = substr($frames, 14);
        } else {
            $masks = substr($frames, 2, 4);
            $payload = substr($frames, 6);
        }
        $text = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $text .= $payload[$i] ^ $masks[$i % 4];
        }
        return $text;
    }

    protected function encode(string $message, int $messageType)
    {
        $b1 = $messageType + 128;
        $length = strlen($message);
        $lengthField = "";
        if($length < 126) {
            $b2 = $length;
        } elseif ($length <= 65536) {
            $b2 = 126;
            $hexLength = dechex($length);
            if(strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;
            for($i = $n; $i >= 0; $i -= 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while(strlen($lengthField) < 2) {
                $lengthField = chr(0) . $lengthField;
            }
        } else {
            $b2 = 127;
            $hexLength = dechex($length);
            if(strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;
            for($i = $n; $i >= 0; $i -= 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while(strlen($lengthField) < 8) {
                $lengthField = chr(0) . $lengthField;
            }
        }
        return chr($b1) . chr($b2) . $lengthField . $message;
    }

    protected function handshake($acceptedSocket)
    {
        $headers = socket_read($acceptedSocket, 1024);
        if (false === $headers) {
            return false;
        }
        if(!preg_match("/Sec-WebSocket-Version: (.*)\r\n/", $headers, $match)) {
            return false;
        }
        $version = $match[1];
        if ($version != 13) {
            return false;
        }
        if(!preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $match)) {
            return false;
        }
        $key = $match[1];
        $acceptKey = $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        $acceptKey = base64_encode(sha1($acceptKey, true));
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: $acceptKey\r\n" .
            "\r\n";
        if (false === socket_write($acceptedSocket, $upgrade)) {
            $this->output('socket_write() failed, reason: ' . socket_strerror(socket_last_error($acceptedSocket)));
            return false;
        }
        return true;
    }

    protected function notifyWatchedSockets(string $message, $reactedSocket = null)
    {
        foreach ($this->watchedSockets as $key => $watchedSocket) {
            if ((isset($reactedSocket) && $watchedSocket === $reactedSocket) || $key === 0) {
                continue;
            }
            if (false === ($written = socket_write($watchedSocket, $this->encode($message, self::MESSAGE_TYPE_TEXT)))) {
                $this->output('socket_write() failed, reason: ' . socket_strerror(socket_last_error($watchedSocket)));
                socket_close($watchedSocket);
                unset($this->watchedSockets[$key]);
            } else {
                $this->output(sprintf('Written %d bytes data to the client connection(%d)', $written, intval($watchedSocket)));
            }
        }
    }

    protected function recvFromSocket($reactedSocket, &$frames = '')
    {
        $dataLength = 2;
        $readNum = $length = 0;
        $frames = '';
        socket_set_option($reactedSocket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
        while (($bytes = socket_recv($reactedSocket, $data, $dataLength, MSG_WAITALL)) > 0) {
            $readNum++;
            if (false === $bytes) {
                $this->output('socket_recv() failed, reason: ' . socket_strerror(socket_last_error($reactedSocket)));
                return false;
            }
            $length += $bytes;
            $frames .= $data;

            if ($readNum === 1) {
                $dataLength = $this->getRemainLength($frames);
                continue;
            } elseif ($readNum === 2) {
                $dataLength = $this->getDataLength($frames);
            } else {
                $dataLength -= $bytes;
            }
            if ($dataLength < 1) {
                break;
            }
        }
        return $length;
    }

    protected function acceptSocket($reactedSocket)
    {
        if (false !== ($acceptedSocket = socket_accept($reactedSocket))) {
            $acceptedSocketId = intval($acceptedSocket);
            if (false === $this->handshake($acceptedSocket)) {
                if (false === socket_write($acceptedSocket, "HTTP/1.1 400 Bad Request\r\n\r\n")) {
                    $this->output('socket_write() failed, reason: ' . socket_strerror(socket_last_error($acceptedSocket)));
                }
                socket_close($acceptedSocket);
                $this->output("Handshake is failed with the client({$acceptedSocketId})");
            } else {
                $this->notifyWatchedSockets("Client({$acceptedSocketId}) joined");
                $this->watchedSockets[] = $acceptedSocket;
                $this->output("Handshake is successfuly done with client({$acceptedSocketId})");
                $this->output(sprintf('Accepted a new client connection(%d)', intval($acceptedSocket)));
            }
        } else {
            $this->output('socket_accept() failed, reason: ' . socket_strerror(socket_last_error($reactedSocket)));
        }
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
        $this->output("Socket server({$this->address}:{$this->port}) started and waiting for the client connections coming...");
        $this->watchedSockets = [$serverSocket];
        while (true) {
            $read = $this->watchedSockets;
            if (false === socket_select($read, $write, $except, null)) {
                $this->output('socket_select() failed, reason: ' . socket_strerror(socket_last_error()));
                continue;
            }
            foreach ($read as $reactedSocket) {
                if ($reactedSocket === $serverSocket) {
                    $this->acceptSocket($reactedSocket);
                    continue;
                }
                $reactedSocketId = intval($reactedSocket);
                if (false === ($length = $this->recvFromSocket($reactedSocket, $frames))) {
                    continue;
                }
                $this->output(sprintf('Received %d bytes data from the client connection(%d)', $length, $reactedSocketId));
                if ($length > 0) {
                    $decodedMessage = $this->decode($frames);
                    if ($this->isPing($frames)) {
                        socket_write($reactedSocket, $this->encode($decodedMessage, self::MESSAGE_TYPE_PONG));
                    } elseif ($this->isColse($frames)) {
                        socket_write($reactedSocket, $this->encode($decodedMessage, self::MESSAGE_TYPE_CLOSE));
                    } else {
                        $this->notifyWatchedSockets("Client({$reactedSocketId}) said: " . $decodedMessage, $reactedSocket);
                    }
                    continue;
                }
                if (false !== ($key = array_search($reactedSocket, $this->watchedSockets))) {
                    unset($this->watchedSockets[$key]);
                }
                socket_close($reactedSocket);
                $this->output(sprintf('Disconnected the client connection(%d)', $reactedSocketId));
                $this->notifyWatchedSockets("Client({$reactedSocketId}) left");
            }
        }
    }
}

//$address = $argv[1] ?? '0.0.0.0';
//$port = $argv[2] ?? random_int(1024, 65535);
//$server = new WsServer($address, $port);
//$server->start();

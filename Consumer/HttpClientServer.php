<?php
namespace Demos\Consumer;
/**
 * Class HttpClientServer
 * @package Demos\Consumer
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php HttpClientServer.php
 * client usage: nc -U /tmp/http-request-server.sock
 */
class Consumer
{
    protected $pipe;

    public function initPipe()
    {
        if (false === socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            die('socket_create_pair() failed, reason: ' . socket_strerror(socket_last_error()));
        }
        $pid = pcntl_fork();
        if ($pid < 0) {
            die('pcntl_fork() failed');
        } elseif ($pid == 0) {
            socket_close($sockets[1]);
            while (true) {
                $path = socket_read($sockets[0], 1024);
                if (false === $path) {
                    echo 'socket_read() failed, reason: ' . socket_strerror(socket_last_error($sockets[0])) . PHP_EOL;
                    continue;
                }
                if (file_exists($path)) {
                    $payload = '';
                    $gz = gzopen($path, 'r');
                    if (false === $gz) {
                        echo 'gzopen() failed' . PHP_EOL;
                        continue;
                    }
                    while ($string = gzread($gz, 2048)) {
                        $payload .= $string;
                    }
                    gzclose($gz);
                    unlink($path);
                    /**
                     * @var $request Request
                     */
                    $request = unserialize($payload);
                    if (false === $request) {
                        echo 'unserialize() failed' . PHP_EOL;
                        continue;
                    }
                    $request->handleResponse();
                }
            }
        }
        socket_close($sockets[0]);
        $this->pipe = $sockets[1];
    }

    public function excute(RequestBatch $batch)
    {
        $mh = curl_multi_init();
        if (false === $mh) {
            echo 'curl_multi_init() failed' . PHP_EOL;
            return;
        }
        $mapping = [];
        /**
         * @var $request Request
         */
        foreach ($batch as $index => $request) {
            $h = curl_init($request->getUrl());
            if (false === $h) {
                echo 'curl_init() failed' . PHP_EOL;
                continue;
            }
            $mapping[(int) $h] = $index;
            curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($h, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($h, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($h, CURLOPT_TIMEOUT, 30);
            if (0 !== curl_multi_add_handle($mh, $h)) {
                echo 'curl_multi_add_handle() failed, reason: ' . curl_multi_strerror(curl_multi_errno($mh)) . PHP_EOL;
                continue;
            }
        }

        $active = $batch->count();
        do {
            curl_multi_exec($mh, $active);
            if (-1 === curl_multi_select($mh)) {
                usleep(1000);
            }
            $minfo = curl_multi_info_read($mh);
            if (false === $minfo || $minfo['result'] !== CURLE_OK) {
                continue;
            }
            $index = $mapping[(int) $minfo['handle']];
            $content = curl_multi_getcontent($minfo['handle']);
            $request = $batch[$index];
            $info = curl_getinfo($minfo['handle']);
            if ($info['http_code'] == 200) {
                $response = new Response($info['http_code'], $content);
                $request->setResponse($response);
                $path = '/tmp/' . uniqid() . '.gz';
                $gz = gzopen($path, 'w9');
                if (false !== $gz) {
                    gzwrite($gz, serialize($request));
                    gzclose($gz);
                    if (false === socket_write($this->pipe, $path, strlen($path))) {
                        echo 'socket_write() failed, reason: ' . socket_strerror(socket_last_error($this->pipe)) . PHP_EOL;
                    }
                } else {
                    echo 'gzopen() failed' . PHP_EOL;
                }
            }
            if (0 !== curl_multi_remove_handle($mh, $minfo['handle'])) {
                echo 'curl_multi_remove_handle() failed, reason: ' . curl_multi_strerror(curl_multi_errno($mh)) . PHP_EOL;
            }
            curl_close($minfo['handle']);
            unset($mapping[(int) $minfo['handle']]);
        } while ($active);
        curl_multi_close($mh);
    }
}

class Request
{
    protected $url;

    protected $method;

    protected $payload;

    protected $handler;

    protected $response;

    public function __construct(
        string $url,
        string $method,
        ResponseHandler $handler,
        string $payload = null
    ) {
        $this->url = $url;
        $this->method = $method;
        $this->handler = $handler;
        $this->payload = $payload;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function setResponse(Response $response)
    {
        $this->response = $response;
        return $this;
    }

    public function handleResponse()
    {
        if ($this->response instanceof Response) {
            $this->handler->handle($this->response);
        }
        return $this;
    }
}

class Response
{
    protected $httpCode;

    protected $content;

    public function __construct($httpCode, $content)
    {
        $this->httpCode = $httpCode;
        $this->content = $content;
    }

    public function getContent()
    {
        return $this->content;
    }
}

interface ResponseHandler
{
    function handle(Response $response);
}

class UrlFilterHandler implements ResponseHandler
{
    public function handle(Response $response)
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $nodes = $dom->getElementsByTagName('a');
        $count = 0;
        /**
         * @var $node \DOMElement
         */
        foreach ($nodes as $node) {
            $url = $node->getAttribute('href');
            if (false === strpos($url, 'http')) {
                continue;
            }
            $count++;
//            echo $url . PHP_EOL;
        }
        echo $count . PHP_EOL;
    }
}

class RequestBatch extends \ArrayIterator
{
    public function merge(RequestBatch $batch)
    {
        /**
         * @var $request Request
         */
        foreach ($batch as $request) {
            $this->append($request);
        }
        return $this;
    }
}

class HttpClientServer
{
    protected $consumerNo;

    public function __construct($consumerNo)
    {
        $this->consumerNo = $consumerNo;
    }

    public function start()
    {
        $sock = '/tmp/http-request-server.sock';
        if (file_exists($sock)) {
            unlink($sock);
        }
        $consumers = [];
        for ($i = 0; $i < $this->consumerNo; $i++) {
            $consumer = new Consumer();
            $consumer->initPipe();
            $consumers[] = $consumer;
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (false === $socket) {
            die('socket_create() failed, reason: ' . socket_strerror(socket_last_error()) . PHP_EOL);
        }
        if (false === socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            die('socket_set_option() failed, reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL);
        }
        if (false === socket_bind($socket, $sock)) {
            die('socket_bind() failed, reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL);
        }
        if (false === socket_listen($socket)) {
            die('socket_listen() failed. reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL);
        }

        $sockets = [$socket];
        while (true) {
            $read = $sockets;
            if (false === socket_select($read, $write, $except, null)) {
                echo 'socket_select() failed, reason: ' . socket_strerror(socket_last_error()) . PHP_EOL;
                continue;
            }
            $batch = new RequestBatch();
            foreach ($read as $reacted) {
                if ($reacted === $socket) {
                    if (false !== ($accepted = socket_accept($reacted))) {
                        $sockets[] = $accepted;
                    } else {
                        echo 'socket_accept() failed, reason: ' . socket_strerror(socket_last_error($reacted)) . PHP_EOL;
                    }
                    continue;
                }
                $bufs = '';
                do {
                    if ($bytes = socket_recv($reacted, $buf, 2048, MSG_DONTWAIT)) {
                        if (false === $bytes) {
                            echo 'socket_recv() failed, reason: ' . socket_strerror(socket_last_error($reacted)) . PHP_EOL;
                            continue;
                        }
                    }
                    $bufs .= $buf;
                    if (false !== strpos($buf, PHP_EOL)) {
                        $unserialized = unserialize(base64_decode(trim($bufs)));
                        if ($unserialized instanceof RequestBatch) {
                            $batch->merge($unserialized);
                        } elseif ($unserialized instanceof Request) {
                            $batch->append($unserialized);
                        } else {
                            echo 'unserialize() failed' . PHP_EOL;
                        }
                        break;
                    }
                } while (true);
            }
            if ($batch->count() > 0) {
                $n = random_int(0, $this->consumerNo - 1);
                $consumers[$n]->excute($batch);
            }
        }
    }
}

// example
$handler = new UrlFilterHandler();
$batch = new RequestBatch();
$request1 = new Request('https://www.qq.com', 'GET', $handler);
$request2 = new Request('http://www.hao123.com', 'GET', $handler);
$batch->append($request1);
$batch->append($request2);
// input the string in "nc" program
echo base64_encode(serialize($batch)) . PHP_EOL;

$consumerNo = $argv[1] ?? 2;
$server = new HttpClientServer($consumerNo);
$server->start();
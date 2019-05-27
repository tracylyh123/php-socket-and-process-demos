<?php
namespace Demos\Consumer;
/**
 * Class ActivityServer
 * @package Demos\Consumer
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php ActivityServer.php {total inventory} {activity deadline}
 * client usage:
 * curl --request POST --url http://192.168.33.11:12345 \
 * --header 'Content-Type: application/x-www-form-urlencoded' \
 * --data 'user_id=1'
 */
class Inventory
{
    protected $tickets;

    protected $remain;

    protected $history;

    protected $deadline;

    public function __construct(Tickets $tickets, $deadline)
    {
        $this->tickets = $tickets;
        $this->deadline = $deadline;
        $this->remain = $tickets->count();
    }

    public function getHistory()
    {
        return $this->history;
    }

    public function hasRemain()
    {
        return !empty($this->remain);
    }

    public function getTimeToDeadline()
    {
        $remain = $this->deadline - time();
        return $remain < 0 ? 0 : $remain;
    }

    public function getDeadline()
    {
        return $this->deadline;
    }

    public function acquire(RequestAction $action)
    {
        if ($this->tickets->isEmpty()) {
            return false;
        }
        /**
         * @var $ticket Ticket
         */
        $ticket = $this->tickets->pop();
        $this->history[$ticket->getCode()] = [
            'user_id' => $action->getUserId(),
            'acquired_at' => microtime()
        ];
        $this->remain = $this->tickets->count();
        return $ticket->getCode();
    }
}

class InventoryFactory
{
    public static function create(int $num, int $deadline)
    {
        $tickets = new Tickets();
        for ($i = 0; $i < $num; $i++) {
            $ticket = new Ticket(uniqid($i));
            $tickets->push($ticket);
        }
        return new Inventory($tickets, $deadline);
    }
}

class Ticket
{
    protected $cokde;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function getCode()
    {
        return $this->code;
    }
}

class Tickets extends \SplStack
{

}

class RequestAction
{
    protected $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    public function getUserId()
    {
        return $this->userId;
    }
}

class HttpException extends \Exception
{

}

class Http
{
    const HTTP_METHOD_POST = 'POST';

    const HTTP_PROTOCOL_1_1 = 'HTTP/1.1';

    const CONTENT_TYPE_FORM_DATA = 'application/x-www-form-urlencoded';
}

class HttpResponse
{
    protected $code;

    protected $protocol;

    protected $method;

    protected $content;

    public function __construct(
        int $code,
        string $protocol,
        string $method,
        string $content
    ) {
        $this->code = $code;
        $this->protocol = $protocol;
        $this->method = $method;
        $this->content = $content;
    }

    public function setCode(int $code)
    {
        $this->code = $code;
        return $this;
    }

    public function setContent(string $content)
    {
        $this->content = $content;
        return $this;
    }

    public function getCodeMessage()
    {
        $message = '';
        switch ($this->code) {
            case 400:
                $message = 'Bad Request';
                break;
            case 500:
                $message = 'Internal Server Error';
                break;
            case 200;
                $message = 'OK';
        }
        return $message;
    }

    public function __toString()
    {
        $contentLength = strlen($this->content);
        $headers = [
            "{$this->protocol} {$this->code} {$this->getCodeMessage()}",
            "Connection: close",
            "Content-Length: {$contentLength}",
            "Content-Type: html/text"
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $this->content;
    }
}

class HttpRequest
{
    protected $method;

    protected $uri;

    protected $protocol;

    protected $payload;

    protected $contentType;

    protected $contentLength;

    public function __construct(
        string $method,
        string $uri,
        string $protocol,
        string $payload,
        string $contentType = null,
        string $contentLegth = null
    ) {
        $this->method = $method;
        $this->uri = $uri;
        $this->protocol = $protocol;
        $this->payload = $payload;
        $this->contentType = $contentType;
        $this->contentLegth = $contentLegth;
    }

    public function isPost()
    {
        return $this->method === Http::HTTP_METHOD_POST;
    }

    public function isHttp1()
    {
        return $this->protocol === Http::HTTP_PROTOCOL_1_1;
    }

    public function isFormData()
    {
        return isset($this->contentType) && $this->contentType === Http::CONTENT_TYPE_FORM_DATA;
    }

    public function checkLength()
    {
        return isset($this->contentLegth) && strlen($this->payload) == $this->contentLegth;
    }

    public function parsePayload()
    {
        if ($this->checkLength()) {
            parse_str($this->payload, $result);
            return $result;
        }
        return false;
    }

    public static function create(string $request)
    {
        $headers = [];
        $exception = new HttpException('Invalid http header');
        if (substr_count($request, "\r\n") < 2) {
            throw $exception;
        }
        list($header, $payload) = explode("\r\n\r\n", $request);
        $lines = explode("\r\n", $header);
        $first = array_shift($lines);
        if (substr_count($first, ' ') != 2) {
            throw $exception;
        }
        list($method, $uri, $protocol) = explode(' ', $first);
        foreach ($lines as $line) {
            if (false !== strpos($line, ':')) {
                list($key, $value) = explode(':', $line);
                $headers[trim($key)] = trim($value);
            }
        }
        return new HttpRequest(
            $method,
            $uri,
            $protocol,
            $payload,
            $headers['Content-Type'] ?? null,
            $headers['Content-Length'] ?? null
        );
    }
}

abstract class AbstractActivityMiddleware
{
    abstract function handle(string $raw) : string;
}

class InventoryMiddleware extends AbstractActivityMiddleware
{
    protected $inventory;

    public function __construct(Inventory $inventory)
    {
        $this->inventory = $inventory;
        pcntl_alarm($inventory->getTimeToDeadline());
    }

    public function handle(string $raw) : string
    {
        $response = new HttpResponse(400, Http::HTTP_PROTOCOL_1_1, Http::HTTP_METHOD_POST, 'error');
        try {
            $request = HttpRequest::create($raw);
        } catch (HttpException $e) {
            return $response;
        }
        if (!$request->isPost()) {
            return $response;
        }
        if (!$request->isHttp1()) {
            return $response;
        }
        if (!$request->isFormData()) {
            return $response;
        }
        $parsed = $request->parsePayload();
        if (empty($parsed['user_id'])) {
            return $response->setContent('Invalid payload data');
        }
        $action = new RequestAction($parsed['user_id']);
        $code = $this->inventory->acquire($action);
        if (!$this->inventory->hasRemain()) {
            posix_kill(posix_getpid(), SIGTERM);
        }
        if (false === $code) {
            $message = 'all tickets have been acquired';
        } else {
            // store to nosql or push to queue or something else
            $message = "acquired the ticket: {$code}";
        }
        return new HttpResponse(200, Http::HTTP_PROTOCOL_1_1, Http::HTTP_METHOD_POST, $message);
    }
}

class ActivityServer
{
    protected $address;

    protected $port;

    protected $middleware;

    protected $sockets = [];

    public function __construct(string $address, int $port, AbstractActivityMiddleware $middleware)
    {
        $this->address = $address;
        $this->port = $port;
        $this->middleware = $middleware;
        $close = function () {
            $this->close();
        };
        pcntl_signal(SIGTERM, $close);
        pcntl_signal(SIGALRM, $close);
    }

    public function close()
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        exit;
    }

    public function start()
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        if (false === $socket) {
            die('socket_create() failed, reason: ' . socket_strerror(socket_last_error()) . PHP_EOL);
        }
        if (false === socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            die('socket_set_option() failed, reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL);
        }
        if (false === socket_bind($socket, $this->address, $this->port)) {
            die('socket_bind() failed, reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL);
        }
        if (false === socket_listen($socket)) {
            die('socket_listen() failed. reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL);
        }
        $this->sockets = [$socket];
        while (true) {
            pcntl_signal_dispatch();
            $read = $this->sockets;
            if (false === @socket_select($read, $write, $except, null)) {
                $errno = socket_last_error();
                if ($errno !== SOCKET_EINTR) {
                    echo 'socket_select() failed, reason: ' . socket_strerror($errno) . PHP_EOL;
                }
                continue;
            }
            foreach ($read as $reacted) {
                if ($reacted === $socket) {
                    if (false !== ($accepted = socket_accept($reacted))) {
                        $this->sockets[] = $accepted;
                    } else {
                        echo 'socket_accept() failed, reason: ' . socket_strerror(socket_last_error($reacted)) . PHP_EOL;
                    }
                    continue;
                }
                $raw = socket_read($reacted, 2048);
                if (false === $raw) {
                    echo 'socket_read() failed, reason: ' . socket_strerror(socket_last_error($reacted));
                } elseif (false === socket_write($reacted, $this->middleware->handle($raw))) {
                    echo 'socket_write() failed, reason: ' . socket_strerror(socket_last_error($reacted));
                }
                if (false !== ($key = array_search($reacted, $this->sockets))) {
                    unset($this->sockets[$key]);
                }
                socket_close($reacted);
            }
        }
    }
}

$total = $argv[1] ?? 30;
$deadline = $argv[2] ?? time() + (3600 * 24);
$address = '0.0.0.0';
$port = 12345;
$middleware = new InventoryMiddleware(InventoryFactory::create($total, $deadline));
$activity = new ActivityServer($address, $port, $middleware);
$activity->start();

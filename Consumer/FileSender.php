<?php
namespace Demos\Consumer\Sender;
/**
 * Class FileSender
 * @package Demos\Consumer
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start client: php FileSender.php {file path}
 */
class FileSender
{
    protected $address;

    protected $port;

    public function __construct($address, $port)
    {
        $this->address = $address;
        $this->port = $port;
    }

    public function send(File $file)
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (false === $socket) {
            throw new SocketException('socket_create() failed');
        }
        if (false === socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => MAX_SEND_TIME, 'usec' => 0])) {
            $exception = new SocketException('socket_create() failed');
            $exception->setSocket($socket);
            throw $exception;
        }
        foreach ($file as $num => $chunck) {
            $data = json_encode($chunck);
            if (false === $data) {
                throw new JsonException('json_encode() failed');
            }
            if (false === socket_sendto($socket, $data, strlen($data), 0, $this->address, $this->port)) {
                $exception = new SocketException('socket_sendto() failed');
                $exception->setSocket($socket);
                throw $exception;
            }
            if (false === socket_recvfrom($socket, $buf, strlen(RECEIVED_FLAG), MSG_WAITALL, $this->address, $this->port)) {
                $exception = new SocketException('socket_recvfrom() failed');
                $exception->setSocket($socket);
                throw $exception;
            }
            if ($buf != RECEIVED_FLAG) {
                throw new ArgumentException('Invalid received flag');
            }
        }
        $info = json_encode($file->getSentMessage());
        if (false === $info) {
            throw new JsonException('json_encode() failed');
        }
        if (false === socket_sendto($socket, $info, strlen($info), 0, $this->address, $this->port)) {
            $exception = new SocketException('socket_sendto() failed');
            $exception->setSocket($socket);
            throw $exception;
        }
        socket_close($socket);
    }
}

abstract class AbstractMessage implements \JsonSerializable
{
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    abstract function toArray(): array;
}

class ChunckMessage extends AbstractMessage
{
    protected $data;

    protected $fileChecksum;

    protected $sequence;

    public function __construct(string $data, $fileChecksum, $sequence)
    {
        $this->data = $data;
        $this->fileChecksum = $fileChecksum;
        $this->sequence = $sequence;
    }

    public function toArray(): array
    {
        return [
            'message_type' => TYPE_CHUNCK,
            'data' => base64_encode($this->data),
            'sequence' => $this->sequence,
            'file_checksum' => $this->fileChecksum,
            'chunck_checksum' => md5($this->data),
            'chunck_name' => $this->fileChecksum . '_' . $this->sequence
        ];
    }
}

class SentMessage extends AbstractMessage
{
    protected $size;

    protected $name;

    protected $fileChecksum;

    protected $totalChuncks;

    public function __construct($size, $name, $fileChecksum ,$totalChuncks)
    {
        $this->size = $size;
        $this->name = $name;
        $this->fileChecksum = $fileChecksum;
        $this->totalChuncks = $totalChuncks;
    }

    public function toArray(): array
    {
        return [
            'message_type' => TYPE_SENT,
            'file_size' => $this->size,
            'file_name' => $this->name,
            'file_checksum' => $this->fileChecksum,
            'total_chuncks' => $this->totalChuncks
        ];
    }
}

class File implements \Iterator
{
    protected $path;

    protected $header;

    protected $position;

    protected $size;

    protected $fileChecksum;

    protected $name;

    public function __construct(string $path)
    {
        if (!file_exists($path)) {
            throw new ArgumentException('File was not found');
        }
        if (false === ($checksum = md5_file($path))) {
            throw new IoException('md5_file() failed');
        }
        $this->path = $path;
        $this->position = 0;
        $this->size = filesize($path);
        $this->name = basename($path);
        $this->fileChecksum = $checksum;
    }

    public function rewind()
    {
        $this->position = 0;
    }

    public function current()
    {
        $handle = fopen($this->path, 'rb');
        if (false === $handle) {
            throw new IoException('fopen() failed');
        }
        if (-1 === fseek($handle, $this->position)) {
            throw new IoException('fseek() failed');
        }
        $buffer = fread($handle, CHUNCK_SIZE);
        if (false === $buffer) {
            throw new IoException('fread() failed');
        }
        if (-1 === fseek($handle, $this->position)) {
            throw new IoException('fseek() failed');
        }
        fclose($handle);
        return new ChunckMessage($buffer, $this->fileChecksum, $this->key());
    }

    public function key()
    {
        return ceil($this->position / CHUNCK_SIZE);
    }

    public function next()
    {
        $this->position += CHUNCK_SIZE;
    }

    public function valid()
    {
        return $this->position < $this->size;
    }

    public function getSentMessage()
    {
        return new SentMessage(
            $this->size,
            $this->name,
            $this->fileChecksum,
            ceil($this->size / CHUNCK_SIZE)
        );
    }
}

class SocketException extends AbstractReasonException
{
    protected $sokcet = null;

    function setSocket($socket)
    {
        $this->sokcet = $socket;
    }

    function getReason(): string
    {
        return socket_strerror(socket_last_error($this->sokcet));
    }
}

abstract class AbstractReasonException extends \Exception
{
    public function __construct($message = "", $code = 0, \Throwable $previous = null)
    {
        $message .= ', reason: ' . $this->getReason();
        parent::__construct($message, $code, $previous);
    }

    abstract function getReason(): string;
}

class JsonException extends AbstractReasonException
{
    function getReason(): string
    {
        return json_last_error_msg();
    }
}

class IoException extends \Exception
{

}

class ArgumentException extends \Exception
{

}

define('MAX_SEND_TIME', 10);

define('TYPE_SENT', 0);
define('TYPE_CHUNCK', 1);

define('CHUNCK_SIZE', 16384);

define('RECEIVED_FLAG', 1);

define('ADDRESS', '127.0.0.1');
define('PORT', '12345');

if (!isset($argv[1])) {
    die('File path is required');
}
$client = new FileSender(ADDRESS, PORT);
$file = new File($argv[1]);
$client->send($file);

<?php
namespace Demos\Consumer\Receiver;
/**
 * Class FileReceiver
 * @package Demos\Consumer
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php FileReceiver.php
 */
class JobsQueue
{
    protected $path;

    public function __construct(string $path)
    {
        if (!file_exists($path)) {
            if (false === touch($path)) {
                throw new IoException('File cannot be created');
            }
        }
        $this->path = $path;
    }

    public function deQueue(): AbstractJob
    {
        clearstatcache();
        $size = filesize($this->path);
        $handle = fopen($this->path, 'r+');
        if (false === $handle) {
            throw new IoException('fopen() failed');
        }
        if (false === flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new IoException('flock() failed');
        }
        $lastjob = null;
        while (!feof($handle)) {
            $job = fgets($handle);
            if (false !== $job) {
                $lastjob = $job;
            } else {
                if (feof($handle)) {
                    break;
                }
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new IoException('fgets() failed');
            }
        }
        if (!empty($lastjob)) {
            $lastJobSize = strlen($lastjob);
            if (false === rewind($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new IoException('rewind() failed');
            }
            if (false === ftruncate($handle, $size - $lastJobSize)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new IoException('ftruncate() failed');
            }
        } else {
            flock($handle, LOCK_UN);
            fclose($handle);
            return new NullJob();
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        $lastjob = json_decode($lastjob, true);
        if (false === $lastjob || null === $lastjob) {
            throw new JsonException('json_decode() failed');
        }
        return JobFacory::create($lastjob);
    }

    public function enQueue(AbstractJob $job): void
    {
        $handle = fopen($this->path, 'a');
        if (false === $handle) {
            throw new IoException('fopen() failed');
        }
        if (false === flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new IoException('flock() failed');
        }
        if (false === rewind($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new IoException('rewind() failed');
        }
        $jobstr = json_encode($job);
        if (false === $jobstr) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new JsonException('json_encode() failed');
        }
        if (false === fwrite($handle, $jobstr . PHP_EOL)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new IoException('fwrite() failed');
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

class JobFacory
{
    public static function create(array $data): AbstractJob
    {
        if (!isset($data['job_type'])) {
            throw new ArgumentException('job_type field is required');
        }
        if (ChuncksMerger::class === $data['job_type']) {
            if (!isset($data['file_name'])) {
                throw new ArgumentException('file_name field is required');
            }
            if (!isset($data['file_checksum'])) {
                throw new ArgumentException('file_checksum field is required');
            }
            if (!isset($data['total_chuncks'])) {
                throw new ArgumentException('total_chuncks field is required');
            }
            return new ChuncksMerger($data['file_name'], $data['file_checksum'], $data['total_chuncks']);
        }
        throw new ArgumentException('undefined job_type');
    }
}

abstract class AbstractJob implements \JsonSerializable
{
    public function jsonSerialize()
    {
        return array_merge(['job_type' => get_class($this)], $this->toArray());
    }

    abstract function toArray(): array;

    abstract function handle();
}

class NullJob extends AbstractJob
{
    public function toArray(): array
    {
        throw new JobException('Invalid operation');
    }

    public function handle()
    {
        // do nothing
    }
}

class ChuncksMerger extends AbstractJob
{
    protected $fileName;

    protected $fileChecksum;

    protected $totalChuncks;

    public function __construct($fileName, $fileChecksum, $totalChuncks)
    {
        $this->fileName = $fileName;
        $this->fileChecksum = $fileChecksum;
        $this->totalChuncks = $totalChuncks;
    }

    public function handle()
    {
        $merged = WORKING_DIR . $this->fileName;
        $handle = fopen($merged, 'wb');
        if (false === $handle) {
            throw new IoException('fopen() failed');
        }
        if (false === flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new IoException('flock() failed');
        }
        for ($i = 0; $i < $this->totalChuncks; $i++) {
            $file = CHUNCKS_DIR . $this->fileChecksum . '_' . $i;
            if (!file_exists($file)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new ArgumentException('File was not found');
            }
            $handle2 = fopen($file, 'rb');
            if (false === $handle2) {
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new IoException('fopen() failed');
            }
            $data = fread($handle2, CHUNCK_SIZE);
            if (false === $data) {
                flock($handle, LOCK_UN);
                fclose($handle);
                fclose($handle2);
                throw new IoException('fread() failed');
            }
            if (false === fwrite($handle, $data)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                fclose($handle2);
                throw new IoException('fwrite() failed');
            }
            fclose($handle2);
            unlink($file);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        if (md5_file($merged) !== $this->fileChecksum) {
            throw new JobException('Cannot receive file');
        }
    }

    public function toArray(): array
    {
        return [
            'file_name' => $this->fileName,
            'file_checksum' => $this->fileChecksum,
            'total_chuncks' => $this->totalChuncks
        ];
    }
}

class FileReceiver
{
    protected $address;

    protected $port;

    protected $output;

    public function __construct($address, $port)
    {
        $this->address = $address;
        $this->port = $port;
        $this->output = new Console();
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function start(): void
    {
        pcntl_async_signals(true);
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new PcntlException('pcntl_fork() failed');
        }
        if ($pid == 0) {
            if (false === pcntl_sigprocmask(SIG_BLOCK, [SIGCONT])) {
                throw new PcntlException('pcntl_sigprocmask() failed');
            }
            while (true) {
                $signo = pcntl_sigwaitinfo([SIGCONT]);
                if ($signo !== SIGCONT) {
                    $this->output->output(new PcntlException('pcntl_sigwaitinfo() received an invalid signal'));
                    continue;
                }
                try {
                    $queue = new JobsQueue(JOB_LIST);
                    $merger = $queue->deQueue();
                    $merger->handle();
                } catch (\Exception $e) {
                    $this->output->output($e);
                }
            }
            throw new UnknownException('It will never goes to here');
        }
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (false === $socket) {
            throw new SocketException('socket_create() failed');
        }
        if (false === socket_bind($socket, $this->address, $this->port)) {
            $exception = new SocketException('socket_bind() failed');
            $exception->setSocket($socket);
            throw new $exception;
        }
        while (true) {
            if (false === socket_recvfrom($socket, $buf, RECEIVE_SIZE, 0, $name, $port)) {
                $exception = new SocketException('socket_recvfrom() failed');
                $exception->setSocket($socket);
                $this->output->output($exception);
                continue;
            }
            $data = json_decode($buf, true);
            if (false === $data || null === $data) {
                $this->output->output(new JsonException('json_decode() failed'));
                continue;
            }
            if (!isset($data['message_type'])) {
                $this->output->output(new ArgumentException('message_type field is required'));
                continue;
            }
            switch ($data['message_type']) {
                case TYPE_CHUNCK:
                    if (!isset($data['data'])) {
                        $this->output->output(new ArgumentException('data field is required'));
                        continue;
                    }
                    if (!isset($data['chunck_name'])) {
                        $this->output->output(new ArgumentException('chunck_name field is required'));
                        continue;
                    }
                    if (!isset($data['chunck_checksum'])) {
                        $this->output->output(new ArgumentException('chunck_checksum field is required'));
                        continue;
                    }
                    $decoded = base64_decode($data['data']);
                    if (false === $decoded) {
                        $this->output->output(new IoException('base64_decode() failed'));
                        continue;
                    }
                    if (md5($decoded) !== $data['chunck_checksum']) {
                        $this->output->output(new ArgumentException('Invalid data'));
                        continue;
                    }
                    if (false === file_put_contents(CHUNCKS_DIR . $data['chunck_name'], $decoded, LOCK_EX)) {
                        $this->output->output(new IoException('file_put_contents() failed'));
                    }
                    if (false === socket_sendto($socket, RECEIVED_FLAG, strlen(RECEIVED_FLAG), 0, $name, $port)) {
                        $exception = new SocketException('socket_sendto() failed');
                        $exception->setSocket($socket);
                        $this->output->output($exception);
                    }
                    break;
                case TYPE_SENT:
                    try {
                        if (!isset($data['file_name'])) {
                            $this->output->output(new ArgumentException('file_name field is required'));
                            continue;
                        }
                        if (!isset($data['file_checksum'])) {
                            $this->output->output(new ArgumentException('file_checksum field is required'));
                            continue;
                        }
                        if (!isset($data['total_chuncks'])) {
                            $this->output->output(new ArgumentException('total_chuncks field is required'));
                            continue;
                        }
                        $queue = new JobsQueue(JOB_LIST);
                        $filename = basename($data['file_name']);
                        $merger = new ChuncksMerger($filename, $data['file_checksum'], $data['total_chuncks']);
                        $queue->enQueue($merger);
                    } catch (\Exception $e) {
                        $this->output->output($e);
                    }
                    if (false === posix_kill($pid, SIGCONT)) {
                        $this->output->output(new PosixException('posix_kill() failed'));
                    }
                    break;
                default:
                    $this->output->output(new ArgumentException('Unknown message type'));
                    break;
            }
        }
        throw new UnknownException('It will never goes to here');
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

class PcntlException extends AbstractReasonException
{
    function getReason(): string
    {
        return pcntl_strerror(pcntl_get_last_error());
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

class JsonException extends AbstractReasonException
{
    function getReason(): string
    {
        return json_last_error_msg();
    }
}

class PosixException extends AbstractReasonException
{
    function getReason(): string
    {
        return posix_strerror(posix_get_last_error());
    }
}

class IoException extends \Exception
{

}

class JobException extends \Exception
{

}

class ArgumentException extends \Exception
{

}

class UnknownException extends \Exception
{

}

interface OutputInterface
{
    function output(\Exception $message);
}

class Console implements OutputInterface
{
    public function output(\Exception $message)
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message->getMessage() . PHP_EOL;
    }
}

class Logger implements OutputInterface
{
    public function output(\Exception $message)
    {
        file_put_contents(ERR_LOG, ' [' . date('Y-m-d H:i:s') . ']' . $message->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

define('TYPE_SENT', 0);
define('TYPE_CHUNCK', 1);

define('CHUNCK_SIZE', 16384);
define('RECEIVE_SIZE', 32768);

define('WORKING_DIR', '/tmp/test/');
define('JOB_LIST', WORKING_DIR . 'jobs');
define('CHUNCKS_DIR', WORKING_DIR . 'chuncks/');
define('ERR_LOG', WORKING_DIR . 'error.log');

define('RECEIVED_FLAG', 1);

define('ADDRESS', '127.0.0.1');
define('PORT', '12345');

$receiver = new FileReceiver(ADDRESS, PORT);
$receiver->setOutput(new Logger());
$receiver->start();

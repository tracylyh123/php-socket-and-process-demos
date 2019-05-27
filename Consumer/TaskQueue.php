<?php
namespace Demos\Consumer;

/**
 * Class TaskQueue
 * @package Demos\Consumer
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php TaskQueue.php {ip} {port}
 * client usage: telnet {ip} {port}
 */
abstract class Task
{
    protected $generator;

    public function run()
    {
        if ($this->generator instanceof \Generator) {
            return $this->generator->next();
        }
        $this->generator = $this->execute();
        return $this->generator->current();
    }

    public function isFinished()
    {
        if ($this->generator instanceof \Generator) {
            return !$this->generator->valid();
        }
        return false;
    }

    abstract protected function execute() : \Generator;
}

class EchoA extends Task
{
    protected function execute() : \Generator
    {
        for ($i = 0; $i < 3; $i++) {
            echo 'A' . PHP_EOL;
            sleep(3);
            yield;
        }
    }
}

class EchoB extends Task
{
    protected function execute() : \Generator
    {
        for ($i = 0; $i < 3; $i++) {
            echo 'B' . PHP_EOL;
            sleep(1);
            yield;
        }
    }
}

class Scheduler
{
    protected $taskQueue;

    public function __construct()
    {
        $this->taskQueue = new \SplQueue();
    }

    public function addTask(Task $task)
    {
        $this->taskQueue->enqueue($task);
    }

    public function run()
    {
        /**
         * @var $task Task
         */
        while (!$this->taskQueue->isEmpty()) {
            $task = $this->taskQueue->dequeue();
            try {
                $task->run();
            } catch (\Exception $e) {
                echo $e->getMessage() . PHP_EOL;
                continue;
            }
            if (!$task->isFinished()) {
                $this->taskQueue->enqueue($task);
            }
        }
    }
}

class TaskQueue
{
    const BATCH_NUM = 5;

    const PROCESS_NUM = 5;

    const MAX_LENGTH = 4096;

    const FIFO_PATH = '/tmp/task-queue';

    const FIFO_MODE = 0644;

    protected $address;

    protected $port;

    public function __construct($address, $port)
    {
        $this->address = $address;
        $this->port = $port;
    }

    protected function createHandler()
    {
        $pid = pcntl_fork();
        if ($pid < 0) {
            echo 'pcntl_fork() failed' . PHP_EOL;
            return false;
        }
        if ($pid == 0) {
            while (true) {
                $tasks = [];
                if (!file_exists(self::FIFO_PATH)) {
                    echo 'fifo does not exist' . PHP_EOL;
                    exit(1);
                }
                $handle = fopen(self::FIFO_PATH, 'r');
                if (false === $handle) {
                    echo 'fopen() failed' . PHP_EOL;
                    exit(1);
                }
                for ($i = 0; $i < self::BATCH_NUM; $i++) {
                    $encoded = fread($handle, self::MAX_LENGTH);
                    if (false === $encoded) {
                        echo 'fread() failed';
                        continue;
                    }
                    $encoded = trim($encoded);
                    if (empty($encoded)) {
                        continue;
                    }
                    $decoded = base64_decode($encoded);
                    if (false === $decoded) {
                        echo 'base64_decode() failed';
                        continue;
                    }
                    $task = @unserialize($decoded);
                    if ($task instanceof Task) {
                        $tasks[] = $task;
                    } else {
                        echo 'unserialize() failed';
                    }
                }
                fclose($handle);
                $scheduler = new Scheduler();
                /**
                 * @var $task Task
                 */
                foreach ($tasks as $task) {
                    $scheduler->addTask($task);
                }
                $scheduler->run();
            }
        }
        return true;
    }

    protected function dispatch()
    {
        $pid = pcntl_fork();
        if ($pid < 0) {
            die('pcntl_fork() failed');
        }
        if ($pid == 0) {
            $processes = 0;
            for ($i = 0; $i < self::PROCESS_NUM; $i++) {
                if (false !== $this->createHandler()) {
                    $processes++;
                }
            }
            while (true) {
                pcntl_wait($status);
                $processes--;
                $code = pcntl_wexitstatus($status);
                if (1 != $code && false !== $this->createHandler()) {
                    $processes++;
                }
            }
        }
    }

    public function start()
    {
        if (!file_exists(self::FIFO_PATH)) {
            if (false === posix_mkfifo(self::FIFO_PATH, self::FIFO_MODE)) {
                die('posix_mkfifo() failed');
            }
        }
        $this->dispatch();
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $socket) {
            die('socket_create() failed, reason: ' . socket_strerror(socket_last_error()));
        }
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (false === socket_bind($socket, $this->address, $this->port)) {
            die('socket_bind() failed, reason: ' . socket_strerror(socket_last_error($socket)));
        }
        if (false === socket_listen($socket)) {
            die('socket_listen() failed, reason: ' . socket_strerror(socket_last_error($socket)));
        }
        $rs = [$socket];
        while (true) {
            $read = $rs;
            if (false === socket_select($read, $write, $except, null)) {
                echo 'socket_select() failed, reason: ' . socket_strerror(socket_last_error()) . PHP_EOL;
                continue;
            }
            foreach ($read as $item) {
                if ($item === $socket) {
                    $accpected = socket_accept($socket);
                    if (false === $accpected) {
                        echo 'socket_accept() failed, reason: ' . socket_strerror(socket_last_error($socket)) . PHP_EOL;
                    } else {
                        $rs[] = $accpected;
                    }
                    continue;
                }
                $data = @socket_read($item, self::MAX_LENGTH, PHP_NORMAL_READ);
                $index = array_search($item, $rs);
                if (false !== $index) {
                    unset($rs[$index]);
                }
                if (false === $data) {
                    echo 'socket_read() failed, reason: ' . socket_strerror(socket_last_error($item)) . PHP_EOL;
                    socket_close($item);
                    continue;
                }
                socket_close($item);
                $handle = fopen(self::FIFO_PATH, 'w');
                if (false === $handle) {
                    echo 'fopen() failed' . PHP_EOL;
                    continue;
                }
                if (false === fwrite($handle, $data)) {
                    echo 'fwrite() failed';
                }
                fclose($handle);
            }
        }
    }
}

$task1 = new EchoA();
$task2 = new EchoB();
// input the string in "telnet" program
echo base64_encode(serialize($task1)) . PHP_EOL;
// input the string in "telnet" program
echo base64_encode(serialize($task2)) . PHP_EOL;

$address = $argv[2] ?? '0.0.0.0';
$port = $argv[3] ?? 12345;
$queue = new TaskQueue($address, $port);
$queue->start();
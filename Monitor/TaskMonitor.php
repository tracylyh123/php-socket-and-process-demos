<?php
namespace Demos\Monitor;
/**
 * @package Demos\Monitor
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start: php TaskMonitor.php
 * see the result: tail -f /tmp/task-log
 */
class Task
{
    protected $taskId;

    public function __construct()
    {
        $this->taskId = random_int(1, 65535);
    }

    public function getTaskId()
    {
        return $this->taskId;
    }

    public function execute()
    {
        $i = 10;
        while ($i--) {
            sleep(1);
            pcntl_signal_dispatch();
        }
    }
}

class TaskQueue
{
    protected $tasks = [];

    protected $monitor;

    public function __construct(TaskMonitor $monitor)
    {
        $this->monitor = $monitor;
    }

    public function addTask(Task $task)
    {
        $this->tasks[] = $task;
        return $this;
    }

    public function run()
    {
        /**
         * @var $task Task
         */
        foreach ($this->tasks as $task) {
            $taskId = $task->getTaskId();
            try {
                $this->monitor->registerTask($taskId, TaskMonitor::ACT_ADD);
                Logger::info('Executing task: ' . $taskId);
                $task->execute();
                Logger::info('Task: ' . $taskId . ' already completed');
            } catch (TaskCanceledException $e) {
                Logger::info('Task: ' . $taskId . ' already canceled');
            } catch (\Exception $e) {
                Logger::info($e->getMessage());
            } finally {
                $this->monitor->registerTask($taskId, TaskMonitor::ACT_REMOVE);
            }
        }
    }
}

class TaskCanceledException extends \Exception
{

}

class Logger
{
    const LOG_FILE = '/tmp/task-log';

    public static function info(string $message): void
    {
        file_put_contents(self::LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}

class TaskMonitor
{
    const TASK_POOL_FILE = '/tmp/task-pool';

    const ACT_ADD = 0;
    const ACT_REMOVE = 1;

    const MAX_TASK = 65535;

    public function installSignalHandler()
    {
        pcntl_signal(SIGTERM, function () {
            throw new TaskCanceledException('This task should be canceled');
        });
    }

    public function cancelTask(int $taskId): bool
    {
        $handle = fopen(self::TASK_POOL_FILE, 'r');
        if (false === $handle) {
            echo 'fopen() failed';
            return false;
        }
        if (flock($handle, LOCK_SH)) {
            $filesize = filesize(self::TASK_POOL_FILE);
            if ($filesize) {
                $contents = fread($handle, $filesize);
                if (false === $contents) {
                    goto ERROR;
                }
                $tasks = @unserialize($contents);
                if (false === $tasks) {
                    echo 'unserialize() failed' . PHP_EOL;
                    goto ERROR;
                }
                $tasks = array_slice($tasks, -self::MAX_TASK, null, true);
                if (!isset($tasks[$taskId])) {
                    echo 'Task ' . $taskId . ' already not running' . PHP_EOL;
                    goto ERROR;
                }
                if (false === posix_kill($tasks[$taskId], SIGTERM)) {
                    $errorId = posix_get_last_error();
                    if ($errorId) {
                        echo posix_strerror($errorId) . PHP_EOL;
                    } else {
                        echo 'Unknown error' . PHP_EOL;
                    }
                    goto ERROR;
                }
            }
            if (false === flock($handle, LOCK_UN)) {
                echo 'flock() failed' . PHP_EOL;
            }
            fclose($handle);
            return true;
        } else {
            echo 'flock() failed' . PHP_EOL;
            fclose($handle);
            return false;
        }
        ERROR:
        if (false === flock($handle, LOCK_UN)) {
            echo 'flock() failed' . PHP_EOL;
        }
        fclose($handle);
        return false;
    }

    public function registerTask(int $taskId, int $action): bool
    {
        $handle = fopen(self::TASK_POOL_FILE, 'c+');
        if (false === $handle) {
            echo 'fopen() failed';
            return false;
        }
        if (flock($handle, LOCK_EX)) {
            $filesize = filesize(self::TASK_POOL_FILE);
            clearstatcache();
            if ($filesize) {
                $contents = fread($handle, $filesize);
                if (false === $contents) {
                    echo 'fread() failed' . PHP_EOL;
                    goto ERROR;
                }
                $tasks = @unserialize($contents);
                if (false === $tasks) {
                    echo 'unserialize() failed' . PHP_EOL;
                    goto ERROR;
                } else {
                    $tasks = array_slice($tasks, -self::MAX_TASK, null, true);
                }
            } else {
                $tasks = [];
            }
            if ($action === self::ACT_ADD) {
                $tasks[$taskId] = posix_getpid();
            } elseif ($action === self::ACT_REMOVE) {
                if (isset($tasks[$taskId])) {
                    unset($tasks[$taskId]);
                }
            }
            if (false === ftruncate($handle, 0)) {
                echo 'ftruncate() failed' . PHP_EOL;
                goto ERROR;
            }
            if (false === rewind($handle)) {
                echo 'rewind() failed' . PHP_EOL;
                goto ERROR;
            }
            if (false === fwrite($handle, serialize($tasks))) {
                echo 'fwrite() failed' . PHP_EOL;
                goto ERROR;
            }
            if (false === fflush($handle)) {
                echo 'fflush() failed' . PHP_EOL;
                goto ERROR;
            }
            if (false === flock($handle, LOCK_UN)) {
                echo 'flock() failed' . PHP_EOL;
            }
            fclose($handle);
            return true;
        } else {
            echo 'flock() failed' . PHP_EOL;
            fclose($handle);
            return false;
        }
        ERROR:
        if (false === flock($handle, LOCK_UN)) {
            echo 'flock() failed' . PHP_EOL;
        }
        fclose($handle);
        return false;
    }
}

$pid = pcntl_fork();
$monitor = new TaskMonitor();
if ($pid < 0) {
    die('pcntl_fork() failed');
}
if ($pid == 0) {
    $monitor->installSignalHandler();
    $queue = new TaskQueue($monitor);
    $queue->addTask(new Task());
    $queue->addTask(new Task());
    $queue->addTask(new Task());
    $queue->addTask(new Task());
    $queue->run();
    exit(0);
} else {
    echo 'Input the task id which you want to cancel' . PHP_EOL;
    $rs = [STDIN];
    while (true) {
        $read = $rs;
        $modified = stream_select($read, $write, $except, 1);
        if (false === $modified) {
            echo 'stream_select() failed' . PHP_EOL;
        } elseif ($modified > 0) {
            $taskId = stream_get_line(STDIN, 1024, PHP_EOL);
            if (false === $taskId) {
                echo 'stream_get_line() failed' . PHP_EOL;
                continue;
            }
            if (empty($taskId) || !is_numeric($taskId)) {
                echo 'Invalid task id' . PHP_EOL;
                continue;
            }
            if ($monitor->cancelTask($taskId)) {
                echo 'Cancel task ' . $taskId . ' successfully' . PHP_EOL;
            } else {
                echo 'Cancel task ' . $taskId . ' failed' . PHP_EOL;
            }
        }
        $cid = pcntl_waitpid($pid, $status, WNOHANG);
        if (-1 === $cid) {
            echo 'pcntl_waitpid() failed' . PHP_EOL;
        } elseif ($cid > 0) {
            echo 'All tasks already been executed' . PHP_EOL;
            break;
        }
    }
}

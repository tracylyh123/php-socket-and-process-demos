<?php
namespace Demos\Monitor;
/**
 * @package Demos\Monitor
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start: php ProcessMonitor.php
 */
abstract class Process
{
    protected $processId;

    protected $isRunning = false;

    protected $monitor;

    protected $timeout;

    protected $registerTime;

    public function __construct(ProcessMonitor $monitor)
    {
        $this->monitor = $monitor;
    }

    public function isRunning()
    {
        return $this->isRunning;
    }

    public function getProcessId()
    {
        return $this->processId;
    }

    public function suspend()
    {
       if (false === posix_kill($this->processId, SIGTSTP)) {
           echo 'posix_kill() failed, reason: ' . posix_strerror(posix_get_last_error()) . PHP_EOL;
       }
    }

    public function resume()
    {
        if (false === posix_kill($this->processId, SIGCONT)) {
            echo 'posix_kill() failed, reason: ' . posix_strerror(posix_get_last_error()) . PHP_EOL;
        }
    }

    public function setTimeout(int $seconds)
    {
        if ($this->isRunning()) {
            echo 'No effect, timeout must be set before the process started' . PHP_EOL;
        } else {
            $this->timeout = $seconds;
        }
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    public function isTimeout()
    {
        if (empty($this->timeout)) {
            return false;
        }
        $spent = time() - $this->getRegisterTime();
        return $spent > $this->timeout;
    }

    public function setRegisterTime($registerTime)
    {
        $this->registerTime = $registerTime;
    }

    public function getRegisterTime()
    {
        return $this->registerTime;
    }

    public function terminate()
    {
        if ($this->isRunning) {
            if (false === pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD])) {
                echo 'pcntl_sigprocmask() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
            }
            if (false === posix_kill($this->processId, SIGINT)) {
                echo 'posix_kill() failed, reason: ' . posix_strerror(posix_get_last_error()) . PHP_EOL;
            }
            if (-1 === pcntl_waitpid($this->processId, $status)) {
                echo 'pcntl_waitpid() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
            }
            if (1 === pcntl_wexitstatus($status)) {
                echo 'child process exited with exception, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
            }
            $oldset = [];
            if (false === pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD], $oldset)) {
                echo 'pcntl_sigprocmask() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
            }
        }
        $this->monitor->unregisterProcess($this->processId);
        $this->isRunning = false;
        $this->processId = null;
    }

    public function run()
    {
        $pid = pcntl_fork();
        if ($pid < 0) {
            die('pcntl_fork() failed');
        }
        $this->isRunning = true;
        if ($pid === 0) {
            $result = pcntl_signal(SIGTSTP, function () {
                $info = [];
                $sid = pcntl_sigwaitinfo([SIGCONT], $info);
                if ($sid < 1) {
                    echo 'pcntl_sigwaitinfo() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
                }
            });
            if (false === $result) {
                echo 'pcntl_signal() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
                exit(1);
            }
            $this->processId = posix_getpid();
            $exitCode = 0;
            try {
                $this->execute();
            } catch (\Exception $e) {
                $exitCode = 1;
            } finally {
                $this->isRunning = false;
                $this->processId = null;
            }
            exit($exitCode);
        }
        $this->processId = $pid;
        $this->monitor->registerProcess($this);
    }

    abstract protected function execute();
}

class PIDEchoProcess extends Process
{
    protected function execute()
    {
        while (true) {
            echo $this->processId . PHP_EOL;
            sleep(2);
        }
    }
}

class ProcessMonitor
{
    protected $processes = [];

    public function __construct()
    {
        pcntl_async_signals(true);
    }

    public function registerProcess(Process $process)
    {
        $process->setRegisterTime(time());
        $this->processes[$process->getProcessId()] = $process;
    }

    public function unregisterProcess($cid)
    {
        unset($this->processes[$cid]);
    }

    public function monitor()
    {
        pcntl_alarm(1);
        $result = pcntl_signal(SIGALRM, function () {
            /**
             * @var $process Process
             */
            foreach ($this->processes as $process) {
                if (!$process->isRunning()) {
                    continue;
                }
                if ($process->isTimeout()) {
                    $process->terminate();
                }
            }
            pcntl_alarm(1);
        });
        if (false === $result) {
            die('pcntl_signal() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()));
        }
        $result = pcntl_signal(SIGCHLD, function () {
            $cid = pcntl_wait($status, WNOHANG);
            if ($cid > 0) {
                $this->unregisterProcess($cid);
            }
            if (-1 === $cid) {
                echo 'pcntl_wait() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
            }
        });
        if (false === $result) {
            die('pcntl_signal() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()));
        }
        while (true) {
            sleep(10);
            if (0 === count($this->processes)) {
                break;
            }
        }
        echo 'No process still running' . PHP_EOL;
    }
}

$monitor = new ProcessMonitor();

$process3 = new PIDEchoProcess($monitor);
$process3->run();
sleep(5);
$process3->suspend();
sleep(15);
$process3->resume();
sleep(5);
$process3->terminate();

$process1 = new PIDEchoProcess($monitor);
$process1->setTimeout(20);
$process1->run();
$process2 = new PIDEchoProcess($monitor);
$process2->setTimeout(10);
$process2->run();

$monitor->monitor();

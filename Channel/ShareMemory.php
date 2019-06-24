<?php
namespace Demos\Channel;
/**
 * Class Client
 * @package Demos\Channel
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start client: php Client.php
 */
interface ExStorage extends \ArrayAccess
{
    function acquire();

    function release();

    function destory();
}

class ShareMemory implements ExStorage
{
    protected $semId;

    protected $shmId;

    public function __construct($semkey, $shmkey)
    {
        $this->semId = sem_get($semkey);
        if (false === $this->shmId) {
            throw new ShareMemoryException('sem_get() failed');
        }
        $this->shmId = shm_attach($shmkey);
    }

    public function offsetExists($offset)
    {
        $has = shm_has_var($this->shmId, crc32($offset));
        return $has;
    }

    public function offsetGet($offset)
    {
        $key = crc32($offset);
        $has = shm_has_var($this->shmId, $key);
        if ($has) {
            $value = shm_get_var($this->shmId, $key);
        } else {
            $value = null;
        }
        return $value;
    }

    public function offsetSet($offset, $value)
    {
        if (false === shm_put_var($this->shmId, crc32($offset), $value)) {
            throw new ShareMemoryException('shm_put_var() failed');
        }
    }

    public function offsetUnset($offset)
    {
        $key = crc32($offset);
        $has = shm_has_var($this->shmId, $key);
        if ($has) {
            if (false === shm_remove_var($this->shmId, $key)) {
                throw new ShareMemoryException('shm_remove_var() failed');
            }
        }
    }

    public function acquire()
    {
        if (false === sem_acquire($this->semId)) {
            throw new ShareMemoryException('sem_acquire() failed');
        }
    }

    public function release()
    {
        if (false === sem_release($this->semId)) {
            throw new ShareMemoryException('sem_release() failed');
        }
    }

    public function destory()
    {
        if (false === shm_remove($this->shmId)) {
            throw new ShareMemoryException('shm_remove() failed');
        }
        if (false === sem_remove($this->semId)) {
            throw new ShareMemoryException('sem_remove() failed');
        }
    }
}

class ShareMemoryException extends \Exception
{

}

class Message
{
    protected $messageId;

    protected $content;

    protected $refcount = 0;

    public function __construct($mssageId, string $content)
    {
        $this->messageId = $mssageId;
        $this->content = $content;
    }

    public function addRefCount()
    {
        $this->refcount++;
    }

    public function subRefCount()
    {
        $this->refcount--;
    }

    public function outputContent()
    {
        echo $this->content . PHP_EOL;
    }

    public function hasRef(): bool
    {
        return $this->refcount > 0;
    }
}

class MessagesManager
{
    protected $storage;

    protected $pid;

    public function __construct($pid, ExStorage $storage)
    {
        $this->storage = $storage;
        $this->pid = $pid;
    }

    public function notify(string $content): void
    {
        $this->storage->acquire();
        try {
            $msgId = uniqid('message_');
            $this->storage[$msgId] = new Message($msgId, $content);
            $pidtable = $this->storage['registered_pid_table'] ?? [];
            foreach ($pidtable as $pid) {
                if ($pid != $this->pid) {
                    if (false === posix_kill($pid, SIGCONT)) {
                        echo 'posix_kill() failed, reason: ' . posix_strerror(posix_get_last_error()) . PHP_EOL;
                        continue;
                    }
                    $unread = $this->storage['unread_' . $pid] ?? [];
                    $unread[] = $msgId;
                    $this->storage['unread_' . $pid] = $unread;
                    $message = $this->storage[$msgId];
                    $message->addRefCount();
                    $this->storage[$msgId] = $message;
                }
            }
        } catch (ShareMemoryException $e) {
            echo $e->getMessage() . PHP_EOL;
        } finally {
            $this->storage->release();
        }
    }

    public function receive(): bool
    {
        $this->storage->acquire();
        $result = true;
        try {
            $unread = $this->storage['unread_' . $this->pid] ?? [];
            foreach ($unread as $key => $msgId) {
                $message = $this->storage[$msgId];
                $message->outputContent();
                $message->subRefCount();
                if (!$message->hasRef()) {
                    unset($this->storage[$msgId]);
                }
                unset($unread[$key]);
            }
            $this->storage['unread_' . $this->pid] = $unread;
        } catch (ShareMemoryException $e) {
            $result = false;
        } finally {
            $this->storage->release();
        }
        return $result;
    }

    public function register(): bool
    {
        $this->storage->acquire();
        $result = true;
        try {
            $pidtable = $this->storage['registered_pid_table'] ?? [];
            if (!in_array($this->pid, $pidtable)) {
                $pidtable[] = $this->pid;
                $this->storage['registered_pid_table'] = $pidtable;
            }
        } catch (ShareMemoryException $e) {
            $result = false;
        } finally {
            $this->storage->release();
        }
        return $result;
    }

    public function unregister(): bool
    {
        $this->storage->acquire();
        $result = true;
        try {
            $pidtable = $this->storage['registered_pid_table'] ?? [];
            if (in_array($this->pid, $pidtable)) {
                if (false !== ($index = array_search($this->pid, $pidtable))) {
                    unset($pidtable[$index]);
                    $this->storage['registered_pid_table'] = $pidtable;
                }
                $unread = $this->storage['unread_' . $this->pid] ?? [];
                foreach ($unread as $msgId) {
                    $message = $this->storage[$msgId];
                    $message->subRefCount();
                    if (!$message->hasRef()) {
                        unset($this->storage[$msgId]);
                    }
                }
                unset($this->storage['unread_' . $this->pid]);
            }
        } catch (ShareMemoryException $e) {
            $result = false;
        } finally {
            $this->storage->release();
        }
        return $result;
    }
}

final class Client
{
    const QUIT_FLAG = '/quit';

    protected $storage;

    public function __construct(ExStorage $storage)
    {
        $this->storage = $storage;
    }

    public function start()
    {
        pcntl_async_signals(true);
        $pid = pcntl_fork();
        if ($pid > 0) {
            $result = pcntl_signal(SIGCHLD, function () use ($pid) {
                if (-1 === pcntl_waitpid($pid, $status)) {
                    echo 'pcntl_waitpid() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
                }
                exit;
            });
            if (false === $result) {
                die('pcntl_signal() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL);
            }
            $read = [STDIN];
            $manager = new MessagesManager($pid, $this->storage);
            if (false === $manager->register()) {
                die('cannot register message manager' . PHP_EOL);
            }
            while (true) {
                $modified = @stream_select($read, $write, $except, null);
                if ($modified === false) {
                    echo 'stream_select() failed' . PHP_EOL;
                    continue;
                } elseif ($modified > 0) {
                    $line = stream_get_line(STDIN, 256, PHP_EOL);
                    if (false === $line) {
                        echo 'stream_get_line() failed' . PHP_EOL;
                        continue;
                    } elseif ($line === self::QUIT_FLAG) {
                        if (false === $manager->unregister()) {
                            echo 'cannot unregister message manager' . PHP_EOL;
                        }
                        if (false === posix_kill($pid, SIGTERM)) {
                            echo 'posix_kill() failed, reason: ' . posix_strerror(posix_get_last_error()) . PHP_EOL;
                        }
                        break;
                    }
                    $manager->notify($line);
                }
            }
        } elseif ($pid == 0) {
            while (true) {
                if (false === pcntl_sigprocmask(SIG_BLOCK, [SIGCONT])) {
                    die('pcntl_sigprocmask() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL);
                }
                $sid = pcntl_sigwaitinfo([SIGCONT]);
                if ($sid < 1) {
                    echo 'pcntl_sigwaitinfo() failed, reason: ' . pcntl_strerror(pcntl_get_last_error()) . PHP_EOL;
                    continue;
                }
                $manager = new MessagesManager(posix_getpid(), $this->storage);
                if (false === $manager->receive()) {
                    echo 'cannot receive message from message manager' . PHP_EOL;
                }
            }
        } else {
            die('pcntl_fork() failed' . PHP_EOL);
        }
    }
}

$tmpsem = '/tmp/sem';
$tmpshm = '/tmp/shm';
touch($tmpsem);
touch($tmpshm);
$semkey = ftok($tmpsem, 'e');
if (-1 === $semkey) {
    die('ftok() failed');
}
$shmkey = ftok($tmpshm, 'h');
if (-1 === $semkey) {
    die('ftok() failed');
}
$sm = new ShareMemory($semkey, $shmkey);
//$sm->destory();exit;
$s = new Client($sm);
$s->start();

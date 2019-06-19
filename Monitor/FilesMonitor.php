<?php
namespace Demos\Monitor;

class FilesMonitor
{
    const POLLING_TIME = 1;

    protected $history = [];

    protected $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function getWatchedFiles(string $dir): array
    {
        $dir = rtrim($dir, '/');
        if (!file_exists($dir)) {
            die('dir ' . $dir . ' does not exist');
        }
        if (!is_dir($dir)) {
            die('dir ' . $dir . ' was not a valid dir');
        }
        $queue = [$dir];
        $watched = [];
        while (true) {
            if (empty($queue)) {
                break;
            }
            $first = array_shift($queue);
            $files = glob($first . '/*');
            foreach ($files as $file) {
                if (is_dir($file)) {
                    array_push($queue, $file);
                } elseif (is_file($file)) {
                    if (is_readable($file)) {
                        $watched[] = WatchedFiles::getInstance($file);
                    }
                }
            }
        }
        return $watched;
    }

    protected function recordHistory(WatchedFiles $file): void
    {
        $this->history[$file->getFilePath()][] = $file;
    }

    public function monitor(string $dir): void
    {
        $watched = $this->getWatchedFiles($dir);
        while (true) {
            foreach ($watched as $file) {
                if (!isset($this->history[$file->getFilePath()])) {
                    $this->recordHistory($file);
                }
                if ($file->isChanged()) {
                    $this->recordHistory($file->resetInfo());
                    $this->output->output($file);
                }
            }
            sleep(self::POLLING_TIME);
        }
    }
}

interface OutputInterface
{
    function output(string $content): void;
}

class Console implements OutputInterface
{
    public function output(string $content): void
    {
        echo $content . PHP_EOL;
    }
}

final class WatchedFiles
{
    private $filepath;

    private $lastChecksum;

    private $lastModified;

    private static $instances = [];

    private function __construct(string $filepath)
    {
        $this->filepath = $filepath;
        $this->lastChecksum = md5_file($filepath);
        $this->lastModified = filemtime($filepath);
    }

    public function isChanged(): bool
    {
        clearstatcache();
        if (!file_exists($this->filepath)) {
            return false;
        }
        if ($this->lastModified === filemtime($this->filepath)) {
            return false;
        }
        if (md5_file($this->filepath) === $this->lastChecksum) {
            return false;
        }
        return true;
    }

    public function getFilePath(): string
    {
        return $this->filepath;
    }

    public function getChecksum(): string
    {
        return md5_file($this->filepath);
    }

    public function resetInfo(): WatchedFiles
    {
        $this->lastChecksum = md5_file($this->filepath);
        $this->lastModified = filemtime($this->filepath);
        return $this;
    }

    public function __toString()
    {
        return sprintf('%s - %s - %s', $this->filepath , $this->lastChecksum, date('Y-m-d H:i:s', $this->lastModified));
    }

    public static function getInstance(string $filepath): WatchedFiles
    {
        if (!isset(self::$instances[$filepath])) {
            self::$instances[$filepath] = new  self($filepath);
        }
        return self::$instances[$filepath];
    }
}

$moitor = new FilesMonitor(new Console());
$moitor->monitor('/tmp');

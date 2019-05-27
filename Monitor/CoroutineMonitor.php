<?php
namespace Demos\Monitor;
/**
 * @package Demos\Monitor
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start: php CoroutineMonitor.php
 */
class CoroutineMonitor
{
    protected $coroutines;

    public function __construct()
    {
        $this->coroutines = new \SplQueue();
    }

    public function register(Coroutine $coroutine)
    {
        if ($coroutine->isHigPriority()) {
            $this->coroutines->unshift($coroutine);
        } else {
            $this->coroutines->push($coroutine);
        }
        $coroutine->registered();
    }

    public function start()
    {
        /**
         * @var $coroutine Coroutine
         */
        while (!$this->coroutines->isEmpty()) {
            $coroutine = $this->coroutines->shift();
            try {
                $coroutine->run();
            } catch (\Exception $e) {
                echo $e->getMessage() . PHP_EOL;
                continue;
            }
            if ($coroutine->isFinished()) {
                continue;
            }
            if ($coroutine->isPerformed()) {
                $this->coroutines->push($coroutine);
            } else {
                $this->coroutines->unshift($coroutine);
            }
        }
    }
}

abstract class Coroutine
{
    const PRIORITY_HIGH = 0;
    const PRIORITY_NORMAL = 1;

    protected $monitor;

    protected $priority = self::PRIORITY_NORMAL;

    protected $registered = false;

    protected $isRunning = false;

    protected $generator;

    protected $progress = 0;

    public function registered()
    {
        $this->registered = true;
    }

    public function highPriority()
    {
        if (false === $this->isRunning) {
            $this->priority = self::PRIORITY_HIGH;
        }
    }

    public function isHigPriority()
    {
        return $this->priority === self::PRIORITY_HIGH;
    }

    public function getThreshold()
    {
        $map = [
            self::PRIORITY_HIGH => 10,
            self::PRIORITY_NORMAL => 1,
        ];
        return $map[$this->priority] ?? 1;
    }

    public function isPerformed()
    {
        return $this->progress % $this->getThreshold() === 0;
    }

    public function run()
    {
        $this->progress++;
        if ($this->generator instanceof \Generator) {
            return $this->generator->next();
        }
        $this->generator = $this->execute();
        $this->isRunning = true;
        return $this->generator->current();
    }

    public function isFinished()
    {
        if ($this->generator instanceof \Generator) {
            return !$this->generator->valid();
        }
        return false;
    }

    abstract public function execute(): \Generator;
}

class TestCoroutine extends Coroutine
{
    protected $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function execute(): \Generator
    {
        $i = 30;
        while (--$i) {
            echo $this->name . PHP_EOL;
            sleep(1);
            yield;
        }
    }
}

$monitor = new CoroutineMonitor();

$coroutine1 = new TestCoroutine('Coroutine1');
$monitor->register($coroutine1);

$coroutine2 = new TestCoroutine('Coroutine2');
$coroutine2->highPriority();
$monitor->register($coroutine2);

$coroutine3 = new TestCoroutine('Coroutine3');
$monitor->register($coroutine3);

$monitor->start();

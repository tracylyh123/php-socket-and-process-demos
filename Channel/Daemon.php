<?php
namespace Demos\Channel;

/**
 * Class Daemon
 * @package Demos\Channel
 * @author Tracy li <tracylyh123@gmail.com>
 *
 * start server: php Daemon.php {server number}
 * client usage: wscat -c ws://{ip}:{port}
 */
class Daemon
{
    protected $address = '0.0.0.0';

    protected $ports = [];

    protected $childs = [];

    public function __construct(int $serverNo)
    {
        $rand = random_int(1024, 65535 - $serverNo);
        for ($i = 0; $i < $serverNo; $i++) {
            $this->ports[] = $rand + $i;
        }
    }

    protected function daemon()
    {
        $pid = pcntl_fork();
        if ($pid !== 0) {
            exit;
        }

        if (posix_setsid() < 0) {
            die('posix_setsid() failed');
        }

        pcntl_signal(SIGINT, SIG_IGN);
        pcntl_signal(SIGHUP, SIG_IGN);

        $pid = pcntl_fork();
        if ($pid !== 0) {
            exit;
        }

        umask(0);
        chdir('/');

        fclose(STDERR);
        fclose(STDIN);
        fclose(STDOUT);
    }

    public function start()
    {
        $this->daemon();
        pcntl_signal(SIGTERM, function () {
            foreach (array_keys($this->childs) as $child) {
                posix_kill($child, SIGTERM);
            }
        });
        foreach ($this->ports as $port) {
            $pid = pcntl_fork();
            if ($pid < 0) {
                die('pcntl_fork() failed');
            } elseif ($pid == 0) {
                pcntl_signal(SIGTERM, SIG_DFL);
                $server = new WsServer($this->address, $port, WsServer::OUTPUT_NULL);
                $server->start();
                exit;
            }
            $this->childs[$pid] = $port;
        }
        do {
            pcntl_signal_dispatch();
            $cid = pcntl_wait($status, WNOHANG);
            if ($cid > 0 && isset($this->childs[$cid])) {
                unset($this->childs[$cid]);
            }
        } while (!empty($this->childs));
    }
}

include 'WsServer.php';
$serverNo = $argv[1] ?? 2;
$servers = new Daemon($serverNo);
$servers->start();

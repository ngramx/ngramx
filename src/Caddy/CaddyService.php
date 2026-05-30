<?php

declare(strict_types=1);

namespace Ngramx\Caddy;

use Symfony\Component\Process\Process;

/**
 * Stops standalone Caddy processes that hold common HTTP(S) ports.
 * Laravel Herd uses nginx, not Caddy; a separate Caddy dev stack is a typical source of 80/443 conflicts.
 */
class CaddyService
{
    /**
     * Send SIGTERM to Caddy processes listening on any of the given TCP ports.
     *
     * @param list<int> $ports
     *
     * @return int Number of distinct Caddy processes signalled
     */
    public function stopListenersOnPorts(array $ports): int
    {
        $which = new Process(['which', 'lsof']);
        $which->run();
        if (!$which->isSuccessful()) {
            return 0;
        }

        $pidSet = [];
        foreach ($ports as $port) {
            foreach ($this->listenerPidsForTcpPort((int) $port) as $pid) {
                $pidSet[$pid] = true;
            }
        }

        $stopped = 0;
        foreach (array_keys($pidSet) as $pid) {
            if ($this->isCaddyProcess($pid) && $this->signalTerm($pid)) {
                ++$stopped;
            }
        }

        return $stopped;
    }

    /**
     * @return list<int>
     */
    private function listenerPidsForTcpPort(int $port): array
    {
        $process = new Process(['lsof', '-nP', '-iTCP:'.$port, '-sTCP:LISTEN', '-t']);
        $process->run();

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        $pids = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pid = (int) $line;
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    private function isCaddyProcess(int $pid): bool
    {
        $ps = new Process(['ps', '-p', (string) $pid, '-o', 'comm=']);
        $ps->run();
        if (!$ps->isSuccessful()) {
            return false;
        }

        $comm = trim($ps->getOutput());
        if ($comm === '') {
            return false;
        }

        return $comm === 'caddy' || str_ends_with($comm, '/caddy');
    }

    private function signalTerm(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 15);
        }

        $kill = new Process(['kill', '-TERM', (string) $pid]);
        $kill->run();

        return $kill->isSuccessful();
    }
}

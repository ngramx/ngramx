<?php

declare(strict_types=1);

namespace Ngramx\Herd;

use Symfony\Component\Process\Process;

class HerdService
{
    public function isInstalled(): bool
    {
        $process = new Process(['which', 'herd']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Stop all Herd services (nginx, dnsmasq, PHP-FPM). Herd does not use Caddy.
     *
     * @throws \RuntimeException if herd stop fails
     */
    public function stop(): void
    {
        $process = new Process(['herd', 'stop']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'Failed to stop Herd: ' . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Start all Herd services.
     *
     * @throws \RuntimeException if herd start fails
     */
    public function start(): void
    {
        $process = new Process(['herd', 'start']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'Failed to start Herd: ' . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }
}

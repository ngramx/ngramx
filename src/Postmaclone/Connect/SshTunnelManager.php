<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

use Ngramx\Codabyte\ServerTarget;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * SSH local port forward via Codabyte to reach DO Managed databases.
 */
final class SshTunnelManager
{
    public function __construct(
        private readonly ServerTarget $target = new ServerTarget(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function buildTunnelArgs(int $localPort, string $remoteHost, int $remotePort): array
    {
        $args = [
            'ssh',
            '-N',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=3',
            '-L', sprintf('127.0.0.1:%d:%s:%d', $localPort, $remoteHost, $remotePort),
        ];

        if ($this->target->port !== null) {
            $args[] = '-p';
            $args[] = (string) $this->target->port;
        }

        $args[] = $this->target->sshDestination();

        return $args;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function probeReachability(): array
    {
        $result = $this->runBatchModeSshProbe();
        if ($result['ok']) {
            return [
                'ok' => true,
                'message' => 'SSH to ' . $this->target->sshDestination() . ' reachable',
            ];
        }

        $detail = $result['detail'];

        return [
            'ok' => false,
            'message' => 'Cannot reach ' . $this->target->sshDestination() . ' via SSH'
                . ($detail !== '' ? ': ' . $detail : ''),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function probeBatchMode(): array
    {
        $result = $this->runBatchModeSshProbe();
        if ($result['ok']) {
            return [
                'ok' => true,
                'message' => 'SSH key available without passphrase prompt (ssh-agent or unencrypted key)',
            ];
        }

        return [
            'ok' => false,
            'message' => 'SSH key requires a passphrase or is not loaded — run `ssh-add` or use `ngramx postmaclone connect --foreground`',
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function runBatchModeSshProbe(): array
    {
        $args = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5'];
        if ($this->target->port !== null) {
            $args[] = '-p';
            $args[] = (string) $this->target->port;
        }
        $args[] = $this->target->sshDestination();
        $args[] = 'true';

        $process = new Process($args);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            return ['ok' => true, 'detail' => ''];
        }

        return [
            'ok' => false,
            'detail' => trim($process->getErrorOutput() ?: $process->getOutput()),
        ];
    }

    public function startBackground(int $localPort, string $remoteHost, int $remotePort): int
    {
        $args = $this->buildTunnelArgs($localPort, $remoteHost, $remotePort);
        $args[] = '-f';

        $process = new Process($args);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            $detail = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new PostmacloneException(
                'Failed to start SSH tunnel'
                . ($detail !== '' ? ': ' . $detail : '')
                . '. Try `ssh-add` or `ngramx postmaclone connect --foreground`.'
            );
        }

        $pid = $this->findTunnelPid($localPort);
        if ($pid === null) {
            throw new PostmacloneException(
                'SSH tunnel started but could not determine its process id. '
                . 'Check `ss -lntp | grep :' . $localPort . '` and run disconnect if needed.'
            );
        }

        return $pid;
    }

    /**
     * @return list<string>
     */
    public function foregroundArgs(int $localPort, string $remoteHost, int $remotePort): array
    {
        return $this->buildTunnelArgs($localPort, $remoteHost, $remotePort);
    }

    public function isRunning(?int $pid): bool
    {
        if ($pid === null || $pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        $process = new Process(['kill', '-0', (string) $pid]);
        $process->run();

        return $process->isSuccessful();
    }

    public function stop(?int $pid): bool
    {
        if ($pid === null || $pid <= 0 || !$this->isRunning($pid)) {
            return false;
        }

        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);

            return true;
        }

        $process = new Process(['kill', (string) $pid]);
        $process->run();

        return $process->isSuccessful();
    }

    public function waitForLocalPort(int $localPort, int $timeoutSeconds = 10): bool
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $socket = @fsockopen('127.0.0.1', $localPort, $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);

                return true;
            }
            usleep(200_000);
        }

        return false;
    }

    public function allocateLocalPort(?int $preferred = null): int
    {
        if ($preferred !== null && $this->isPortFree($preferred)) {
            return $preferred;
        }

        for ($port = 15432; $port <= 15532; ++$port) {
            if ($this->isPortFree($port)) {
                return $port;
            }
        }

        throw new PostmacloneException('No free local port found for SSH tunnel (tried 15432–15532)');
    }

    private function isPortFree(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($socket !== false) {
            fclose($socket);

            return false;
        }

        return true;
    }

    private function findTunnelPid(int $localPort): ?int
    {
        $process = new Process(['ss', '-lntp']);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        $needle = ':' . $localPort;
        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (!str_contains($line, $needle)) {
                continue;
            }
            if (preg_match('/pid=(\d+)/', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}

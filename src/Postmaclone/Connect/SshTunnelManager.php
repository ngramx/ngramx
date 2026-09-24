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

    public function startBackground(int $localPort, string $remoteHost, int $remotePort): void
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
    }

    public function resolveTunnelPid(int $localPort): ?int
    {
        return $this->findTunnelPidFromSs($localPort) ?? $this->findTunnelPidFromLsof($localPort);
    }

    /**
     * Stop a background tunnel by pid and/or listeners on the local forward port.
     */
    public function stopTunnel(?int $pid, ?int $localPort): bool
    {
        if ($pid !== null && $pid > 0 && $this->isRunning($pid)) {
            $this->stop($pid);
        }

        if ($localPort !== null) {
            foreach ($this->findListenerPidsOnLocalPort($localPort) as $listenerPid) {
                if ($this->isRunning($listenerPid)) {
                    $this->stop($listenerPid);
                }
            }

            return $this->waitForLocalPortClosed($localPort);
        }

        if ($pid !== null && $pid > 0) {
            return $this->waitForProcessExit($pid);
        }

        return true;
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
            if ($this->isLocalPortListening($localPort)) {
                return true;
            }
            usleep(200_000);
        }

        return false;
    }

    public function waitForLocalPortClosed(int $localPort, int $timeoutSeconds = 5): bool
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (!$this->isLocalPortListening($localPort)) {
                return true;
            }
            usleep(200_000);
        }

        foreach ($this->findListenerPidsOnLocalPort($localPort) as $listenerPid) {
            if (!$this->isRunning($listenerPid)) {
                continue;
            }
            if (function_exists('posix_kill')) {
                posix_kill($listenerPid, SIGKILL);
            } else {
                (new Process(['kill', '-9', (string) $listenerPid]))->run();
            }
        }

        usleep(200_000);

        return !$this->isLocalPortListening($localPort);
    }

    public function waitForProcessExit(int $pid, int $timeoutSeconds = 5): bool
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (!$this->isRunning($pid)) {
                return true;
            }
            usleep(200_000);
        }

        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGKILL);
        } else {
            (new Process(['kill', '-9', (string) $pid]))->run();
        }

        usleep(200_000);

        return !$this->isRunning($pid);
    }

    public function isLocalPortListening(int $localPort): bool
    {
        $socket = @fsockopen('127.0.0.1', $localPort, $errno, $errstr, 1);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
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
        return !$this->isLocalPortListening($port);
    }

    /**
     * @return list<int>
     */
    private function findListenerPidsOnLocalPort(int $localPort): array
    {
        $pids = [];
        $single = $this->resolveTunnelPid($localPort);
        if ($single !== null) {
            $pids[] = $single;
        }

        $process = new Process(['lsof', '-nP', '-iTCP:' . $localPort, '-sTCP:LISTEN', '-t']);
        $process->run();
        if (!$process->isSuccessful()) {
            return array_values(array_unique($pids));
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if ($line === '' || !ctype_digit($line)) {
                continue;
            }
            $pids[] = (int) $line;
        }

        return array_values(array_unique($pids));
    }

    private function findTunnelPidFromSs(int $localPort): ?int
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

    private function findTunnelPidFromLsof(int $localPort): ?int
    {
        $process = new Process(['lsof', '-nP', '-iTCP:' . $localPort, '-sTCP:LISTEN', '-t']);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if ($line !== '' && ctype_digit($line)) {
                return (int) $line;
            }
        }

        return null;
    }
}

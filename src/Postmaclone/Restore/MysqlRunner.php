<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use Symfony\Component\Process\Process;

/**
 * Runs mysql against an ephemeral target, preferring `docker exec` for Docker
 * targets so restore does not use the compose-network alias (unreachable from
 * the host) or flaky host-port forwarding.
 */
final class MysqlRunner
{
    public function runFile(EphemeralTarget $target, string $sqlFile, int $timeout = 3600): void
    {
        if (!is_file($sqlFile)) {
            throw new PostmacloneException("SQL file not found: {$sqlFile}");
        }

        $in = fopen($sqlFile, 'rb');
        if ($in === false) {
            throw new PostmacloneException("Failed to open SQL file: {$sqlFile}");
        }

        try {
            $this->run($target, [], $in, $timeout);
        } finally {
            fclose($in);
        }
    }

    /**
     * @param list<string> $mysqlArgs Arguments after `mysql` (excluding connection target)
     * @param resource|null $stdin
     */
    public function run(EphemeralTarget $target, array $mysqlArgs, $stdin = null, int $timeout = 3600): void
    {
        $cmd = array_merge($this->command($target), $mysqlArgs);

        $process = new Process($cmd);
        $process->setTimeout($timeout);
        $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => $target->password]));
        if ($stdin !== null) {
            $process->setInput($stdin);
        }
        $process->run();

        if (!$process->isSuccessful()) {
            throw new PostmacloneException('mysql failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * @return list<string>
     */
    public function command(EphemeralTarget $target): array
    {
        $container = $target->meta['container_name'] ?? null;
        if ($target->provider === 'docker' && is_string($container) && $container !== '') {
            return [
                'docker', 'exec', '-i',
                '-e', 'MYSQL_PWD',
                $container,
                'mysql',
                '-h', '127.0.0.1',
                '-u', $target->username,
                $target->database,
            ];
        }

        $host = (string) ($target->meta['host_bind_host'] ?? $target->host);
        $port = (int) ($target->meta['host_bind_port'] ?? $target->port);

        return [
            'mysql',
            '-h', $host,
            '-P', (string) $port,
            '-u', $target->username,
            $target->database,
        ];
    }
}

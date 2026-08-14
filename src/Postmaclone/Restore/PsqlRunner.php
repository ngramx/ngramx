<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use Symfony\Component\Process\Process;

/**
 * Runs psql against an ephemeral target, preferring `docker exec` for Docker
 * targets so restore does not depend on flaky host-port forwarding (common on WSL).
 */
final class PsqlRunner
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
            $this->run($target, ['-v', 'ON_ERROR_STOP=1'], $in, $timeout);
        } finally {
            fclose($in);
        }
    }

    public function runQuery(EphemeralTarget $target, string $sql, int $timeout = 60): void
    {
        $this->run($target, ['-v', 'ON_ERROR_STOP=1', '-c', $sql], null, $timeout);
    }

    /**
     * @param list<string> $psqlArgs Arguments after `psql` (excluding connection target)
     * @param resource|null $stdin
     */
    public function run(EphemeralTarget $target, array $psqlArgs, $stdin = null, int $timeout = 3600): void
    {
        $container = $target->meta['container_name'] ?? null;
        if ($target->provider === 'docker' && is_string($container) && $container !== '') {
            $cmd = array_merge(
                ['docker', 'exec', '-i', $container, 'psql', '-U', $target->username, '-d', $target->database],
                $psqlArgs,
            );
        } else {
            $cmd = array_merge(['psql', $target->databaseUrl], $psqlArgs);
        }

        $process = new Process($cmd);
        $process->setTimeout($timeout);
        if ($stdin !== null) {
            $process->setInput($stdin);
        }
        $process->run();

        if (!$process->isSuccessful()) {
            throw new PostmacloneException('psql failed: ' . $process->getErrorOutput());
        }
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use Symfony\Component\Process\Process;

class PostgresRestorer implements RestorerInterface
{
    public function __construct(
        private readonly PlainSqlDumpSanitizer $sanitizer = new PlainSqlDumpSanitizer(),
        private readonly PsqlRunner $psql = new PsqlRunner(),
    ) {
    }

    public function restore(string $dumpPath, EphemeralTarget $target): void
    {
        if (!is_file($dumpPath)) {
            throw new PostmacloneException("Dump not found: {$dumpPath}");
        }

        if ($this->looksLikeCustomFormat($dumpPath)) {
            $this->restoreCustom($dumpPath, $target);

            return;
        }

        $this->restorePlain($dumpPath, $target);
    }

    private function restoreCustom(string $dumpPath, EphemeralTarget $target): void
    {
        $container = $target->meta['container_name'] ?? null;
        if ($target->provider === 'docker' && is_string($container) && $container !== '') {
            $in = fopen($dumpPath, 'rb');
            if ($in === false) {
                throw new PostmacloneException("Failed to open dump: {$dumpPath}");
            }
            try {
                $process = new Process([
                    'docker', 'exec', '-i', $container,
                    'pg_restore', '-v', '-O', '--no-acl', '--no-owner',
                    '-U', $target->username,
                    '-d', $target->database,
                ]);
                $process->setTimeout(3600);
                $process->setInput($in);
                $process->run();
            } finally {
                fclose($in);
            }
        } else {
            $process = new Process([
                'pg_restore',
                '-v',
                '-O',
                '--no-acl',
                '--no-owner',
                '-d', $target->databaseUrl,
                $dumpPath,
            ]);
            $process->setTimeout(3600);
            $process->run();
        }

        if (!$process->isSuccessful()) {
            $err = $process->getErrorOutput() . $process->getOutput();
            if (str_contains(strtolower($err), 'error') && !str_contains($err, 'errors ignored on restore')) {
                if ($process->getExitCode() > 1) {
                    throw new PostmacloneException('pg_restore failed: ' . $err);
                }
            }
        }
    }

    private function restorePlain(string $dumpPath, EphemeralTarget $target): void
    {
        $psqlPath = $this->sanitizer->forPsql($dumpPath);

        try {
            $this->psql->runFile($target, $psqlPath, 3600);
        } catch (PostmacloneException $e) {
            throw new PostmacloneException(
                'psql restore failed: ' . $e->getMessage()
                . "\nTip: ngramx postmaclone doctor",
                0,
                $e
            );
        } finally {
            $this->sanitizer->cleanup($dumpPath, $psqlPath);
        }
    }

    private function looksLikeCustomFormat(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 5);
        fclose($fh);

        return is_string($magic) && str_starts_with($magic, 'PGDMP');
    }
}

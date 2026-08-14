<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Filesystem\HostBinary;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * Dumps a remote/local database into a temp file for restore into an ephemeral clone.
 */
class ConnectionStringSource implements BackupSourceInterface
{
    private ?string $localPath = null;

    public function __construct(
        private readonly string $connectionUrl,
        private readonly string $engine,
        private readonly string $cacheDir,
    ) {
    }

    public function materialize(): string
    {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            throw new PostmacloneException("Failed to create cache dir: {$this->cacheDir}");
        }

        $this->localPath = rtrim($this->cacheDir, '/') . '/postmaclone-src-' . substr(hash('sha256', $this->connectionUrl), 0, 12) . '.sql';

        $path = $this->localPath;
        if ($this->engine === 'postgres') {
            $this->pgDump($path);
        } else {
            $this->mysqlDump($path);
        }

        return $path;
    }

    public function probe(): array
    {
        return [
            'exists' => true,
            'detail' => 'connection string source (will dump on materialize)',
        ];
    }

    public function lastModified(): ?int
    {
        return null;
    }

    public function cleanup(bool $keep): void
    {
        if ($keep || $this->localPath === null || !is_file($this->localPath)) {
            return;
        }
        unlink($this->localPath);
    }

    private function pgDump(string $out): void
    {
        $this->assertDumpClientOnPath('pg_dump', 'sudo apt install postgresql-client');

        $process = new Process(['pg_dump', '--no-owner', '--no-acl', '-f', $out, $this->connectionUrl]);
        $process->setTimeout(3600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('pg_dump failed: ' . $process->getErrorOutput());
        }
    }

    private function mysqlDump(string $out): void
    {
        $this->assertDumpClientOnPath(
            'mysqldump',
            'sudo apt install mysql-client-core-8.0  # or: sudo apt install mariadb-client'
        );

        $parts = parse_url($this->connectionUrl);
        if ($parts === false) {
            throw new PostmacloneException('Invalid MySQL connection URL');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = (string) ($parts['port'] ?? 3306);
        $user = isset($parts['user']) ? urldecode($parts['user']) : 'root';
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        $db = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

        $cmd = array_merge(
            [
                'mysqldump',
                '-h', $host,
                '-P', $port,
                '-u', $user,
            ],
            MysqlDumpFlags::forRestrictedSource(),
            [$db],
        );
        $process = new Process($cmd);
        if ($pass !== '') {
            $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => $pass]));
        }
        $process->setTimeout(3600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException(
                $this->formatDumpFailure('mysqldump', $process->getErrorOutput())
            );
        }
        file_put_contents($out, $process->getOutput());
    }

    private function assertDumpClientOnPath(string $binary, string $installHint): void
    {
        if (HostBinary::exists($binary)) {
            return;
        }

        throw new PostmacloneException(
            "{$binary} not found on PATH (required to dump a connection-string source).\n"
            . "  Install the client tools (e.g. {$installHint})."
        );
    }

    private function formatDumpFailure(string $binary, string $stderr): string
    {
        $message = "{$binary} failed: {$stderr}";
        $lower = strtolower($stderr);
        if (str_contains($lower, 'not found')) {
            $hint = $binary === 'mysqldump'
                ? 'sudo apt install mysql-client-core-8.0  # or: sudo apt install mariadb-client'
                : 'sudo apt install postgresql-client';
            $message .= "\n  Install the client tools (e.g. {$hint}).";
        }
        if (
            str_contains($lower, 'lock tables')
            || str_contains($lower, 'process privilege')
            || str_contains($lower, 'access denied')
        ) {
            $message .= "\n  Tip: source user needs SELECT (and typically SHOW VIEW). "
                . 'Postmaclone dumps with --single-transaction --no-tablespaces to avoid LOCK TABLES / PROCESS.';
        }

        return $message;
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

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

    public function cleanup(bool $keep): void
    {
        if ($keep || $this->localPath === null || !is_file($this->localPath)) {
            return;
        }
        unlink($this->localPath);
    }

    private function pgDump(string $out): void
    {
        $process = new Process(['pg_dump', '--no-owner', '--no-acl', '-f', $out, $this->connectionUrl]);
        $process->setTimeout(3600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('pg_dump failed: ' . $process->getErrorOutput());
        }
    }

    private function mysqlDump(string $out): void
    {
        $parts = parse_url($this->connectionUrl);
        if ($parts === false) {
            throw new PostmacloneException('Invalid MySQL connection URL');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = (string) ($parts['port'] ?? 3306);
        $user = isset($parts['user']) ? urldecode($parts['user']) : 'root';
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        $db = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

        $cmd = [
            'mysqldump',
            '-h', $host,
            '-P', $port,
            '-u', $user,
            $db,
        ];
        $process = new Process($cmd);
        if ($pass !== '') {
            $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => $pass]));
        }
        $process->setTimeout(3600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('mysqldump failed: ' . $process->getErrorOutput());
        }
        file_put_contents($out, $process->getOutput());
    }
}

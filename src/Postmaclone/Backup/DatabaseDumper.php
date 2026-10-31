<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Progress\PercentReporter;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Dump an anonymized/scratch database with optional table include/exclude filters.
 */
class DatabaseDumper
{
    /**
     * @param list<string>|null $includeTables
     * @param list<string>|null $excludeTables
     * @param (callable(string): void)|null $onProgress
     */
    public function dump(
        string $connectionUrl,
        string $engine,
        string $outPath,
        ?array $includeTables = null,
        ?array $excludeTables = null,
        bool $gzip = true,
        ?callable $onProgress = null,
    ): string {
        $plain = $gzip ? preg_replace('/\.gz$/i', '', $outPath) : $outPath;
        if (!is_string($plain) || $plain === '') {
            $plain = $outPath . '.sql';
        }
        if ($gzip && !str_ends_with(strtolower($outPath), '.gz')) {
            $outPath = $plain . '.gz';
        }

        $dir = dirname($plain);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new PostmacloneException("Failed to create dump directory: {$dir}");
        }

        if ($engine === 'postgres') {
            $this->pgDump($connectionUrl, $plain, $includeTables, $excludeTables, $onProgress);
            if (!$gzip) {
                return $plain;
            }
            $this->gzipFile($plain, $outPath, $onProgress);
            @unlink($plain);

            return $outPath;
        }

        // MySQL: stream mysqldump through gzip. Never hold the dump in PHP
        // memory and never write uncompressed SQL (Hydra's plain dump is tens
        // of GB; the lon1 produce droplet has 2GB RAM).
        $this->mysqlDump(
            $connectionUrl,
            $gzip ? $outPath : $plain,
            $includeTables,
            $excludeTables,
            $gzip,
            $onProgress,
        );

        return $gzip ? $outPath : $plain;
    }

    /**
     * @param list<string>|null $includeTables
     * @param list<string>|null $excludeTables
     * @param (callable(string): void)|null $onProgress
     */
    private function pgDump(string $url, string $out, ?array $includeTables, ?array $excludeTables, ?callable $onProgress): void
    {
        $cmd = [PostgresDumpBinary::resolve(), '--no-owner', '--no-acl', '-f', $out];
        foreach ($includeTables ?? [] as $table) {
            $cmd[] = '--table=' . $table;
        }
        foreach ($excludeTables ?? [] as $table) {
            $cmd[] = '--exclude-table=' . $table;
        }
        $cmd[] = $url;

        $process = new Process($cmd);
        $process->setTimeout(7200);
        $this->runDumpProcess($process, $out, $onProgress, 'Dumping anonymized database');
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('pg_dump failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * @param list<string>|null $includeTables
     * @param list<string>|null $excludeTables
     * @param (callable(string): void)|null $onProgress
     */
    private function mysqlDump(
        string $url,
        string $out,
        ?array $includeTables,
        ?array $excludeTables,
        bool $gzip,
        ?callable $onProgress,
    ): void {
        $parts = parse_url($url);
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
            MysqlDumpFlags::forScratchDatabase(),
        );
        foreach ($excludeTables ?? [] as $table) {
            $cmd[] = '--ignore-table=' . $db . '.' . $table;
        }
        $cmd[] = $db;
        foreach ($includeTables ?? [] as $table) {
            $cmd[] = $table;
        }

        $errFile = $out . '.mysqldump.err';
        $quoted = implode(' ', array_map('escapeshellarg', $cmd));
        $script = $this->mysqlDumpPipeline($quoted, $out, $errFile, $gzip);

        $process = new Process($this->mysqlDumpShell($script));
        if ($pass !== '') {
            $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => $pass]));
        }
        $process->setTimeout(7200);
        $process->disableOutput();
        try {
            $this->runDumpProcess($process, $out, $onProgress, 'Dumping anonymized database');
        } catch (ProcessTimedOutException $e) {
            $this->stopDumpProcessGroup($process);
            throw new PostmacloneException('MySQL dump timed out after 7200 seconds', 0, $e);
        }
        $err = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';
        @unlink($errFile);
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('MySQL dump failed: ' . $err);
        }
        if ($this->dumpFileSize($out) === 0) {
            throw new PostmacloneException('mysqldump produced an empty file' . ($err !== '' ? ': ' . $err : ''));
        }
    }

    /**
     * @param (callable(string): void)|null $onProgress
     */
    private function gzipFile(string $src, string $dest, ?callable $onProgress = null): void
    {
        $in = fopen($src, 'rb');
        if ($in === false) {
            throw new PostmacloneException("Failed to open dump for gzip: {$src}");
        }
        $out = gzopen($dest, 'wb9');
        if ($out === false) {
            fclose($in);
            throw new PostmacloneException("Failed to open gzip destination: {$dest}");
        }

        $size = filesize($src);
        $reporter = $onProgress !== null
            ? new PercentReporter($size === false ? 0 : $size, 'Compressing dump', $onProgress)
            : null;

        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            gzwrite($out, $chunk);
            $reporter?->add(strlen($chunk));
        }
        $reporter?->finish();
        fclose($in);
        gzclose($out);
    }

    /**
     * @param (callable(string): void)|null $onProgress
     */
    private function runDumpProcess(Process $process, string $out, ?callable $onProgress, string $label): void
    {
        if ($onProgress === null) {
            $process->run();

            return;
        }

        $onProgress($label);
        $lastBytes = 0;
        $process->start();
        while ($process->isRunning()) {
            $process->checkTimeout();
            if (!$process->isOutputDisabled()) {
                $process->getIncrementalOutput();
                $process->getIncrementalErrorOutput();
            }
            $size = $this->dumpFileSize($out);
            if ($size - $lastBytes >= 256 * 1024 * 1024) {
                $onProgress(sprintf('%s (%s written)', $label, $this->formatBytes($size)));
                $lastBytes = $size;
            }
            usleep(5_000_000);
        }
        $size = $this->dumpFileSize($out);
        if ($size > 0) {
            $onProgress(sprintf('%s finished (%s)', $label, $this->formatBytes($size)));
        }
    }

    /**
     * New process group (setsid) plus TERM trap so a Symfony timeout kills
     * mysqldump and gzip, not just the wrapper shell.
     *
     * gzip stderr is appended to the same sidecar as mysqldump; process
     * output is disabled so we would otherwise lose ENOSPC / missing gzip.
     */
    private function mysqlDumpPipeline(string $quotedCmd, string $out, string $errFile, bool $gzip): string
    {
        $err = escapeshellarg($errFile);
        $script = 'set -o pipefail; '
            . 'trap \'trap - TERM; kill -- -$$ 2>/dev/null\' TERM; '
            . $quotedCmd . ' 2> ' . $err;

        return $gzip
            ? $script . ' | gzip -1 2>> ' . $err . ' > ' . escapeshellarg($out)
            : $script . ' > ' . escapeshellarg($out);
    }

    /**
     * @return list<string>
     */
    private function mysqlDumpShell(string $script): array
    {
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $setsid) {
            if (is_executable($setsid)) {
                return [$setsid, 'bash', '-c', $script];
            }
        }

        return ['bash', '-c', $script];
    }

    private function stopDumpProcessGroup(Process $process): void
    {
        $pid = $process->getPid();
        if (is_int($pid) && $pid > 1 && \function_exists('posix_kill')) {
            posix_kill(-$pid, SIGTERM);
        }
        $process->stop(1.0, SIGKILL);
    }

    private function dumpFileSize(string $path): int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%.1f GB', $bytes / (1024 * 1024 * 1024));
    }
}

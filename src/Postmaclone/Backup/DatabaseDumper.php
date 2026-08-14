<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * Dump an anonymized/scratch database with optional table include/exclude filters.
 */
class DatabaseDumper
{
    /**
     * @param list<string>|null $includeTables
     * @param list<string>|null $excludeTables
     */
    public function dump(
        string $connectionUrl,
        string $engine,
        string $outPath,
        ?array $includeTables = null,
        ?array $excludeTables = null,
        bool $gzip = true,
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
            $this->pgDump($connectionUrl, $plain, $includeTables, $excludeTables);
        } else {
            $this->mysqlDump($connectionUrl, $plain, $includeTables, $excludeTables);
        }

        if (!$gzip) {
            return $plain;
        }

        $this->gzipFile($plain, $outPath);
        @unlink($plain);

        return $outPath;
    }

    /**
     * @param list<string>|null $includeTables
     * @param list<string>|null $excludeTables
     */
    private function pgDump(string $url, string $out, ?array $includeTables, ?array $excludeTables): void
    {
        $cmd = ['pg_dump', '--no-owner', '--no-acl', '-f', $out];
        foreach ($includeTables ?? [] as $table) {
            $cmd[] = '--table=' . $table;
        }
        foreach ($excludeTables ?? [] as $table) {
            $cmd[] = '--exclude-table=' . $table;
        }
        $cmd[] = $url;

        $process = new Process($cmd);
        $process->setTimeout(7200);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('pg_dump failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * @param list<string>|null $includeTables
     * @param list<string>|null $excludeTables
     */
    private function mysqlDump(string $url, string $out, ?array $includeTables, ?array $excludeTables): void
    {
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

        $process = new Process($cmd);
        if ($pass !== '') {
            $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => $pass]));
        }
        $process->setTimeout(7200);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('mysqldump failed: ' . $process->getErrorOutput());
        }
        if (file_put_contents($out, $process->getOutput()) === false) {
            throw new PostmacloneException("Failed to write dump: {$out}");
        }
    }

    private function gzipFile(string $src, string $dest): void
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
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            gzwrite($out, $chunk);
        }
        fclose($in);
        gzclose($out);
    }
}

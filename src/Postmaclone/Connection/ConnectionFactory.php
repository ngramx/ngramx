<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connection;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use PDO;

class ConnectionFactory
{
    public function fromUrl(string $url, bool $readOnly = false): PDO
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new PostmacloneException('Invalid database connection URL');
        }

        $scheme = strtolower($parts['scheme']);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $user = isset($parts['user']) ? urldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        $path = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

        if (in_array($scheme, ['postgresql', 'postgres', 'pdo_pgsql'], true)) {
            $port ??= 5432;
            $dsn = "pgsql:host={$host};port={$port};dbname={$path}";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($readOnly) {
                $pdo->exec('SET default_transaction_read_only = on');
            }

            return $pdo;
        }

        if (in_array($scheme, ['mysql', 'mariadb', 'pdo_mysql'], true)) {
            $port ??= 3306;
            $dsn = "mysql:host={$host};port={$port};dbname={$path};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($readOnly) {
                try {
                    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
                } catch (\Throwable) {
                    // Best-effort on MySQL versions that lack the setting.
                }
            }

            return $pdo;
        }

        throw new PostmacloneException("Unsupported connection scheme: {$scheme}");
    }

    public function fromParts(
        string $engine,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
    ): PDO {
        $url = $this->buildUrl($engine, $host, $port, $database, $username, $password);

        return $this->fromUrl($url);
    }

    public function buildUrl(
        string $engine,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
    ): string {
        $scheme = $engine === 'postgres' ? 'postgresql' : 'mysql';

        return sprintf(
            '%s://%s:%s@%s:%d/%s',
            $scheme,
            rawurlencode($username),
            rawurlencode($password),
            $host,
            $port,
            $database
        );
    }
}

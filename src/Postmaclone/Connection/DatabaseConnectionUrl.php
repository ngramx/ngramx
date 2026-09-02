<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connection;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Parse and rebuild Postgres/MySQL connection URLs for password rotation.
 */
readonly class DatabaseConnectionUrl
{
    public function __construct(
        public string $scheme,
        public string $username,
        public string $password,
        public string $host,
        public int $port,
        public string $database,
    ) {
    }

    public static function parse(string $url): self
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['user'])) {
            throw new PostmacloneException('Invalid database connection URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $database = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
        if ($database === '') {
            throw new PostmacloneException('Database connection URL must include a database name');
        }

        return new self(
            scheme: $scheme,
            username: urldecode((string) $parts['user']),
            password: isset($parts['pass']) ? urldecode((string) $parts['pass']) : '',
            host: (string) $parts['host'],
            port: (int) ($parts['port'] ?? (in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) ? 5432 : 3306)),
            database: $database,
        );
    }

    public function withPassword(string $password): self
    {
        return new self(
            scheme: $this->scheme,
            username: $this->username,
            password: $password,
            host: $this->host,
            port: $this->port,
            database: $this->database,
        );
    }

    public function toUrl(): string
    {
        $user = rawurlencode($this->username);
        $pass = rawurlencode($this->password);
        $db = rawurlencode($this->database);
        $scheme = $this->scheme === 'postgresql' ? 'postgres' : $this->scheme;

        return "{$scheme}://{$user}:{$pass}@{$this->host}:{$this->port}/{$db}";
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connection;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Parsed database connection URL parts for env binding and tunnel setup.
 */
readonly class ParsedConnectionUrl
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $query = '',
    ) {
    }

    public function engine(): string
    {
        return in_array($this->scheme, ['postgresql', 'postgres', 'pdo_pgsql'], true)
            ? PostmacloneConfig::ENGINE_POSTGRES
            : PostmacloneConfig::ENGINE_MYSQL;
    }

    public function databaseUrl(string $host, int $port): string
    {
        $scheme = $this->engine() === PostmacloneConfig::ENGINE_POSTGRES ? 'postgresql' : 'mysql';
        $url = sprintf(
            '%s://%s:%s@%s:%d/%s',
            $scheme,
            rawurlencode($this->username),
            rawurlencode($this->password),
            $host,
            $port,
            rawurlencode($this->database),
        );

        if ($this->query !== '') {
            $url .= '?' . $this->query;
        }

        return $url;
    }
}

final class ConnectionUrlParser
{
    public function parse(string $url): ParsedConnectionUrl
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
        $query = isset($parts['query']) ? (string) $parts['query'] : '';

        if (in_array($scheme, ['postgresql', 'postgres', 'pdo_pgsql'], true)) {
            $port ??= 5432;
        } elseif (in_array($scheme, ['mysql', 'mariadb', 'pdo_mysql'], true)) {
            $port ??= 3306;
        } else {
            throw new PostmacloneException("Unsupported connection scheme: {$scheme}");
        }

        if ($host === '' || $path === '') {
            throw new PostmacloneException('Connection URL requires host and database');
        }

        return new ParsedConnectionUrl(
            scheme: $scheme,
            host: $host,
            port: $port,
            database: $path,
            username: $user,
            password: $pass,
            query: $query,
        );
    }
}

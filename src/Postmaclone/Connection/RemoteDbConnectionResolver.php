<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connection;

use Ngramx\Config\Schema\Postmaclone\DbConnectionConfig;
use Ngramx\Config\Schema\Postmaclone\DbCredentialsConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Backup\OpSecretReader;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Resolves a DbConnectionConfig (full URL or database + op credentials) to a connection URL.
 */
final class RemoteDbConnectionResolver
{
    public function __construct(
        private readonly OpSecretReader $opReader = new OpSecretReader(),
    ) {
    }

    public function resolve(?DbConnectionConfig $connection, string $engine, ?string $legacyUrl = null): string
    {
        if ($connection !== null && $connection->isConfigured()) {
            if ($connection->url !== null && $connection->url !== '') {
                return $this->resolveUrlReference($connection->url);
            }

            if ($connection->usesCredentialParts()) {
                return $this->buildFromParts($connection, $engine);
            }
        }

        if ($legacyUrl !== null && $legacyUrl !== '') {
            return $this->resolveUrlReference($legacyUrl);
        }

        $env = getenv('POSTMACLONE_REMOTE_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        throw new PostmacloneException(
            'Remote database connection requires target.remote / shared database+credentials, '
            . 'target.remote.url, shared.url, or POSTMACLONE_REMOTE_URL'
        );
    }

    private function buildFromParts(DbConnectionConfig $connection, string $engine): string
    {
        $credentials = $connection->credentials;
        if ($credentials === null) {
            throw new PostmacloneException('Remote connection credentials are required');
        }

        $username = $this->resolveUrlReference($credentials->username);
        $password = $this->resolveUrlReference($credentials->password);
        $host = $this->resolveHost($connection, $credentials);
        $database = $connection->database ?? '';
        if ($host === '' || $database === '') {
            throw new PostmacloneException('Remote connection host and database are required');
        }

        $port = $this->resolvePort($connection, $credentials, $engine);
        $scheme = $engine === PostmacloneConfig::ENGINE_POSTGRES ? 'postgres' : 'mysql';

        $url = sprintf(
            '%s://%s:%s@%s:%d/%s',
            $scheme,
            rawurlencode($username),
            rawurlencode($password),
            $host,
            $port,
            rawurlencode($database),
        );

        if ($engine === PostmacloneConfig::ENGINE_POSTGRES) {
            $sslmode = $this->resolveSslMode($credentials);
            if ($sslmode !== null) {
                $url .= '?sslmode=' . rawurlencode($sslmode);
            }
        }

        return $url;
    }

    private function resolveHost(DbConnectionConfig $connection, DbCredentialsConfig $credentials): string
    {
        if ($connection->host !== null && $connection->host !== '') {
            return $this->resolveUrlReference($connection->host);
        }

        if ($credentials->host !== null && $credentials->host !== '') {
            return $this->resolveUrlReference($credentials->host);
        }

        return '';
    }

    private function resolvePort(DbConnectionConfig $connection, DbCredentialsConfig $credentials, string $engine): int
    {
        if ($connection->port !== null) {
            return $connection->port;
        }

        if ($credentials->port !== null && $credentials->port !== '') {
            return (int) $this->resolveUrlReference($credentials->port);
        }

        return $engine === PostmacloneConfig::ENGINE_POSTGRES ? 5432 : 3306;
    }

    private function resolveSslMode(DbCredentialsConfig $credentials): ?string
    {
        if ($credentials->connectionOptions === null || $credentials->connectionOptions === '') {
            return 'require';
        }

        $options = $this->resolveUrlReference($credentials->connectionOptions);
        if (preg_match('/sslmode=([^\s&]+)/', $options, $matches) === 1) {
            return $matches[1];
        }

        return 'require';
    }

    private function resolveUrlReference(string $value): string
    {
        if (str_starts_with($value, 'op://')) {
            return $this->opReader->read($value);
        }

        return $value;
    }
}

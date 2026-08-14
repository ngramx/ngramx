<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneLockData;

/**
 * Restore into an existing in-region Postgres/MySQL URL (DO Managed / Neon / droplet).
 * Does not share one long-lived DB across sessions — caller must supply a fresh empty DB URL.
 */
class RemoteDbTarget implements EphemeralTargetInterface
{
    public function __construct(
        private readonly ?string $url,
    ) {
    }

    public function provision(string $engine, int $ttlHours): EphemeralTarget
    {
        $url = $this->resolveUrl();
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new PostmacloneException('Invalid remote target URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $expected = $engine === 'postgres' ? ['pgsql', 'postgres', 'postgresql'] : ['mysql', 'mariadb'];
        if (!in_array($scheme, $expected, true) && !($engine !== 'postgres' && $scheme === 'mysql')) {
            // allow postgres:// style
            if ($engine === 'postgres' && !str_starts_with($scheme, 'postgres')) {
                throw new PostmacloneException("Remote URL scheme '{$scheme}' does not match engine {$engine}");
            }
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($engine === 'postgres' ? 5432 : 3306));
        $database = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        $username = isset($parts['user']) ? urldecode($parts['user']) : '';
        $password = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        if ($database === '' || $username === '') {
            throw new PostmacloneException('Remote target URL must include database name and username');
        }

        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$ttlHours} hours")
            ->format('c');

        return new EphemeralTarget(
            provider: 'remote',
            engine: $engine,
            host: $host,
            port: $port,
            database: $database,
            username: $username,
            password: $password,
            databaseUrl: $url,
            expiresAt: $expires,
            meta: [
                'host_bind_host' => $host,
                'host_bind_port' => $port,
            ],
        );
    }

    public function destroy(PostmacloneLockData $lock): void
    {
        // Remote DBs are externally provisioned; wipe is best-effort DROP SCHEMA / no-op.
        // Operators recreate empty DBs per session in the factory/cloud side.
    }

    private function resolveUrl(): string
    {
        if ($this->url !== null && $this->url !== '') {
            if (str_starts_with($this->url, 'op://')) {
                $reader = new \Ngramx\Postmaclone\Backup\OpSecretReader();

                return $reader->read($this->url);
            }

            return $this->url;
        }

        $env = getenv('POSTMACLONE_REMOTE_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        throw new PostmacloneException(
            'Remote target requires postmaclone.target.remote.url or POSTMACLONE_REMOTE_URL'
        );
    }
}

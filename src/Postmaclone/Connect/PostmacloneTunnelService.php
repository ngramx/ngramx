<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

use Ngramx\Codabyte\ServerTargetResolver;
use Ngramx\Config\Schema\Postmaclone\ConnectConfig;
use Ngramx\Config\Schema\Postmaclone\DbConnectionConfig;
use Ngramx\Config\Schema\Postmaclone\DbCredentialsConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Connection\ConnectionUrlParser;
use Ngramx\Postmaclone\Connection\ParsedConnectionUrl;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * SSH tunnel to shared anonymized DB clusters using factory engines.*.anon credentials.
 */
final class PostmacloneTunnelService
{
    public const DEFAULT_POSTGRES_LOCAL_PORT = 15432;
    public const DEFAULT_MYSQL_LOCAL_PORT = 13306;

    public function __construct(
        private readonly RemoteDbConnectionResolver $connectionResolver = new RemoteDbConnectionResolver(),
        private readonly ConnectionUrlParser $urlParser = new ConnectionUrlParser(),
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
    ) {
    }

    public function status(string $engine): ?PostmacloneConnectLockData
    {
        return (new PostmacloneTunnelLock($this->normalizeEngine($engine)))->read();
    }

    /**
     * @return array{lock: PostmacloneConnectLockData, tunnel_args: list<string>}
     */
    public function open(
        FactoryConfig $factory,
        string $engine,
        ?string $database = null,
        bool $probe = true,
        bool $replace = false,
        ?int $localPortOverride = null,
    ): array {
        $engine = $this->normalizeEngine($engine);
        $anon = $this->requireEngineAnon($factory, $engine);

        $lockFile = new PostmacloneTunnelLock($engine);
        if ($lockFile->exists()) {
            if (!$replace) {
                $flag = $this->engineCliFlag($engine);
                throw new PostmacloneException(
                    "A {$engine} tunnel is already open. Run `ngramx postmaclone tunnel --{$flag} --tunnel-disconnect` "
                    . 'or add --replace.'
                );
            }
            $this->close($engine, force: true);
        }

        $database ??= $engine === PostmacloneConfig::ENGINE_POSTGRES ? 'postgres' : 'mysql';
        $connection = new DbConnectionConfig(database: $database, credentials: $anon);

        $remoteUrl = $this->connectionResolver->resolve($connection, $engine);
        $parsed = $this->urlParser->parse($remoteUrl);
        $connectConfig = $factory->connect ?? new ConnectConfig();
        $preferredPort = $localPortOverride ?? $connectConfig->localPort ?? $this->defaultLocalPort($engine);
        $exactPort = $localPortOverride !== null;

        $tunnel = new SshTunnelManager($this->resolveTunnelTarget($connectConfig));
        $batch = $tunnel->probeBatchMode();
        if (!$batch['ok']) {
            throw new PostmacloneException($batch['message']);
        }

        $localPort = $tunnel->allocateLocalPort($preferredPort, $exactPort);

        try {
            $tunnel->startBackground($localPort, $parsed->host, $parsed->port);
            if (!$tunnel->waitForLocalPort($localPort)) {
                $tunnel->stopTunnel(null, $localPort);
                throw new PostmacloneException(
                    "SSH tunnel did not bind 127.0.0.1:{$localPort} in time. Check Codabyte access and retry."
                );
            }

            $tunnelPid = $tunnel->resolveTunnelPid($localPort);
            $connectedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

            $lock = new PostmacloneConnectLockData(
                mode: PostmacloneConnectLockData::MODE_TUNNEL,
                engine: $engine,
                connectedAt: $connectedAt,
                host: '127.0.0.1',
                port: $localPort,
                database: $parsed->database,
                username: $parsed->username,
                password: $parsed->password,
                databaseUrl: $parsed->databaseUrl('127.0.0.1', $localPort),
                ideHost: '127.0.0.1',
                idePort: $localPort,
                remoteHost: $parsed->host,
                remotePort: $parsed->port,
                localPort: $localPort,
                tunnelPid: $tunnelPid,
            );

            if ($probe) {
                $this->probeConnection($parsed, $localPort, $engine);
            }

            $lockFile->write($lock);

            return [
                'lock' => $lock,
                'tunnel_args' => $tunnel->foregroundArgs($localPort, $parsed->host, $parsed->port),
            ];
        } catch (\Throwable $e) {
            $tunnel->stopTunnel(null, $localPort);
            throw $e;
        }
    }

    /**
     * @return array{lock: PostmacloneConnectLockData, tunnel_args: list<string>}
     */
    public function prepareForeground(
        FactoryConfig $factory,
        string $engine,
        ?string $database = null,
        ?int $localPortOverride = null,
    ): array {
        $engine = $this->normalizeEngine($engine);
        $anon = $this->requireEngineAnon($factory, $engine);

        if ((new PostmacloneTunnelLock($engine))->exists()) {
            $flag = $this->engineCliFlag($engine);
            throw new PostmacloneException(
                "A tunnel is already active. Run `ngramx postmaclone tunnel --{$flag} --tunnel-disconnect` first."
            );
        }

        $database ??= $engine === PostmacloneConfig::ENGINE_POSTGRES ? 'postgres' : 'mysql';
        $connection = new DbConnectionConfig(database: $database, credentials: $anon);

        $remoteUrl = $this->connectionResolver->resolve($connection, $engine);
        $parsed = $this->urlParser->parse($remoteUrl);
        $connectConfig = $factory->connect ?? new ConnectConfig();
        $preferredPort = $localPortOverride ?? $connectConfig->localPort ?? $this->defaultLocalPort($engine);
        $exactPort = $localPortOverride !== null;
        $tunnel = new SshTunnelManager($this->resolveTunnelTarget($connectConfig));
        $localPort = $tunnel->allocateLocalPort($preferredPort, $exactPort);

        $lock = new PostmacloneConnectLockData(
            mode: PostmacloneConnectLockData::MODE_TUNNEL,
            engine: $engine,
            connectedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            host: '127.0.0.1',
            port: $localPort,
            database: $parsed->database,
            username: $parsed->username,
            password: $parsed->password,
            databaseUrl: $parsed->databaseUrl('127.0.0.1', $localPort),
            ideHost: '127.0.0.1',
            idePort: $localPort,
            remoteHost: $parsed->host,
            remotePort: $parsed->port,
            localPort: $localPort,
        );

        (new PostmacloneTunnelLock($engine))->write($lock);

        return [
            'lock' => $lock,
            'tunnel_args' => $tunnel->foregroundArgs($localPort, $parsed->host, $parsed->port),
        ];
    }

    public function close(string $engine, bool $force = false): bool
    {
        $engine = $this->normalizeEngine($engine);
        $lockFile = new PostmacloneTunnelLock($engine);
        $lock = $lockFile->read();
        if ($lock === null) {
            return false;
        }

        $tunnel = new SshTunnelManager();
        if (!$tunnel->stopTunnel($lock->tunnelPid, $lock->localPort) && !$force) {
            throw new PostmacloneException(
                'Failed to stop SSH tunnel'
                . ($lock->tunnelPid !== null ? ' (pid ' . $lock->tunnelPid . ')' : '')
                . '. Re-run with --force to clear local state.'
            );
        }

        $lockFile->delete();

        return true;
    }

    public function normalizeEngine(string $engine): string
    {
        $engine = strtolower(trim($engine));
        if (in_array($engine, ['psql', 'pgsql', 'postgresql'], true)) {
            return PostmacloneConfig::ENGINE_POSTGRES;
        }
        if ($engine === 'mariadb') {
            return PostmacloneConfig::ENGINE_MARIADB;
        }

        return $engine;
    }

    public function engineCliFlag(string $engine): string
    {
        return match ($this->normalizeEngine($engine)) {
            PostmacloneConfig::ENGINE_POSTGRES => 'psql',
            PostmacloneConfig::ENGINE_MYSQL, PostmacloneConfig::ENGINE_MARIADB => 'mysql',
            default => $engine,
        };
    }

    private function defaultLocalPort(string $engine): int
    {
        return match ($this->normalizeEngine($engine)) {
            PostmacloneConfig::ENGINE_POSTGRES => self::DEFAULT_POSTGRES_LOCAL_PORT,
            default => self::DEFAULT_MYSQL_LOCAL_PORT,
        };
    }

    private function requireEngineAnon(FactoryConfig $factory, string $engine): DbCredentialsConfig
    {
        $engineConfig = $factory->engines[$engine] ?? null;
        $anon = $engineConfig?->anon;
        if ($anon === null) {
            throw new PostmacloneException(
                "Factory config has no engines.{$engine}.anon credentials for tunnel."
            );
        }

        return $anon;
    }

    private function probeConnection(ParsedConnectionUrl $parsed, int $localPort, string $engine): void
    {
        $url = $parsed->databaseUrl('127.0.0.1', $localPort);

        try {
            $pdo = $this->connections->fromUrl($url);
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            throw new PostmacloneException(
                'Database connection probe failed: ' . $e->getMessage()
            );
        }
    }

    private function resolveTunnelTarget(ConnectConfig $connectConfig): \Ngramx\Codabyte\ServerTarget
    {
        $resolver = ServerTargetResolver::fromEnvironment();

        return $resolver->resolve([
            'host' => $connectConfig->tunnelHost,
            'ssh-user' => $connectConfig->tunnelUser,
            'port' => $connectConfig->tunnelPort !== null ? (string) $connectConfig->tunnelPort : null,
        ]);
    }
}

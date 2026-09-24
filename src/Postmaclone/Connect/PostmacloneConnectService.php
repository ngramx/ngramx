<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

use Ngramx\Codabyte\ServerTarget;
use Ngramx\Codabyte\ServerTargetResolver;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Validator\ConfigValidator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Config\Schema\Postmaclone\ConnectConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Connection\ConnectionUrlParser;
use Ngramx\Postmaclone\Connection\ParsedConnectionUrl;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use Ngramx\Postmaclone\EngineDetector;
use Ngramx\Postmaclone\EnvBinder;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneLock;
use Ngramx\Postmaclone\PostmacloneLockData;

class PostmacloneConnectService
{
    public function __construct(
        private readonly RemoteDbConnectionResolver $connectionResolver = new RemoteDbConnectionResolver(),
        private readonly ConnectionUrlParser $urlParser = new ConnectionUrlParser(),
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
        private readonly TrustedEgressDetector $egressDetector = new TrustedEgressDetector(),
        private readonly SharedDbFreshnessChecker $freshnessChecker = new SharedDbFreshnessChecker(),
        private readonly EngineDetector $engineDetector = new EngineDetector(),
        private readonly PostmacloneConnectComposeOverride $composeOverride = new PostmacloneConnectComposeOverride(),
        private readonly DockerCompose $dockerCompose = new DockerCompose(),
    ) {
    }

    public function status(string $projectRoot): ?PostmacloneConnectLockData
    {
        return (new PostmacloneConnectLock($projectRoot))->read();
    }

    /**
     * @return array{lock: PostmacloneConnectLockData, warnings: list<string>, refreshed_services: list<string>}
     */
    public function connect(
        NgramxConfig $config,
        string $projectRoot,
        bool $bindEnv = true,
        bool $strict = false,
        bool $replace = false,
        bool $probe = true,
    ): array {
        $pm = $this->requireSharedConfig($config);
        $warnings = $this->freshnessChecker->check(
            $pm->prebuilt,
            $pm->shared?->maxAgeHours,
            $strict,
        );

        $connectLock = new PostmacloneConnectLock($projectRoot);
        $cloneLock = new PostmacloneLock($projectRoot);

        if ($cloneLock->exists()) {
            throw new PostmacloneException(
                'An ephemeral Post Maclone clone is active. Run `ngramx postmaclone down` before connecting to the shared hosted DB.'
            );
        }

        if ($connectLock->exists()) {
            if (!$replace) {
                throw new PostmacloneException(
                    'Already connected to the shared hosted DB. Run `ngramx postmaclone disconnect` or `ngramx postmaclone connect --replace`.'
                );
            }
            $this->disconnect($projectRoot, force: true);
        }

        $engine = $this->engineDetector->detect($pm->engine, $config->docker->composeFile);
        $remoteUrl = $this->connectionResolver->resolve($pm->shared?->connection, $engine);
        $parsed = $this->urlParser->parse($remoteUrl);
        $connectConfig = $pm->connect ?? new ConnectConfig();
        $direct = $this->egressDetector->isDirectMode();

        $tunnelPid = null;
        $localPort = null;
        $envHost = $parsed->host;
        $envPort = $parsed->port;
        $ideHost = $parsed->host;
        $idePort = $parsed->port;
        $mode = PostmacloneConnectLockData::MODE_DIRECT;
        $tunnel = null;

        try {
            if (!$direct) {
                $mode = PostmacloneConnectLockData::MODE_TUNNEL;
                $localPort = (new SshTunnelManager($this->resolveTunnelTarget($connectConfig)))->allocateLocalPort(
                    $connectConfig->localPort,
                );

                $tunnel = new SshTunnelManager($this->resolveTunnelTarget($connectConfig));
                $batch = $tunnel->probeBatchMode();
                if (!$batch['ok']) {
                    throw new PostmacloneException($batch['message']);
                }

                $tunnel->startBackground($localPort, $parsed->host, $parsed->port);
                if (!$tunnel->waitForLocalPort($localPort)) {
                    $tunnel->stopTunnel(null, $localPort);
                    throw new PostmacloneException(
                        "SSH tunnel did not bind 127.0.0.1:{$localPort} in time. Check Codabyte access and retry."
                    );
                }

                $tunnelPid = $tunnel->resolveTunnelPid($localPort);

                $envHost = $connectConfig->dockerHost;
                $envPort = $localPort;
                $ideHost = '127.0.0.1';
                $idePort = $localPort;
            }

            $databaseUrl = $parsed->databaseUrl($envHost, $envPort);
            $connectedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

            $lock = new PostmacloneConnectLockData(
                mode: $mode,
                engine: $engine,
                connectedAt: $connectedAt,
                host: $envHost,
                port: $envPort,
                database: $parsed->database,
                username: $parsed->username,
                password: $parsed->password,
                databaseUrl: $databaseUrl,
                ideHost: $ideHost,
                idePort: $idePort,
                remoteHost: $direct ? null : $parsed->host,
                remotePort: $direct ? null : $parsed->port,
                localPort: $localPort,
                tunnelPid: $tunnelPid,
            );

            if ($probe) {
                $this->probeConnection($parsed, $direct ? null : $localPort, $engine);
            }

            if ($bindEnv) {
                $lock = $lock->withEnvBackupPath($this->bindEnv($projectRoot, $lock));
            }

            $connectLock->write($lock);
            $this->applyComposeOverrides($config, $lock);
            $refreshed = $this->refreshDatabaseContainers($config, $projectRoot, $warnings);

            return ['lock' => $lock, 'warnings' => $warnings, 'refreshed_services' => $refreshed];
        } catch (\Throwable $e) {
            if ($tunnel !== null) {
                $tunnel->stopTunnel($tunnelPid, $localPort);
            }

            throw $e;
        }
    }

    /**
     * @return array{lock: PostmacloneConnectLockData, warnings: list<string>, tunnel_args: list<string>}
     */
    public function prepareForegroundConnect(
        NgramxConfig $config,
        string $projectRoot,
        bool $strict = false,
    ): array {
        $pm = $this->requireSharedConfig($config);
        $warnings = $this->freshnessChecker->check(
            $pm->prebuilt,
            $pm->shared?->maxAgeHours,
            $strict,
        );

        if ((new PostmacloneLock($projectRoot))->exists()) {
            throw new PostmacloneException(
                'An ephemeral Post Maclone clone is active. Run `ngramx postmaclone down` first.'
            );
        }

        if ((new PostmacloneConnectLock($projectRoot))->exists()) {
            throw new PostmacloneException(
                'Already connected. Run `ngramx postmaclone disconnect` first.'
            );
        }

        if ($this->egressDetector->isDirectMode()) {
            throw new PostmacloneException(
                'Foreground tunnel is only for local development. This host has trusted DB egress — omit --foreground.'
            );
        }

        $engine = $this->engineDetector->detect($pm->engine, $config->docker->composeFile);
        $remoteUrl = $this->connectionResolver->resolve($pm->shared?->connection, $engine);
        $parsed = $this->urlParser->parse($remoteUrl);
        $connectConfig = $pm->connect ?? new ConnectConfig();
        $tunnel = new SshTunnelManager($this->resolveTunnelTarget($connectConfig));
        $localPort = $tunnel->allocateLocalPort($connectConfig->localPort);

        $lock = new PostmacloneConnectLockData(
            mode: PostmacloneConnectLockData::MODE_TUNNEL,
            engine: $engine,
            connectedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            host: $connectConfig->dockerHost,
            port: $localPort,
            database: $parsed->database,
            username: $parsed->username,
            password: $parsed->password,
            databaseUrl: $parsed->databaseUrl($connectConfig->dockerHost, $localPort),
            ideHost: '127.0.0.1',
            idePort: $localPort,
            remoteHost: $parsed->host,
            remotePort: $parsed->port,
            localPort: $localPort,
        );

        return [
            'lock' => $lock,
            'warnings' => $warnings,
            'tunnel_args' => $tunnel->foregroundArgs($localPort, $parsed->host, $parsed->port),
        ];
    }

    /**
     * Stage a foreground tunnel: patch .env and write lock before SSH blocks.
     *
     * @return array{lock: PostmacloneConnectLockData, warnings: list<string>, tunnel_args: list<string>}
     */
    public function stageForegroundConnect(
        NgramxConfig $config,
        string $projectRoot,
        bool $bindEnv = true,
        bool $strict = false,
    ): array {
        $prepared = $this->prepareForegroundConnect($config, $projectRoot, $strict);
        $lock = $prepared['lock'];

        $envBackupPath = null;
        if ($bindEnv) {
            $lock = $lock->withEnvBackupPath($this->bindEnv($projectRoot, $lock));
        }

        (new PostmacloneConnectLock($projectRoot))->write($lock);
        $this->applyComposeOverrides($config, $lock);

        return [
            'lock' => $lock,
            'warnings' => $prepared['warnings'],
            'tunnel_args' => $prepared['tunnel_args'],
        ];
    }

    public function disconnect(string $projectRoot, bool $force = false, bool $refreshContainers = true): bool
    {
        $connectLock = new PostmacloneConnectLock($projectRoot);
        $lock = $connectLock->read();
        if ($lock === null) {
            return false;
        }

        if ($lock->mode === PostmacloneConnectLockData::MODE_TUNNEL) {
            $tunnel = new SshTunnelManager();
            if (!$tunnel->stopTunnel($lock->tunnelPid, $lock->localPort) && !$force) {
                throw new PostmacloneException(
                    'Failed to stop SSH tunnel'
                    . ($lock->tunnelPid !== null ? ' (pid ' . $lock->tunnelPid . ')' : '')
                    . '. Re-run with --force to clear local state.'
                );
            }
        }

        $envBinder = new EnvBinder($projectRoot);
        $envBinder->restore($lock->envBackupPath);

        $composeFile = $this->resolveComposeFileFromProjectRoot($projectRoot);
        if ($composeFile !== null) {
            $this->composeOverride->remove($composeFile);
        }

        $connectLock->delete();

        if ($refreshContainers) {
            $config = $this->loadConfigFromProjectRoot($projectRoot);
            if ($config !== null) {
                $warnings = [];
                $this->refreshDatabaseContainers($config, $projectRoot, $warnings);
            }
        }

        return true;
    }

    public function shouldAutoConnectOnUp(NgramxConfig $config, bool $anonRequested): bool
    {
        $pm = $config->postmaclone;
        if ($pm === null || !$pm->hasShared()) {
            return false;
        }

        if ($anonRequested) {
            return true;
        }

        return $this->egressDetector->isDirectMode();
    }

    private function bindEnv(string $projectRoot, PostmacloneConnectLockData $lock): ?string
    {
        $binder = new EnvBinder($projectRoot);
        $cloneLock = new PostmacloneLockData(
            provider: 'shared',
            engine: $lock->engine,
            createdAt: $lock->connectedAt,
            expiresAt: $lock->connectedAt,
            host: $lock->host,
            port: $lock->port,
            database: $lock->database,
            username: $lock->username,
            password: $lock->password,
            databaseUrl: $lock->databaseUrl,
        );

        return $binder->bind($cloneLock);
    }

    private function probeConnection(ParsedConnectionUrl $parsed, ?int $localPort, string $engine): void
    {
        $host = $localPort !== null ? '127.0.0.1' : $parsed->host;
        $port = $localPort ?? $parsed->port;
        $url = $parsed->databaseUrl($host, $port);

        try {
            $pdo = $this->connections->fromUrl($url);
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            throw new PostmacloneException(
                'Database connection probe failed: ' . $e->getMessage()
            );
        }
    }

    private function requireSharedConfig(NgramxConfig $config): PostmacloneConfig
    {
        $pm = $config->postmaclone;
        if ($pm === null) {
            $message = 'Missing postmaclone: section in ngramx.yml';
            if ($config->postmacloneError !== null && $config->postmacloneError !== '') {
                $message .= ' (invalid postmaclone config was removed: ' . $config->postmacloneError . ')';
            }

            throw new PostmacloneException($message);
        }
        if (!$pm->hasShared()) {
            throw new PostmacloneException(
                'postmaclone.shared is required for connect. Add shared.database and anon credentials (see ngramx.example.yml).'
            );
        }

        return $pm;
    }

    private function resolveTunnelTarget(ConnectConfig $connectConfig): ServerTarget
    {
        $resolver = ServerTargetResolver::fromEnvironment();

        return $resolver->resolve([
            'host' => $connectConfig->tunnelHost,
            'ssh-user' => $connectConfig->tunnelUser,
            'port' => $connectConfig->tunnelPort !== null ? (string) $connectConfig->tunnelPort : null,
        ]);
    }

    private function applyComposeOverrides(NgramxConfig $config, PostmacloneConnectLockData $lock): void
    {
        $composeFile = $this->resolveComposeFile($config);
        if ($composeFile === null) {
            return;
        }

        // Written before `ngramx up` runs ComposeOverrideGenerator; the generator
        // re-reads postmaclone-connect.lock and merges the same DB_* overrides.
        $this->composeOverride->apply($composeFile, $lock);
    }

    private function resolveComposeFile(NgramxConfig $config): ?string
    {
        $composeFile = $config->docker->composeFile;

        return is_file($composeFile) ? $composeFile : null;
    }

    private function resolveComposeFileFromProjectRoot(string $projectRoot): ?string
    {
        $config = $this->loadConfigFromProjectRoot($projectRoot);

        return $config !== null ? $this->resolveComposeFile($config) : null;
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    public function refreshDatabaseContainers(NgramxConfig $config, string $projectRoot, array &$warnings = []): array
    {
        $composeFile = $this->resolveComposeFile($config);
        if ($composeFile === null || !$this->dockerCompose->isDockerRunning()) {
            return [];
        }

        $projectName = $this->composeProjectName($projectRoot);
        if (!$this->dockerCompose->isRunning($composeFile, $projectName)) {
            return [];
        }

        $refreshed = [];
        foreach ($this->composeOverride->databaseServiceNames($composeFile) as $service) {
            if (!$this->dockerCompose->isServiceRunning($composeFile, $service, $projectName)) {
                continue;
            }

            try {
                $this->dockerCompose->recreateService($composeFile, $service, $projectName);
                $refreshed[] = $service;
            } catch (\Throwable $e) {
                $warnings[] = "Could not recreate `$service`: {$e->getMessage()}. "
                    . 'Run `docker compose up -d --force-recreate ' . $service . '`.';
            }
        }

        return $refreshed;
    }

    private function loadConfigFromProjectRoot(string $projectRoot): ?NgramxConfig
    {
        $configPath = rtrim($projectRoot, '/') . '/ngramx.yml';
        if (!is_file($configPath)) {
            return null;
        }

        $cwd = getcwd();
        chdir($projectRoot);
        try {
            return (new ConfigLoader(new ConfigValidator()))->load($configPath);
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }

    private function composeProjectName(string $projectRoot): ?string
    {
        $namespace = (new LockFile($projectRoot))->read()?->namespace;

        return is_string($namespace) && $namespace !== '' ? $namespace : null;
    }
}

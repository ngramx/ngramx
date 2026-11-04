<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\DbConnectionConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryDatasetConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\SharedDbConfig;
use Ngramx\Config\Schema\Postmaclone\TargetConfig;
use Ngramx\Filesystem\AbsolutePath;
use Ngramx\Postmaclone\Anonymizer\LiveAnonymizer;
use Ngramx\Postmaclone\Anonymizer\SqlDialect;
use Ngramx\Postmaclone\Backup\BackupFreshnessChecker;
use Ngramx\Postmaclone\Backup\DatabaseDumper;
use Ngramx\Postmaclone\Backup\LocalBackupSource;
use Ngramx\Postmaclone\Backup\S3BackupSource;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Backup\S3ManifestReader;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Backup\S3ObjectUploader;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Connection\PdoDriverGuard;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\DatabaseWiper;
use Ngramx\Postmaclone\Restore\MysqlRestorer;
use Ngramx\Postmaclone\Restore\PostgresRestorer;
use Ngramx\Postmaclone\Target\DockerDbTarget;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use Ngramx\Postmaclone\Target\RemoteDbTarget;

/**
 * Factory-side produce: restore prod dump → anonymize → dump → publish artifact + latest.json.
 */
class PostmacloneProducer
{
    public function __construct(
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
        private readonly DatabaseDumper $dumper = new DatabaseDumper(),
        private readonly SharedDbRefresher $sharedRefresher = new SharedDbRefresher(),
        private readonly SharedDbPasswordRotator $passwordRotator = new SharedDbPasswordRotator(),
        private readonly RemoteDbConnectionResolver $connectionResolver = new RemoteDbConnectionResolver(),
        private readonly BackupFreshnessChecker $backupFreshness = new BackupFreshnessChecker(),
        private readonly SharedSlotPrivilegeFence $privilegeFence = new SharedSlotPrivilegeFence(),
    ) {
    }

    /**
     * @param (callable(string): void)|null $onProgress
     * @return array{dataset: string, artifact_key: string, size: int, sha256: string, warnings: list<string>, shared_refreshed: bool, password_rotated: bool}
     */
    public function produceDataset(FactoryDatasetConfig $dataset, string $workRoot, bool $strict = false, ?callable $onProgress = null): array
    {
        $engine = $dataset->engine ?? PostmacloneConfig::ENGINE_POSTGRES;
        if (!in_array($engine, [
            PostmacloneConfig::ENGINE_POSTGRES,
            PostmacloneConfig::ENGINE_MYSQL,
            PostmacloneConfig::ENGINE_MARIADB,
        ], true)) {
            throw new PostmacloneException("Dataset {$dataset->name}: unsupported engine {$engine}");
        }

        PdoDriverGuard::assertForEngine($engine);
        $cacheDir = rtrim($workRoot, '/') . '/.ngramx/cache';
        $faker = new FakerMethodResolver($dataset->locale, $dataset->seed);
        foreach ($dataset->tables as $table) {
            foreach ($table->columns as $column) {
                foreach ($column->fakerExpressions() as $expression) {
                    $faker->assertMethodExists($expression);
                }
            }
        }

        $this->progress($onProgress, 'Checking backup freshness');
        $this->backupFreshness->assertFresh($dataset);

        $artifactName = $dataset->publish->file ?? ($dataset->name . '_anon.sql.gz');
        $publishPath = rtrim((string) $dataset->publish->path, '/') . '/';
        $publishCredentials = new S3Credentials($dataset->publish->credentials);
        $manifestLocator = S3ObjectLocator::parse(
            $publishPath . 'latest.json',
            $dataset->publish->region,
            $dataset->publish->endpoint,
            $dataset->publish->pathStyle,
        );
        $previousManifest = (new S3ManifestReader($manifestLocator, $publishCredentials))->read();

        $pingPong = $dataset->shared?->pingPong === true;
        $promoted = false;
        $liveAfterPromote = null;
        $previousLive = null;
        if ($pingPong) {
            $names = $this->pingPongSlotNames($dataset);
            $slots = SharedSlotPicker::pick(
                $previousManifest,
                $names['scratch'],
                $names['shared'],
            );
            $this->progress(
                $onProgress,
                "Ping-pong live {$slots['live']}; building idle {$slots['idle']}",
            );
            $this->progress($onProgress, "Revoking consumer from idle {$slots['idle']}");
            $this->privilegeFence->revokeConsumer($engine, $dataset, $slots['idle']);
            $dataset = $this->withRemoteDatabase($dataset, $slots['idle']);
            $liveAfterPromote = $slots['idle'];
            $previousLive = $slots['live'];
        }

        $this->progress($onProgress, 'Downloading dump');
        $source = $this->buildBackupSource($dataset, $cacheDir, $onProgress);
        $dumpPath = $source->materialize();
        $this->progress($onProgress, 'Provisioning scratch database');
        $target = $this->provisionScratch($dataset, $engine);

        $scratchReleased = false;

        try {
            if ($target->provider === 'remote') {
                $this->progress($onProgress, 'Wiping scratch database');
                (new DatabaseWiper())->wipe($engine, $target);
            }

            $this->progress($onProgress, 'Restoring dump into scratch');
            $restorer = $engine === PostmacloneConfig::ENGINE_POSTGRES
                ? new PostgresRestorer(onProgress: $onProgress)
                : new MysqlRestorer(onProgress: $onProgress);
            $restorer->restore($dumpPath, $target);
            $this->progress($onProgress, 'Removing downloaded source dump');
            $source->cleanup(false);

            $pdoHost = (string) ($target->meta['host_bind_host'] ?? $target->host);
            $pdoPort = (int) ($target->meta['host_bind_port'] ?? $target->port);
            $pdo = $this->connections->fromParts(
                $engine === 'postgres' ? 'postgres' : 'mysql',
                $pdoHost,
                $pdoPort,
                $target->database,
                $target->username,
                $target->password,
            );

            $this->progress($onProgress, 'Anonymizing scratch database');
            $anonymizer = new LiveAnonymizer(
                $faker,
                new SqlDialect($engine),
                $dataset->testPassword,
                strict: $strict,
                onProgress: $onProgress,
            );
            $anonymizer->anonymize($pdo, $dataset->tables);
            $warnings = $anonymizer->warnings();

            if ($pingPong) {
                $promoted = true;
            }

            $artifactLocal = rtrim($cacheDir, '/') . '/' . $artifactName;
            $connUrl = $this->dumpConnectionUrl($engine, $pdoHost, $pdoPort, $target);
            $this->progress($onProgress, 'Dumping anonymized database');
            $artifactLocal = $this->dumper->dump(
                $connUrl,
                $engine === 'mariadb' ? 'mysql' : $engine,
                $artifactLocal,
                $dataset->includeTables,
                $dataset->excludeTables,
                gzip: true,
                onProgress: $onProgress,
            );

            $size = (int) filesize($artifactLocal);
            $sha256 = hash_file('sha256', $artifactLocal);
            if (!is_string($sha256)) {
                throw new PostmacloneException('Failed to hash published artifact');
            }

            $objectPath = $publishPath . $artifactName;
            $locator = S3ObjectLocator::parse(
                $objectPath,
                $dataset->publish->region,
                $dataset->publish->endpoint,
                $dataset->publish->pathStyle,
            );

            $this->progress($onProgress, 'Uploading artifact');
            $uploader = new S3ObjectUploader($locator, $publishCredentials);
            $uploader->putFile($artifactLocal);

            $sharedRefreshed = false;
            $passwordRotated = false;
            $passwordRotatedAt = null;
            if (($dataset->shared?->isConfigured() ?? false) && !$pingPong) {
                $this->progress($onProgress, 'Destroying scratch database before shared refresh');
                $this->destroyScratch($dataset, $target, $engine);
                $scratchReleased = true;

                $this->progress($onProgress, 'Refreshing shared hosted database');
                $this->sharedRefresher->refresh($engine, $dataset->shared, $artifactLocal);
                $sharedRefreshed = true;
            }

            if ($dataset->shared?->isConfigured() ?? false) {
                $rotationStore = CredentialRotationStateStore::forPublish($dataset->publish);
                $credentialKey = $this->passwordRotator->credentialKey($dataset->shared);
                $lastRotatedAt = $credentialKey !== null
                    ? $rotationStore->lastRotatedAt($credentialKey)
                    : null;
                if ($lastRotatedAt === null && $previousManifest !== null) {
                    $legacy = $previousManifest['shared_password_rotated_at'] ?? null;
                    if (is_string($legacy) && $legacy !== '') {
                        $lastRotatedAt = $legacy;
                    }
                }

                if (!SharedDbPasswordRotator::environmentAllowsRotation()) {
                    $this->progress($onProgress, 'Skipping password rotation (ROTATE_DATABASE_PASSWORD is off)');
                }
                $rotation = $this->passwordRotator->rotateIfDue($engine, $dataset->shared, $lastRotatedAt);
                $passwordRotated = $rotation['rotated'];
                $passwordRotatedAt = $rotation['rotated_at'];
                if ($credentialKey !== null && $passwordRotatedAt !== null) {
                    $rotationStore->recordRotatedAt($credentialKey, $passwordRotatedAt);
                }
            }

            if ($liveAfterPromote !== null) {
                $this->progress($onProgress, "Granting consumer on {$liveAfterPromote}");
                $this->privilegeFence->grantConsumer($engine, $dataset, $liveAfterPromote);
                $this->progress($onProgress, "Promoting {$liveAfterPromote} to shared");
            }
            $this->writeLatestManifest(
                $manifestLocator,
                $publishCredentials,
                $this->manifestBody(
                    $dataset,
                    $engine,
                    $artifactName,
                    $size,
                    $sha256,
                    $liveAfterPromote,
                    $passwordRotatedAt,
                ),
            );
            if (is_string($previousLive) && $previousLive !== $liveAfterPromote) {
                $this->progress($onProgress, "Revoking consumer from previous live {$previousLive}");
                $this->privilegeFence->revokeConsumer($engine, $dataset, $previousLive);
            }

            $source->cleanup(false);
            @unlink($artifactLocal);

            return [
                'dataset' => $dataset->name,
                'artifact_key' => $locator->key,
                'size' => $size,
                'sha256' => $sha256,
                'warnings' => $warnings,
                'shared_refreshed' => $sharedRefreshed,
                'password_rotated' => $passwordRotated,
            ];
        } finally {
            if (!$scratchReleased && !$promoted) {
                $this->progress($onProgress, 'Destroying scratch database');
                $this->destroyScratch($dataset, $target, $engine);
            }
        }
    }

    /**
     * Publish a dump that produce already wrote locally (after a failed Spaces PUT)
     * and refresh shared. Skips download, scratch, anonymize, and dump.
     *
     * @param (callable(string): void)|null $onProgress
     * @return array{dataset: string, artifact_key: string, size: int, sha256: string, warnings: list<string>, shared_refreshed: bool, password_rotated: bool}
     */
    public function publishExistingArtifact(
        FactoryDatasetConfig $dataset,
        string $artifactLocal,
        ?callable $onProgress = null,
    ): array {
        $engine = $dataset->engine ?? PostmacloneConfig::ENGINE_POSTGRES;
        if (!is_file($artifactLocal)) {
            throw new PostmacloneException("Artifact not found: {$artifactLocal}");
        }

        $artifactName = $dataset->publish->file ?? ($dataset->name . '_anon.sql.gz');
        $size = (int) filesize($artifactLocal);
        $sha256 = hash_file('sha256', $artifactLocal);
        if (!is_string($sha256)) {
            throw new PostmacloneException('Failed to hash published artifact');
        }

        $publishPath = rtrim((string) $dataset->publish->path, '/') . '/';
        $locator = S3ObjectLocator::parse(
            $publishPath . $artifactName,
            $dataset->publish->region,
            $dataset->publish->endpoint,
            $dataset->publish->pathStyle,
        );
        $publishCredentials = new S3Credentials($dataset->publish->credentials);
        $manifestLocator = S3ObjectLocator::parse(
            $publishPath . 'latest.json',
            $dataset->publish->region,
            $dataset->publish->endpoint,
            $dataset->publish->pathStyle,
        );
        $previousManifest = (new S3ManifestReader($manifestLocator, $publishCredentials))->read();

        $this->progress($onProgress, 'Uploading artifact');
        $uploader = new S3ObjectUploader($locator, $publishCredentials);
        $uploader->putFile($artifactLocal);

        $sharedRefreshed = false;
        $passwordRotated = false;
        $passwordRotatedAt = null;
        $liveAfterPromote = null;
        $previousLive = null;
        if ($dataset->shared?->isConfigured() ?? false) {
            if ($dataset->shared->pingPong) {
                $existingLive = SharedSlotPicker::manifestLive($previousManifest);
                if ($existingLive !== null) {
                    $this->progress(
                        $onProgress,
                        "Ping-pong keeping live {$existingLive}; skipping idle restore",
                    );
                    $liveAfterPromote = $existingLive;
                } else {
                    $names = $this->pingPongSlotNames($dataset);
                    $slots = SharedSlotPicker::pick(
                        $previousManifest,
                        $names['scratch'],
                        $names['shared'],
                    );
                    $this->progress(
                        $onProgress,
                        "Ping-pong live {$slots['live']}; loading idle {$slots['idle']}",
                    );
                    $this->progress($onProgress, "Revoking consumer from idle {$slots['idle']}");
                    $this->privilegeFence->revokeConsumer($engine, $dataset, $slots['idle']);
                    $remote = $dataset->target->remote;
                    if ($remote === null) {
                        throw new PostmacloneException('shared.ping_pong requires target.remote');
                    }
                    $idleShared = new SharedDbConfig(
                        connection: new DbConnectionConfig(
                            url: $remote->url,
                            host: $remote->host,
                            port: $remote->port,
                            database: $slots['idle'],
                            credentials: $remote->credentials,
                        ),
                    );
                    $this->sharedRefresher->refresh($engine, $idleShared, $artifactLocal);
                    $this->progress($onProgress, "Granting consumer on {$slots['idle']}");
                    $this->privilegeFence->grantConsumer($engine, $dataset, $slots['idle']);
                    $liveAfterPromote = $slots['idle'];
                    $previousLive = $slots['live'];
                }
            } else {
                $this->progress($onProgress, 'Refreshing shared hosted database');
                $this->sharedRefresher->refresh($engine, $dataset->shared, $artifactLocal);
                $sharedRefreshed = true;
            }

            $rotationStore = CredentialRotationStateStore::forPublish($dataset->publish);
            $credentialKey = $this->passwordRotator->credentialKey($dataset->shared);
            $lastRotatedAt = $credentialKey !== null
                ? $rotationStore->lastRotatedAt($credentialKey)
                : null;
            if ($lastRotatedAt === null && $previousManifest !== null) {
                $legacy = $previousManifest['shared_password_rotated_at'] ?? null;
                if (is_string($legacy) && $legacy !== '') {
                    $lastRotatedAt = $legacy;
                }
            }

            if (!SharedDbPasswordRotator::environmentAllowsRotation()) {
                $this->progress($onProgress, 'Skipping password rotation (ROTATE_DATABASE_PASSWORD is off)');
            }
            $rotation = $this->passwordRotator->rotateIfDue($engine, $dataset->shared, $lastRotatedAt);
            $passwordRotated = $rotation['rotated'];
            $passwordRotatedAt = $rotation['rotated_at'];
            if ($credentialKey !== null && $passwordRotatedAt !== null) {
                $rotationStore->recordRotatedAt($credentialKey, $passwordRotatedAt);
            }
        }

        $this->writeLatestManifest(
            $manifestLocator,
            $publishCredentials,
            $this->manifestBody(
                $dataset,
                $engine,
                $artifactName,
                $size,
                $sha256,
                $liveAfterPromote,
                $passwordRotatedAt,
            ),
        );
        if (is_string($previousLive) && $previousLive !== $liveAfterPromote) {
            $this->progress($onProgress, "Revoking consumer from previous live {$previousLive}");
            $this->privilegeFence->revokeConsumer($engine, $dataset, $previousLive);
        }

        return [
            'dataset' => $dataset->name,
            'artifact_key' => $locator->key,
            'size' => $size,
            'sha256' => $sha256,
            'warnings' => [],
            'shared_refreshed' => $sharedRefreshed,
            'password_rotated' => $passwordRotated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestBody(
        FactoryDatasetConfig $dataset,
        string $engine,
        string $artifactName,
        int $size,
        string $sha256,
        ?string $sharedDatabase,
        ?string $passwordRotatedAt,
    ): array {
        $manifest = [
            'dataset' => $dataset->name,
            'engine' => $engine,
            'file' => $artifactName,
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'size' => $size,
            'sha256' => $sha256,
            'include_tables' => $dataset->includeTables,
            'exclude_tables' => $dataset->excludeTables,
        ];
        if ($sharedDatabase !== null && $sharedDatabase !== '') {
            $manifest['shared_database'] = $sharedDatabase;
        }
        if ($passwordRotatedAt !== null) {
            $manifest['shared_password_rotated_at'] = $passwordRotatedAt;
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeLatestManifest(
        S3ObjectLocator $locator,
        S3Credentials $credentials,
        array $manifest,
    ): void {
        (new S3ObjectUploader($locator, $credentials))
            ->putBody((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{scratch: string, shared: string}
     */
    private function pingPongSlotNames(FactoryDatasetConfig $dataset): array
    {
        $remote = $dataset->target->remote;
        $sharedConnection = $dataset->shared?->connection;

        return [
            'scratch' => $remote !== null ? (string) ($remote->database ?? '') : '',
            'shared' => $sharedConnection !== null ? (string) ($sharedConnection->database ?? '') : '',
        ];
    }

    private function withRemoteDatabase(FactoryDatasetConfig $dataset, string $database): FactoryDatasetConfig
    {
        $remote = $dataset->target->remote;
        if ($remote === null) {
            throw new PostmacloneException('shared.ping_pong requires target.remote');
        }

        $target = $dataset->target;

        return new FactoryDatasetConfig(
            name: $dataset->name,
            engine: $dataset->engine,
            locale: $dataset->locale,
            seed: $dataset->seed,
            backup: $dataset->backup,
            publish: $dataset->publish,
            target: new TargetConfig(
                provider: $target->provider,
                ttlHours: $target->ttlHours,
                neonProjectId: $target->neonProjectId,
                neonRegionId: $target->neonRegionId,
                dockerImage: $target->dockerImage,
                dockerPort: $target->dockerPort,
                remoteUrl: $target->remoteUrl,
                remote: new DbConnectionConfig(
                    url: $remote->url,
                    host: $remote->host,
                    port: $remote->port,
                    database: $database,
                    credentials: $remote->credentials,
                ),
                remoteThresholdBytes: $target->remoteThresholdBytes,
            ),
            shared: $dataset->shared,
            tables: $dataset->tables,
            includeTables: $dataset->includeTables,
            excludeTables: $dataset->excludeTables,
            testPassword: $dataset->testPassword,
        );
    }

    /**
     * @param (callable(string): void)|null $onProgress
     */
    private function progress(?callable $onProgress, string $message): void
    {
        if ($onProgress !== null) {
            $onProgress($message);
        }
    }

    /**
     * @param (callable(string): void)|null $onProgress
     */
    private function buildBackupSource(FactoryDatasetConfig $dataset, string $cacheDir, ?callable $onProgress = null): LocalBackupSource|S3BackupSource
    {
        $backup = $dataset->backup;
        if ($backup->source === BackupConfig::SOURCE_S3 || (
            is_string($backup->path) && (
                str_starts_with($backup->path, 's3://')
                || str_starts_with($backup->path, 'spaces://')
            )
        )) {
            if ($backup->path === null) {
                throw new PostmacloneException("Dataset {$dataset->name}: backup.path is required");
            }

            return new S3BackupSource(
                S3ObjectLocator::parse(
                    $backup->path,
                    $backup->region,
                    $backup->endpoint,
                    $backup->pathStyle,
                ),
                $cacheDir,
                file: $backup->file,
                credentials: new S3Credentials($backup->credentials),
                onProgress: $onProgress,
            );
        }

        if ($backup->path === null || $backup->path === '') {
            throw new PostmacloneException("Dataset {$dataset->name}: backup.path is required");
        }

        $cwd = getcwd();
        $path = AbsolutePath::resolve(is_string($cwd) ? $cwd : '', $backup->path);

        return new LocalBackupSource($path);
    }

    private function provisionScratch(FactoryDatasetConfig $dataset, string $engine): EphemeralTarget
    {
        $provider = $dataset->target->provider;
        if ($provider === TargetConfig::PROVIDER_AUTO) {
            $provider = TargetConfig::PROVIDER_DOCKER;
        }

        if ($provider === TargetConfig::PROVIDER_REMOTE) {
            $url = $this->connectionResolver->resolve(
                $dataset->target->remote,
                $engine,
                $dataset->target->remoteUrl,
            );

            return (new RemoteDbTarget($url))->provision($engine, $dataset->target->ttlHours);
        }

        return (new DockerDbTarget(
            image: $dataset->target->dockerImage,
            hostPort: $dataset->target->dockerPort,
            composeFile: null,
            primaryService: null,
        ))->provision($engine, $dataset->target->ttlHours);
    }

    private function destroyScratch(FactoryDatasetConfig $dataset, EphemeralTarget $target, string $engine): void
    {
        $lock = new PostmacloneLockData(
            provider: $target->provider,
            engine: $engine,
            createdAt: date('c'),
            expiresAt: $target->expiresAt,
            host: $target->host,
            port: $target->port,
            database: $target->database,
            username: $target->username,
            password: $target->password,
            databaseUrl: $target->databaseUrl,
            providerMeta: $target->meta,
        );

        try {
            if ($target->provider === 'remote') {
                (new RemoteDbTarget($target->databaseUrl))->destroy($lock);
            } else {
                (new DockerDbTarget(
                    image: $dataset->target->dockerImage,
                    hostPort: $dataset->target->dockerPort,
                    composeFile: null,
                    primaryService: null,
                ))->destroy($lock);
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function dumpConnectionUrl(string $engine, string $host, int $port, EphemeralTarget $target): string
    {
        $user = rawurlencode($target->username);
        $pass = rawurlencode($target->password);
        $db = rawurlencode($target->database);
        if ($engine === 'postgres') {
            return "postgresql://{$user}:{$pass}@{$host}:{$port}/{$db}";
        }

        return "mysql://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryDatasetConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\TargetConfig;
use Ngramx\Postmaclone\Anonymizer\LiveAnonymizer;
use Ngramx\Postmaclone\Anonymizer\SqlDialect;
use Ngramx\Postmaclone\Backup\DatabaseDumper;
use Ngramx\Postmaclone\Backup\LocalBackupSource;
use Ngramx\Postmaclone\Backup\S3BackupSource;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Backup\S3ObjectUploader;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Connection\PdoDriverGuard;
use Ngramx\Postmaclone\Exception\PostmacloneException;
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
    ) {
    }

    /**
     * @return array{dataset: string, artifact_key: string, size: int, sha256: string, warnings: list<string>}
     */
    public function produceDataset(FactoryDatasetConfig $dataset, string $workRoot, bool $strict = false): array
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
                $faker->assertMethodExists($column->faker);
            }
        }

        $source = $this->buildBackupSource($dataset, $cacheDir);
        $dumpPath = $source->materialize();
        $target = $this->provisionScratch($dataset, $engine);

        try {
            $restorer = $engine === PostmacloneConfig::ENGINE_POSTGRES
                ? new PostgresRestorer()
                : new MysqlRestorer();
            $restorer->restore($dumpPath, $target);

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

            $anonymizer = new LiveAnonymizer(
                $faker,
                new SqlDialect($engine),
                $dataset->testPassword,
                strict: $strict,
            );
            $anonymizer->anonymize($pdo, $dataset->tables);
            $warnings = $anonymizer->warnings();

            $artifactName = $dataset->publish->file ?? ($dataset->name . '_anon.sql.gz');
            $artifactLocal = rtrim($cacheDir, '/') . '/' . $artifactName;
            $connUrl = $this->dumpConnectionUrl($engine, $pdoHost, $pdoPort, $target);
            $artifactLocal = $this->dumper->dump(
                $connUrl,
                $engine === 'mariadb' ? 'mysql' : $engine,
                $artifactLocal,
                $dataset->includeTables,
                $dataset->excludeTables,
                gzip: true,
            );

            $size = (int) filesize($artifactLocal);
            $sha256 = hash_file('sha256', $artifactLocal);
            if (!is_string($sha256)) {
                throw new PostmacloneException('Failed to hash published artifact');
            }

            $publishPath = rtrim((string) $dataset->publish->path, '/') . '/';
            $objectPath = $publishPath . $artifactName;
            $locator = S3ObjectLocator::parse(
                $objectPath,
                $dataset->publish->region,
                $dataset->publish->endpoint,
                $dataset->publish->pathStyle,
            );
            $uploader = new S3ObjectUploader(
                $locator,
                new S3Credentials($dataset->publish->credentials),
            );
            $uploader->putFile($artifactLocal);

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
            $manifestLocator = S3ObjectLocator::parse(
                $publishPath . 'latest.json',
                $dataset->publish->region,
                $dataset->publish->endpoint,
                $dataset->publish->pathStyle,
            );
            (new S3ObjectUploader(
                $manifestLocator,
                new S3Credentials($dataset->publish->credentials),
            ))->putBody((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $source->cleanup(false);
            @unlink($artifactLocal);

            return [
                'dataset' => $dataset->name,
                'artifact_key' => $locator->key,
                'size' => $size,
                'sha256' => $sha256,
                'warnings' => $warnings,
            ];
        } finally {
            $this->destroyScratch($dataset, $target, $engine);
        }
    }

    private function buildBackupSource(FactoryDatasetConfig $dataset, string $cacheDir): LocalBackupSource|S3BackupSource
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
            );
        }

        if ($backup->path === null || $backup->path === '') {
            throw new PostmacloneException("Dataset {$dataset->name}: backup.path is required");
        }

        $path = $backup->path;
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . ltrim($path, './');
        }

        return new LocalBackupSource($path);
    }

    private function provisionScratch(FactoryDatasetConfig $dataset, string $engine): EphemeralTarget
    {
        $provider = $dataset->target->provider;
        if ($provider === TargetConfig::PROVIDER_AUTO) {
            $provider = TargetConfig::PROVIDER_DOCKER;
        }

        if ($provider === TargetConfig::PROVIDER_REMOTE) {
            return (new RemoteDbTarget($dataset->target->remoteUrl))->provision($engine, $dataset->target->ttlHours);
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
                (new RemoteDbTarget($dataset->target->remoteUrl))->destroy($lock);
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

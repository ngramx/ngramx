<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\TargetConfig;
use Ngramx\Postmaclone\Anonymizer\DumpInsertParser;
use Ngramx\Postmaclone\Anonymizer\LiveAnonymizer;
use Ngramx\Postmaclone\Anonymizer\SqlDialect;
use Ngramx\Postmaclone\Anonymizer\SqlEmitter;
use Ngramx\Postmaclone\Backup\BackupSourceInterface;
use Ngramx\Postmaclone\Backup\ConnectionStringSource;
use Ngramx\Postmaclone\Backup\LocalBackupSource;
use Ngramx\Postmaclone\Backup\S3BackupSource;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Connection\PdoDriverGuard;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\MysqlRestorer;
use Ngramx\Postmaclone\Restore\PostgresRestorer;
use Ngramx\Postmaclone\Target\ComposeDbServiceSwitcher;
use Ngramx\Postmaclone\Target\DockerDbTarget;
use Ngramx\Postmaclone\Target\EphemeralTargetInterface;
use Ngramx\Postmaclone\Target\NeonTarget;

class PostmacloneService
{
    public function __construct(
        private readonly FromResolver $fromResolver = new FromResolver(),
        private readonly EngineDetector $engineDetector = new EngineDetector(),
        private readonly HostGuard $hostGuard = new HostGuard(),
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
        private readonly DumpInsertParser $dumpParser = new DumpInsertParser(),
        private readonly ComposeDbServiceSwitcher $dbSwitcher = new ComposeDbServiceSwitcher(),
    ) {
    }

    public function resolveEngine(NgramxConfig $config, ?FromSource $from = null): string
    {
        $pm = $this->requireConfig($config);

        return $this->engineDetector->detect(
            $pm->engine,
            $config->docker->composeFile,
            $from?->engineHint
        );
    }

    public function engineMismatchWarning(NgramxConfig $config): ?string
    {
        $pm = $config->postmaclone;
        if ($pm === null) {
            return null;
        }

        return $this->engineDetector->detectionMismatch($pm->engine, $config->docker->composeFile);
    }

    public function resolveFrom(?string $fromOption, NgramxConfig $config): ?FromSource
    {
        if ($fromOption !== null && $fromOption !== '') {
            return $this->fromResolver->resolve($fromOption);
        }

        return null;
    }

    public function buildBackupSource(
        NgramxConfig $config,
        string $projectRoot,
        string $engine,
        ?FromSource $from,
    ): BackupSourceInterface {
        $pm = $this->requireConfig($config);
        $cacheDir = rtrim($projectRoot, '/') . '/.ngramx/cache';

        if ($from !== null) {
            if ($from->isPath()) {
                $path = $this->absolutePath($from->value, $projectRoot);

                return new LocalBackupSource($path);
            }
            if ($from->isConnection()) {
                $this->hostGuard->assertAllowed($from->value, $pm->denyHosts);

                return new ConnectionStringSource($from->value, $engine, $cacheDir);
            }
            if ($from->isS3()) {
                $locator = S3ObjectLocator::parse(
                    $from->value,
                    $pm->backup->region,
                    $pm->backup->endpoint,
                    $pm->backup->pathStyle,
                );

                return new S3BackupSource(
                    $locator,
                    $cacheDir,
                    credentials: new S3Credentials($pm->backup->credentials),
                );
            }
        }

        $backup = $pm->backup;
        if ($backup->source === BackupConfig::SOURCE_S3) {
            if ($backup->path === null) {
                throw new PostmacloneException('postmaclone.backup.path is required for S3 sources');
            }
            $locator = S3ObjectLocator::parse(
                $backup->path,
                $backup->region,
                $backup->endpoint,
                $backup->pathStyle,
            );

            return new S3BackupSource(
                $locator,
                $cacheDir,
                file: $backup->file,
                credentials: new S3Credentials($backup->credentials),
            );
        }

        if ($backup->path === null || $backup->path === '') {
            throw new PostmacloneException(
                'No backup source. Pass --from <dump|connection|s3://...> or set postmaclone.backup.path'
            );
        }

        return new LocalBackupSource($this->absolutePath($backup->path, $projectRoot));
    }

    /**
     * @return array{sql: string, warnings: list<string>}
     */
    public function emitSql(NgramxConfig $config, string $projectRoot, FromSource $from, bool $strict = false): array
    {
        $pm = $this->requireConfig($config);
        $engine = $this->resolveEngine($config, $from);
        $dialect = new SqlDialect($engine);
        $faker = new FakerMethodResolver($pm->locale, $pm->seed);
        $this->assertFakerMethods($faker, $pm);

        $emitter = new SqlEmitter($faker, $dialect, $pm->testPassword);
        $warnings = [];

        if ($from->isConnection()) {
            $this->hostGuard->assertAllowed($from->value, $pm->denyHosts);
            $pdo = $this->connections->fromUrl($from->value, readOnly: true);
            $rowsByTable = [];
            foreach ($pm->tables as $name => $table) {
                $pk = $table->primaryKey ?? 'id';
                $cols = array_unique(array_merge([$pk], array_keys($table->columns)));
                $quoted = array_map(fn ($c) => $dialect->quoteIdentifier($c), $cols);
                try {
                    $sql = 'SELECT ' . implode(', ', $quoted) . ' FROM ' . $dialect->quoteIdentifier($name);
                    $stmt = $pdo->query($sql);
                    /** @var list<array<string, mixed>> $rows */
                    $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
                    $rowsByTable[$name] = $rows;
                } catch (\Throwable $e) {
                    $msg = "Failed reading {$name}: {$e->getMessage()}";
                    if ($strict) {
                        throw new PostmacloneException($msg, 0, $e);
                    }
                    $warnings[] = $msg;
                    $rowsByTable[$name] = [];
                }
            }

            return ['sql' => $emitter->emit($pm->tables, $rowsByTable), 'warnings' => $warnings];
        }

        if ($from->isPath()) {
            $path = $this->absolutePath($from->value, $projectRoot);
            if (!is_file($path)) {
                throw new PostmacloneException("Dump file not found: {$path}");
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw new PostmacloneException("Failed to read dump: {$path}");
            }
            $rowsByTable = $this->dumpParser->parse($content, array_keys($pm->tables));

            return ['sql' => $emitter->emit($pm->tables, $rowsByTable), 'warnings' => $warnings];
        }

        // S3: download then parse
        $source = $this->buildBackupSource($config, $projectRoot, $engine, $from);
        $path = $source->materialize();
        try {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new PostmacloneException("Failed to read downloaded dump: {$path}");
            }
            $rowsByTable = $this->dumpParser->parse($content, array_keys($pm->tables));

            return ['sql' => $emitter->emit($pm->tables, $rowsByTable), 'warnings' => $warnings];
        } finally {
            $source->cleanup(false);
        }
    }

    /**
     * @return array{lock: PostmacloneLockData, warnings: list<string>}
     */
    public function create(
        NgramxConfig $config,
        string $projectRoot,
        ?FromSource $from,
        bool $replace,
        bool $keepDownload,
        bool $strict,
        ?string $label,
        bool $bindEnv = true,
    ): array {
        $pm = $this->requireConfig($config);
        $lockFile = new PostmacloneLock($projectRoot);
        $envBinder = new EnvBinder($projectRoot);

        if ($lockFile->exists()) {
            if (!$replace) {
                throw new PostmacloneException(
                    'A Post Maclone clone already exists. Run `ngramx postmaclone status`, '
                    . '`ngramx postmaclone down`, or `ngramx postmaclone --replace`.'
                );
            }
            $this->destroy($config, $projectRoot, force: true);
        }

        $engine = $this->resolveEngine($config, $from);
        PdoDriverGuard::assertForEngine($engine);
        $faker = new FakerMethodResolver($pm->locale, $pm->seed);
        $this->assertFakerMethods($faker, $pm);

        $source = $this->buildBackupSource($config, $projectRoot, $engine, $from);
        if ($from?->isConnection()) {
            // warning handled by caller
        }

        $dumpPath = $source->materialize();
        $target = $this->buildTarget($config, $pm, $engine)->provision($engine, $pm->target->ttlHours);

        try {
            $restorer = $engine === PostmacloneConfig::ENGINE_POSTGRES
                ? new PostgresRestorer()
                : new MysqlRestorer();
            $restorer->restore($dumpPath, $target);

            // Anonymize from the host via published port; app .env uses compose-network hostname.
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
                $pm->testPassword,
                strict: $strict,
            );
            $anonymizer->anonymize($pdo, $pm->tables);
            $warnings = $anonymizer->warnings();

            $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
            $lock = new PostmacloneLockData(
                provider: $target->provider,
                engine: $engine,
                createdAt: $createdAt,
                expiresAt: $target->expiresAt,
                host: $target->host,
                port: $target->port,
                database: $target->database,
                username: $target->username,
                password: $target->password,
                databaseUrl: $target->databaseUrl,
                label: $label,
                providerMeta: $target->meta,
                downloadPath: $keepDownload ? $dumpPath : null,
            );

            if ($bindEnv) {
                $backup = $envBinder->bind($lock);
                $lock = new PostmacloneLockData(
                    provider: $lock->provider,
                    engine: $lock->engine,
                    createdAt: $lock->createdAt,
                    expiresAt: $lock->expiresAt,
                    host: $lock->host,
                    port: $lock->port,
                    database: $lock->database,
                    username: $lock->username,
                    password: $lock->password,
                    databaseUrl: $lock->databaseUrl,
                    envBackupPath: $backup,
                    label: $lock->label,
                    providerMeta: $lock->providerMeta,
                    downloadPath: $lock->downloadPath,
                );
                // Mounted .env credentials + network alias `db` — clear Laravel config cache.
                if ($lock->provider === 'docker') {
                    $this->dbSwitcher->refreshAppConfig(
                        $config->docker->composeFile,
                        $config->docker->primaryService,
                    );
                }
            }

            $lockFile->write($lock);
            $source->cleanup($keepDownload);

            return ['lock' => $lock, 'warnings' => $warnings];
        } catch (\Throwable $e) {
            try {
                $this->buildTarget($config, $pm, $engine)->destroy(new PostmacloneLockData(
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
                ));
            } catch (\Throwable) {
                // best-effort cleanup
            }
            // Keep the downloaded dump for doctor / retry without re-downloading.
            $source->cleanup(true);
            $message = $e instanceof PostmacloneException ? $e->getMessage() : $e->getMessage();
            $message .= "\nDump kept under .ngramx/cache — run: ngramx postmaclone doctor";

            throw new PostmacloneException($message, 0, $e instanceof \Throwable ? $e : null);
        }
    }

    public function destroy(NgramxConfig $config, string $projectRoot, bool $force = false): bool
    {
        $lockFile = new PostmacloneLock($projectRoot);
        $envBinder = new EnvBinder($projectRoot);
        $lock = $lockFile->read();

        if ($lock === null) {
            // Still try to restore orphan .env backup
            $envBinder->restore();

            return false;
        }

        $envBinder->restore($lock->envBackupPath);

        try {
            $pm = $config->postmaclone ?? new PostmacloneConfig();
            $this->targetFromLock($lock, $config, $pm)->destroy($lock);
        } catch (\Throwable $e) {
            if (!$force) {
                throw $e instanceof PostmacloneException ? $e : new PostmacloneException($e->getMessage(), 0, $e);
            }
        }

        if ($lock->provider === 'docker') {
            $this->dbSwitcher->refreshAppConfig(
                $config->docker->composeFile,
                $config->docker->primaryService,
            );
        }

        if ($lock->downloadPath && is_file($lock->downloadPath)) {
            @unlink($lock->downloadPath);
        }

        $lockFile->delete();

        return true;
    }

    public function status(string $projectRoot): ?PostmacloneLockData
    {
        return (new PostmacloneLock($projectRoot))->read();
    }

    private function buildTarget(NgramxConfig $config, PostmacloneConfig $pm, string $engine): EphemeralTargetInterface
    {
        $provider = $pm->target->provider;
        if ($provider === TargetConfig::PROVIDER_AUTO) {
            $provider = $engine === PostmacloneConfig::ENGINE_POSTGRES
                ? TargetConfig::PROVIDER_NEON
                : TargetConfig::PROVIDER_DOCKER;
        }

        if ($provider === TargetConfig::PROVIDER_NEON) {
            return new NeonTarget($pm->target->neonProjectId, $pm->target->neonRegionId);
        }

        return new DockerDbTarget(
            image: $pm->target->dockerImage,
            hostPort: $pm->target->dockerPort,
            composeFile: $config->docker->composeFile,
            primaryService: $config->docker->primaryService,
        );
    }

    private function targetFromLock(PostmacloneLockData $lock, NgramxConfig $config, PostmacloneConfig $pm): EphemeralTargetInterface
    {
        if ($lock->provider === 'neon') {
            return new NeonTarget($pm->target->neonProjectId, $pm->target->neonRegionId);
        }

        return new DockerDbTarget(
            image: $pm->target->dockerImage,
            hostPort: $pm->target->dockerPort,
            composeFile: $config->docker->composeFile,
            primaryService: $config->docker->primaryService,
        );
    }


    private function requireConfig(NgramxConfig $config): PostmacloneConfig
    {
        if ($config->postmaclone === null) {
            throw new PostmacloneException(
                'Missing postmaclone: section in ngramx.yml. Add tables and backup/target settings first.'
            );
        }

        return $config->postmaclone;
    }

    private function assertFakerMethods(FakerMethodResolver $faker, PostmacloneConfig $pm): void
    {
        foreach ($pm->tables as $table) {
            foreach ($table->columns as $column) {
                $faker->assertMethodExists($column->faker);
            }
        }
    }

    private function absolutePath(string $path, string $projectRoot): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($projectRoot, '/') . '/' . ltrim($path, './');
    }

}

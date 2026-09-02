<?php

declare(strict_types=1);

namespace Ngramx\Config;

use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\AgentsConfig;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\EndpointConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\BackupCredentialsConfig;
use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\DbConnectionConfig;
use Ngramx\Config\Schema\Postmaclone\DbCredentialsConfig;
use Ngramx\Config\Schema\Postmaclone\EngineConnectionsConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryDatasetConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\PrebuiltConfig;
use Ngramx\Config\Schema\Postmaclone\PublishConfig;
use Ngramx\Config\Schema\Postmaclone\SharedDbConfig;
use Ngramx\Config\Schema\Postmaclone\TableRule;
use Ngramx\Config\Schema\Postmaclone\TargetConfig;
use Ngramx\Config\Schema\SecretsConfig;
use Ngramx\Config\Schema\SecretsProviderConfig;
use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Config\Validator\ConfigValidator;
use Ngramx\Filesystem\AbsolutePath;
use Symfony\Component\Yaml\Yaml;

class ConfigLoader
{
    private const DEFAULT_N8N_WORKFLOWS_DIR = './.n8n';

    public function __construct(
        private readonly ConfigValidator $validator,
    ) {
    }

    /**
     * @throws ConfigException
     */
    public function load(string $path = 'ngramx.yml'): NgramxConfig
    {
        $filePath = $this->resolveConfigPath($path);

        if (!file_exists($filePath)) {
            throw new ConfigException("Configuration file not found: $filePath");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ConfigException("Failed to read configuration file: $filePath");
        }

        try {
            $config = Yaml::parse($content);
        } catch (\Exception $e) {
            throw new ConfigException("Failed to parse YAML: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($config)) {
            throw new ConfigException('Invalid configuration: expected array, got ' . gettype($config));
        }

        $this->validator->validate($config);

        return $this->buildConfig($config, dirname($filePath));
    }

    /**
     * Find ngramx.yml in current or parent directories
     *
     * @throws ConfigException
     */
    public function findConfigFile(): string
    {
        $currentDir = getcwd();
        if ($currentDir === false) {
            throw new ConfigException('Failed to get current working directory');
        }

        $maxDepth = 10;
        $depth = 0;

        while ($depth < $maxDepth) {
            $configPath = $currentDir . '/ngramx.yml';
            if (file_exists($configPath)) {
                return $configPath;
            }

            // Stop at the repository boundary. A linked git worktree lives
            // *inside* its parent repo (e.g. <repo>/.ngramx/worktrees/<name>)
            // and its root carries a `.git` pointer file. Without this guard a
            // worktree whose branch does not track ngramx.yml would silently
            // keep walking up and inherit the PARENT repo's config — resolving
            // the parent's compose file and dropping the worktree's generated
            // override, which makes parallel stacks fight over the same
            // hard-coded container names. Treat any directory containing a
            // `.git` entry (dir for a normal clone, file for a worktree) as the
            // top of the search so config never leaks across repo boundaries.
            if (file_exists($currentDir . '/.git')) {
                break;
            }

            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break; // Reached root
            }

            $currentDir = $parentDir;
            $depth++;
        }

        throw new ConfigException(
            'ngramx.yml not found in the current directory or any parent up to the repository root'
        );
    }

    private function resolveConfigPath(string $path): string
    {
        if (AbsolutePath::isAbsolute($path)) {
            return $path;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new ConfigException('Failed to get current working directory');
        }

        return AbsolutePath::resolve($cwd, $path);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildConfig(array $config, string $configDir): NgramxConfig
    {
        $docker = $this->buildDockerConfig($config['docker'], $configDir);
        $setup = $this->buildSetupConfig($config['setup'] ?? []);
        $n8n = $this->buildN8nConfig($config['n8n'] ?? [], $configDir);
        $secrets = $this->buildSecretsConfig($config['secrets'] ?? []);
        $agents = $this->buildAgentsConfig($config['agents'] ?? []);
        $commands = $this->buildCommandsMap($config['commands'] ?? []);
        $postmaclone = isset($config['postmaclone']) && is_array($config['postmaclone'])
            ? $this->buildPostmacloneConfig($config['postmaclone'])
            : null;

        $defaultTeam = $config['default_team'] ?? NgramxConfig::DEFAULT_TEAM;

        return new NgramxConfig(
            version: $config['version'],
            docker: $docker,
            setup: $setup,
            n8n: $n8n,
            secrets: $secrets,
            agents: $agents,
            commands: $commands,
            defaultTeam: strtolower((string) $defaultTeam),
            postmaclone: $postmaclone,
        );
    }

    /**
     * Discover factory postmaclone.yml / .yaml (walk up like ngramx.yml).
     *
     * @throws ConfigException
     */
    public function findFactoryConfigFile(?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '') {
            $path = $this->resolveConfigPath($explicit);
            if (!file_exists($path)) {
                throw new ConfigException("Factory configuration file not found: {$path}");
            }

            return $path;
        }

        $currentDir = getcwd();
        if ($currentDir === false) {
            throw new ConfigException('Failed to get current working directory');
        }

        $maxDepth = 10;
        $depth = 0;
        while ($depth < $maxDepth) {
            foreach (['postmaclone.yml', 'postmaclone.yaml'] as $name) {
                $candidate = $currentDir . '/' . $name;
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
            if (file_exists($currentDir . '/.git')) {
                break;
            }
            $parent = dirname($currentDir);
            if ($parent === $currentDir) {
                break;
            }
            $currentDir = $parent;
            $depth++;
        }

        throw new ConfigException(
            'Factory config not found. Create postmaclone.yml (or pass --config) in the factory repo.'
        );
    }

    /**
     * @throws ConfigException
     */
    public function loadFactory(string $path): FactoryConfig
    {
        $filePath = $this->resolveConfigPath($path);
        if (!file_exists($filePath)) {
            throw new ConfigException("Factory configuration file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ConfigException("Failed to read factory configuration: {$filePath}");
        }

        try {
            $config = Yaml::parse($content);
        } catch (\Exception $e) {
            throw new ConfigException("Failed to parse factory YAML: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($config)) {
            throw new ConfigException('Invalid factory configuration: expected array');
        }

        $this->validator->validateFactory($config);

        return $this->buildFactoryConfig($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildFactoryConfig(array $config): FactoryConfig
    {
        $locale = isset($config['locale']) && is_string($config['locale'])
            ? $config['locale']
            : PostmacloneConfig::DEFAULT_LOCALE;
        $seed = array_key_exists('seed', $config) ? (is_int($config['seed']) ? $config['seed'] : null) : 42;
        $engines = $this->buildEngineConnections(is_array($config['engines'] ?? null) ? $config['engines'] : []);
        $datasets = [];
        $raw = is_array($config['datasets'] ?? null) ? $config['datasets'] : [];
        foreach ($raw as $name => $dataset) {
            if (!is_string($name) || !is_array($dataset)) {
                continue;
            }
            $datasets[$name] = $this->buildFactoryDataset($name, $dataset, $locale, $seed, $engines);
        }

        return new FactoryConfig(
            version: isset($config['version']) ? (string) $config['version'] : '1',
            datasets: $datasets,
            engines: $engines,
            locale: $locale,
            seed: $seed,
        );
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, EngineConnectionsConfig> $engines
     */
    private function buildFactoryDataset(
        string $name,
        array $dataset,
        string $defaultLocale,
        ?int $defaultSeed,
        array $engines,
    ): FactoryDatasetConfig {
        $backupRaw = is_array($dataset['backup'] ?? null) ? $dataset['backup'] : [];
        $publishRaw = is_array($dataset['publish'] ?? null) ? $dataset['publish'] : [];
        $targetRaw = is_array($dataset['target'] ?? null) ? $dataset['target'] : [];
        $sharedRaw = is_array($dataset['shared'] ?? null) ? $dataset['shared'] : null;
        $engine = isset($dataset['engine']) && is_string($dataset['engine'])
            ? $dataset['engine']
            : PostmacloneConfig::ENGINE_POSTGRES;
        $engineConnections = $engines[$engine] ?? null;

        return new FactoryDatasetConfig(
            name: $name,
            engine: $engine,
            locale: isset($dataset['locale']) && is_string($dataset['locale']) ? $dataset['locale'] : $defaultLocale,
            seed: array_key_exists('seed', $dataset)
                ? (is_int($dataset['seed']) ? $dataset['seed'] : null)
                : $defaultSeed,
            backup: $this->buildBackupConfig($backupRaw),
            publish: new PublishConfig(
                path: isset($publishRaw['path']) && is_string($publishRaw['path']) ? $publishRaw['path'] : null,
                region: isset($publishRaw['region']) && is_string($publishRaw['region']) ? $publishRaw['region'] : null,
                endpoint: isset($publishRaw['endpoint']) && is_string($publishRaw['endpoint']) ? $publishRaw['endpoint'] : null,
                pathStyle: isset($publishRaw['path_style']) && is_bool($publishRaw['path_style']) ? $publishRaw['path_style'] : null,
                file: isset($publishRaw['file']) && is_string($publishRaw['file']) ? $publishRaw['file'] : null,
                credentials: $this->loadBackupCredentials($publishRaw['credentials'] ?? null),
            ),
            target: $this->buildTargetConfig(
                $targetRaw,
                TargetConfig::PROVIDER_DOCKER,
                $engineConnections?->scratch,
            ),
            shared: $this->buildSharedDbConfig($sharedRaw, $engineConnections?->anon),
            tables: $this->buildTables(is_array($dataset['tables'] ?? null) ? $dataset['tables'] : []),
            includeTables: $this->loadStringList($dataset['include_tables'] ?? null),
            excludeTables: $this->loadStringList($dataset['exclude_tables'] ?? null),
            testPassword: isset($dataset['test_password']) && is_string($dataset['test_password'])
                ? $dataset['test_password']
                : PostmacloneConfig::DEFAULT_TEST_PASSWORD,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildPostmacloneConfig(array $config): PostmacloneConfig
    {
        $backupRaw = is_array($config['backup'] ?? null) ? $config['backup'] : [];
        $prebuiltRaw = is_array($config['prebuilt'] ?? null) ? $config['prebuilt'] : null;
        $targetRaw = is_array($config['target'] ?? null) ? $config['target'] : [];
        $sharedRaw = is_array($config['shared'] ?? null) ? $config['shared'] : null;
        $engines = $this->buildEngineConnections(is_array($config['engines'] ?? null) ? $config['engines'] : []);
        $engine = isset($config['engine']) && is_string($config['engine'])
            ? $config['engine']
            : PostmacloneConfig::ENGINE_POSTGRES;
        $engineConnections = $engines[$engine] ?? null;

        $denyHosts = [];
        if (isset($config['deny_hosts']) && is_array($config['deny_hosts'])) {
            foreach ($config['deny_hosts'] as $host) {
                if (is_string($host) && $host !== '') {
                    $denyHosts[] = $host;
                }
            }
        }

        $prebuilt = null;
        if ($prebuiltRaw !== null) {
            $prebuilt = new PrebuiltConfig(
                source: isset($prebuiltRaw['source']) && is_string($prebuiltRaw['source'])
                    ? $prebuiltRaw['source']
                    : BackupConfig::SOURCE_S3,
                path: isset($prebuiltRaw['path']) && is_string($prebuiltRaw['path']) ? $prebuiltRaw['path'] : null,
                region: isset($prebuiltRaw['region']) && is_string($prebuiltRaw['region']) ? $prebuiltRaw['region'] : null,
                endpoint: isset($prebuiltRaw['endpoint']) && is_string($prebuiltRaw['endpoint']) ? $prebuiltRaw['endpoint'] : null,
                pathStyle: isset($prebuiltRaw['path_style']) && is_bool($prebuiltRaw['path_style']) ? $prebuiltRaw['path_style'] : null,
                file: isset($prebuiltRaw['file']) && is_string($prebuiltRaw['file']) ? $prebuiltRaw['file'] : null,
                credentials: $this->loadBackupCredentials($prebuiltRaw['credentials'] ?? null),
                maxAgeHours: isset($prebuiltRaw['max_age_hours']) && is_int($prebuiltRaw['max_age_hours'])
                    ? $prebuiltRaw['max_age_hours']
                    : null,
            );
        }

        return new PostmacloneConfig(
            engine: isset($config['engine']) && is_string($config['engine']) ? $config['engine'] : null,
            locale: isset($config['locale']) && is_string($config['locale']) ? $config['locale'] : PostmacloneConfig::DEFAULT_LOCALE,
            seed: array_key_exists('seed', $config) ? (is_int($config['seed']) ? $config['seed'] : null) : 42,
            backup: $this->buildBackupConfig($backupRaw),
            prebuilt: $prebuilt,
            shared: $this->buildSharedDbConfig($sharedRaw, $engineConnections?->anon),
            target: $this->buildTargetConfig($targetRaw, TargetConfig::PROVIDER_AUTO, $engineConnections?->scratch),
            tables: $this->buildTables(is_array($config['tables'] ?? null) ? $config['tables'] : []),
            testPassword: isset($config['test_password']) && is_string($config['test_password'])
                ? $config['test_password']
                : PostmacloneConfig::DEFAULT_TEST_PASSWORD,
            denyHosts: $denyHosts,
            engines: $engines,
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, EngineConnectionsConfig>
     */
    private function buildEngineConnections(array $raw): array
    {
        $engines = [];
        foreach ($raw as $engineName => $roles) {
            if (!is_string($engineName) || !is_array($roles)) {
                continue;
            }

            $scratch = null;
            if (isset($roles['scratch']) && is_array($roles['scratch'])) {
                $scratch = $this->loadDbCredentials($roles['scratch']['credentials'] ?? null);
            }

            $anon = null;
            if (isset($roles['anon']) && is_array($roles['anon'])) {
                $anon = $this->loadDbCredentials($roles['anon']['credentials'] ?? null);
            }

            if ($scratch === null && $anon === null) {
                continue;
            }

            $engines[$engineName] = new EngineConnectionsConfig(scratch: $scratch, anon: $anon);
        }

        return $engines;
    }

    /**
     * @param array<string, mixed> $backupRaw
     */
    private function buildBackupConfig(array $backupRaw): BackupConfig
    {
        return new BackupConfig(
            source: isset($backupRaw['source']) && is_string($backupRaw['source']) ? $backupRaw['source'] : BackupConfig::SOURCE_LOCAL,
            path: isset($backupRaw['path']) && is_string($backupRaw['path']) ? $backupRaw['path'] : null,
            region: isset($backupRaw['region']) && is_string($backupRaw['region']) ? $backupRaw['region'] : null,
            endpoint: isset($backupRaw['endpoint']) && is_string($backupRaw['endpoint']) ? $backupRaw['endpoint'] : null,
            pathStyle: isset($backupRaw['path_style']) && is_bool($backupRaw['path_style']) ? $backupRaw['path_style'] : null,
            file: isset($backupRaw['file']) && is_string($backupRaw['file']) ? $backupRaw['file'] : null,
            credentials: $this->loadBackupCredentials($backupRaw['credentials'] ?? null),
            roles: $this->loadBackupRoles($backupRaw['roles'] ?? null),
        );
    }

    /**
     * @param array<string, mixed>|null $sharedRaw
     */
    private function buildSharedDbConfig(?array $sharedRaw, ?DbCredentialsConfig $defaultCredentials = null): ?SharedDbConfig
    {
        if ($sharedRaw === null) {
            return null;
        }

        return new SharedDbConfig(
            connection: $this->buildDbConnectionConfig($sharedRaw, $defaultCredentials),
            maxAgeHours: isset($sharedRaw['max_age_hours']) && is_int($sharedRaw['max_age_hours'])
                ? $sharedRaw['max_age_hours']
                : null,
            passwordRotationDays: $this->loadPasswordRotationDays($sharedRaw),
        );
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function buildDbConnectionConfig(?array $raw, ?DbCredentialsConfig $defaultCredentials = null): ?DbConnectionConfig
    {
        if ($raw === null && $defaultCredentials === null) {
            return null;
        }

        $raw ??= [];

        if (isset($raw['url']) && is_string($raw['url']) && $raw['url'] !== '') {
            return new DbConnectionConfig(url: $raw['url']);
        }

        $credentials = $this->loadDbCredentials($raw['credentials'] ?? null) ?? $defaultCredentials;
        $host = isset($raw['host']) && is_string($raw['host']) ? $raw['host'] : null;
        $database = isset($raw['database']) && is_string($raw['database']) ? $raw['database'] : null;
        if ($host === null && $database === null && $credentials === null) {
            return null;
        }

        return new DbConnectionConfig(
            host: $host,
            port: isset($raw['port']) && is_int($raw['port']) ? $raw['port'] : null,
            database: $database,
            credentials: $credentials,
        );
    }

    /**
     * @param mixed $credentials
     */
    private function loadDbCredentials(mixed $credentials): ?DbCredentialsConfig
    {
        if (!is_array($credentials)) {
            return null;
        }
        if (!isset($credentials['username'], $credentials['password'])
            || !is_string($credentials['username'])
            || !is_string($credentials['password'])) {
            return null;
        }

        return new DbCredentialsConfig(
            username: $credentials['username'],
            password: $credentials['password'],
            host: $this->loadCredentialHost($credentials),
            port: isset($credentials['port']) && is_string($credentials['port']) ? $credentials['port'] : null,
            connectionOptions: $this->loadCredentialConnectionOptions($credentials),
        );
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function loadCredentialHost(array $credentials): ?string
    {
        if (isset($credentials['server']) && is_string($credentials['server']) && $credentials['server'] !== '') {
            return $credentials['server'];
        }
        if (isset($credentials['host']) && is_string($credentials['host']) && $credentials['host'] !== '') {
            return $credentials['host'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function loadCredentialConnectionOptions(array $credentials): ?string
    {
        foreach (['connection_options', 'connection options'] as $key) {
            if (isset($credentials[$key]) && is_string($credentials[$key]) && $credentials[$key] !== '') {
                return $credentials[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sharedRaw
     */
    private function loadPasswordRotationDays(array $sharedRaw): ?int
    {
        if (!array_key_exists('password_rotation_days', $sharedRaw)) {
            return SharedDbConfig::DEFAULT_PASSWORD_ROTATION_DAYS;
        }
        if ($sharedRaw['password_rotation_days'] === null) {
            return null;
        }

        return is_int($sharedRaw['password_rotation_days']) ? $sharedRaw['password_rotation_days'] : null;
    }

    /**
     * @param array<string, mixed> $targetRaw
     */
    private function buildTargetConfig(
        array $targetRaw,
        string $defaultProvider,
        ?DbCredentialsConfig $remoteDefaultCredentials = null,
    ): TargetConfig {
        $neon = is_array($targetRaw['neon'] ?? null) ? $targetRaw['neon'] : [];
        $docker = is_array($targetRaw['docker'] ?? null) ? $targetRaw['docker'] : [];
        $remote = is_array($targetRaw['remote'] ?? null) ? $targetRaw['remote'] : [];
        $remoteConnection = $this->buildDbConnectionConfig($remote, $remoteDefaultCredentials);
        $legacyRemoteUrl = isset($remote['url']) && is_string($remote['url']) ? $remote['url'] : null;

        return new TargetConfig(
            provider: isset($targetRaw['provider']) && is_string($targetRaw['provider'])
                ? $targetRaw['provider']
                : $defaultProvider,
            ttlHours: isset($targetRaw['ttl_hours']) && is_int($targetRaw['ttl_hours'])
                ? $targetRaw['ttl_hours']
                : TargetConfig::DEFAULT_TTL_HOURS,
            neonProjectId: isset($neon['project_id']) && is_string($neon['project_id']) ? $neon['project_id'] : null,
            neonRegionId: isset($neon['region_id']) && is_string($neon['region_id']) ? $neon['region_id'] : null,
            dockerImage: isset($docker['image']) && is_string($docker['image']) ? $docker['image'] : null,
            dockerPort: isset($docker['port']) && is_int($docker['port']) ? $docker['port'] : 0,
            remoteUrl: $legacyRemoteUrl,
            remote: $remoteConnection,
            remoteThresholdBytes: isset($targetRaw['remote_threshold_bytes']) && is_int($targetRaw['remote_threshold_bytes'])
                ? $targetRaw['remote_threshold_bytes']
                : TargetConfig::DEFAULT_REMOTE_THRESHOLD_BYTES,
        );
    }

    /**
     * @param array<string, mixed> $tablesRaw
     * @return array<string, TableRule>
     */
    private function buildTables(array $tablesRaw): array
    {
        $tables = [];
        foreach ($tablesRaw as $tableName => $tableConfig) {
            if (!is_string($tableName) || !is_array($tableConfig)) {
                continue;
            }

            $primaryKey = isset($tableConfig['primary_key']) && is_string($tableConfig['primary_key'])
                ? $tableConfig['primary_key']
                : null;

            $columnsRaw = $tableConfig;
            if (isset($tableConfig['columns']) && is_array($tableConfig['columns'])) {
                $columnsRaw = $tableConfig['columns'];
            }
            unset($columnsRaw['primary_key'], $columnsRaw['columns']);

            $columns = [];
            foreach ($columnsRaw as $columnName => $rule) {
                if (!is_string($columnName)) {
                    continue;
                }
                if (is_string($rule)) {
                    $unique = str_starts_with($rule, 'unique')
                        && strlen($rule) > 6
                        && ctype_upper($rule[6] ?? '');
                    $columns[$columnName] = new ColumnRule(
                        column: $columnName,
                        faker: $rule,
                        unique: $unique,
                    );
                    continue;
                }
                if (!is_array($rule) || !isset($rule['faker']) || !is_string($rule['faker'])) {
                    continue;
                }
                $columns[$columnName] = new ColumnRule(
                    column: $columnName,
                    faker: $rule['faker'],
                    unique: (bool) ($rule['unique'] ?? false),
                    preserveNulls: (bool) ($rule['preserve_nulls'] ?? true),
                    where: isset($rule['where']) && is_string($rule['where']) ? $rule['where'] : null,
                );
            }

            $tables[$tableName] = new TableRule(
                table: $tableName,
                columns: $columns,
                primaryKey: $primaryKey,
            );
        }

        return $tables;
    }

    /**
     * @return list<string>|null
     */
    private function loadStringList(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    private function loadBackupCredentials(mixed $raw): ?BackupCredentialsConfig
    {
        if (!is_array($raw)) {
            return null;
        }
        $key = $raw['key'] ?? null;
        $secret = $raw['secret'] ?? null;
        if (!is_string($key) || !is_string($secret)) {
            return null;
        }

        return new BackupCredentialsConfig(key: $key, secret: $secret);
    }

    /**
     * @return list<string>|null
     */
    private function loadBackupRoles(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $roles = [];
        foreach ($raw as $role) {
            if (is_string($role) && trim($role) !== '') {
                $roles[] = trim($role);
            }
        }

        return $roles;
    }

    /**
     * @param array<string, mixed> $dockerConfig
     */
    private function buildDockerConfig(array $dockerConfig, string $configDir): DockerConfig
    {
        $waitFor = [];
        if (isset($dockerConfig['wait_for']) && is_array($dockerConfig['wait_for'])) {
            foreach ($dockerConfig['wait_for'] as $waitConfig) {
                $readyCommand = $waitConfig['ready_command'] ?? null;
                $readyLog = $waitConfig['ready_log'] ?? null;

                $waitFor[] = new ServiceWaitConfig(
                    service: $waitConfig['service'],
                    timeout: $waitConfig['timeout'],
                    healthcheck: (bool) ($waitConfig['healthcheck'] ?? false),
                    readyCommand: $readyCommand !== null ? (string) $readyCommand : null,
                    readyLog: $readyLog !== null ? (string) $readyLog : null,
                );
            }
        }

        // Resolve compose file path relative to config directory
        $composeFile = AbsolutePath::resolve($configDir, $dockerConfig['compose_file']);

        return new DockerConfig(
            composeFile: $composeFile,
            primaryService: $dockerConfig['primary_service'],
            appUrl: $dockerConfig['app_url'],
            waitFor: $waitFor,
            sslPath: $dockerConfig['ssl_path'] ?? 'docker/nginx/ssl',
            verifyTimeout: isset($dockerConfig['verify_timeout'])
                ? (int) $dockerConfig['verify_timeout']
                : null,
            endpoints: $this->buildEndpoints($dockerConfig['endpoints'] ?? null),
            env: $this->buildEnvMap($dockerConfig['env'] ?? null, 'docker.env'),
        );
    }

    /**
     * @return array<string, EndpointConfig>
     */
    private function buildEndpoints(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new ConfigException('docker.endpoints must be a map of endpoint name => { url, service, env, file }');
        }

        $endpoints = [];
        foreach ($raw as $name => $entry) {
            if (!is_string($name) || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) !== 1) {
                throw new ConfigException(sprintf(
                    'docker.endpoints: "%s" is not a valid endpoint name (lowercase letters, digits and hyphens only — it becomes a hostname label)',
                    (string) $name,
                ));
            }
            if ($name === 'primary') {
                throw new ConfigException('docker.endpoints: "primary" is reserved for docker.app_url');
            }
            // Shorthand: `api: "http://api.example.localhost"`.
            if (is_string($entry)) {
                $entry = ['url' => $entry];
            }
            if (!is_array($entry) || !isset($entry['url']) || !is_string($entry['url'])) {
                throw new ConfigException("docker.endpoints.{$name} must have a url");
            }
            $parts = parse_url($entry['url']);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                throw new ConfigException("docker.endpoints.{$name}.url must be an http(s) URL with a hostname");
            }
            $service = $entry['service'] ?? null;
            if ($service !== null && (!is_string($service) || trim($service) === '')) {
                throw new ConfigException("docker.endpoints.{$name}.service must be a compose service name");
            }
            $file = $entry['file'] ?? '.env';
            if (!is_string($file) || trim($file) === '' || str_starts_with($file, '/') || str_contains($file, '..')) {
                throw new ConfigException("docker.endpoints.{$name}.file must be a project-relative path");
            }

            $endpoints[$name] = new EndpointConfig(
                name: $name,
                url: $entry['url'],
                service: $service,
                env: $this->buildEnvMap($entry['env'] ?? null, "docker.endpoints.{$name}.env"),
                file: $file,
            );
        }

        return $endpoints;
    }

    /**
     * @return array<string, string>
     */
    private function buildEnvMap(mixed $raw, string $path): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new ConfigException("{$path} must be a map of ENV_VAR => value");
        }
        $env = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new ConfigException(sprintf('%s: "%s" is not a valid environment variable name', $path, (string) $key));
            }
            if (!is_scalar($value)) {
                throw new ConfigException("{$path}.{$key} must be a string");
            }
            $env[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $env;
    }

    /**
     * @param array<string, mixed> $setupConfig
     */
    private function buildSetupConfig(array $setupConfig): SetupConfig
    {
        $preStart = [];
        if (isset($setupConfig['pre_start']) && is_array($setupConfig['pre_start'])) {
            $preStart = $this->buildCommandList($setupConfig['pre_start']);
        }

        $initialize = [];
        if (isset($setupConfig['initialize']) && is_array($setupConfig['initialize'])) {
            $initialize = $this->buildCommandList($setupConfig['initialize']);
        }

        return new SetupConfig(
            preStart: $preStart,
            initialize: $initialize,
        );
    }

    private function normalizePath(string $path, string $projectRoot): string
    {
        if ($path === '') {
            throw new \RuntimeException('Path cannot be empty');
        }

        if ($path[0] === '/') {
            return rtrim($path, '/');
        }

        // Remove leading './' from relative paths
        $normalizedPath = preg_replace('#^\./#', '', $path);

        $fullPath = rtrim($projectRoot . '/' . $normalizedPath, '/');

        // Use realpath if the path exists to resolve any remaining . or .. components
        if (file_exists($fullPath)) {
            $resolved = realpath($fullPath);
            if ($resolved !== false) {
                return $resolved;
            }
        }

        return $fullPath;
    }


    /**
     * @param array<string, mixed> $n8nConfig
     */
    private function buildN8nConfig(array $n8nConfig, string $configDir): N8nConfig
    {
        $workflowsDir = $this->normalizePath(
            $n8nConfig['workflows_dir'] ?? self::DEFAULT_N8N_WORKFLOWS_DIR,
            $configDir
        );
        return new N8nConfig(
            workflowsDir: $workflowsDir,
        );
    }


    /**
     * @param array<string, mixed> $secretsConfig
     */
    private function buildSecretsConfig(array $secretsConfig): SecretsConfig
    {
        $secretsConfig = SecretsSectionNormalizer::normalize($secretsConfig);

        if (isset($secretsConfig['providers'])) {
            $providers = [];
            foreach ($secretsConfig['providers'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $providers[] = new SecretsProviderConfig(
                    provider: SecretsProviderConfig::normalizeProvider(
                        is_string($entry['provider'] ?? null)
                            ? $entry['provider']
                            : SecretsProviderConfig::PROVIDER_SHELL
                    ),
                    required: is_array($entry['required'] ?? null) ? $entry['required'] : [],
                );
            }

            return new SecretsConfig(providers: $providers);
        }

        return new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::normalizeProvider(
                    is_string($secretsConfig['provider'] ?? null)
                        ? $secretsConfig['provider']
                        : SecretsProviderConfig::PROVIDER_SHELL
                ),
                required: is_array($secretsConfig['required'] ?? null) ? $secretsConfig['required'] : [],
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $agentsConfig
     */
    private function buildAgentsConfig(array $agentsConfig): AgentsConfig
    {
        $targets = $agentsConfig['targets'] ?? AgentsConfig::DEFAULT_TARGETS;
        $skills = $agentsConfig['skills'] ?? AgentsConfig::DEFAULT_SKILLS;

        return new AgentsConfig(
            targets: $targets,
            skills: $skills,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $commands
     * @return CommandDefinition[]
     */
    private function buildCommandList(array $commands): array
    {
        $result = [];
        foreach ($commands as $command) {
            $result[] = $this->buildCommandDefinition($command);
        }
        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $commands
     * @return array<string, CommandDefinition>
     */
    private function buildCommandsMap(array $commands): array
    {
        $result = [];
        foreach ($commands as $name => $command) {
            $result[$name] = $this->buildCommandDefinition($command);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $command
     */
    private function buildCommandDefinition(array $command): CommandDefinition
    {
        $rawCommand = $command['command'];
        $parallel = (bool) ($command['parallel'] ?? true);

        if (is_array($rawCommand)) {
            $commands = array_values(array_map(static fn ($c) => (string) $c, $rawCommand));
            // Mirror shell semantics in the human-readable summary: ` & ` for
            // concurrent lists, ` && ` for sequential (stop-on-failure) lists.
            $displayCommand = implode($parallel ? ' & ' : ' && ', $commands);
        } else {
            $commands = [(string) $rawCommand];
            $displayCommand = (string) $rawCommand;
        }

        return new CommandDefinition(
            command: $displayCommand,
            description: $command['description'],
            timeout: $command['timeout'] ?? 600,
            retry: $command['retry'] ?? null,
            ignoreFailure: $command['ignore_failure'] ?? false,
            commands: $commands,
            parallel: $parallel,
        );
    }
}

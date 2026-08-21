<?php

declare(strict_types=1);

namespace Ngramx\Config\Validator;

use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\AgentsConfig;
use Ngramx\Config\Schema\HookEvent;
use Ngramx\Config\SecretsSectionNormalizer;

class ConfigValidator
{
    /** @var list<string> */
    private const SUPPORTED_SECRET_PROVIDERS = ['shell', '.env', 'env'];

    /** @var list<string> */
    private const DOCUMENTED_SECRET_PROVIDERS = ['shell', '.env'];

    /**
     * @param array<string, mixed> $config
     * @throws ConfigException
     */
    public function validate(array $config): void
    {
        $this->validateRequiredFields($config);
        $this->validateDockerSection($config['docker']);

        if (isset($config['setup'])) {
            $this->validateSetupSection($config['setup']);
        }

        if (isset($config['commands'])) {
            $this->validateCommandsSection($config['commands']);
        }

        if (isset($config['secrets'])) {
            $this->validateSecretsSection($config['secrets']);
        }

        if (isset($config['agents'])) {
            $this->validateAgentsSection($config['agents']);
        }

        if (isset($config['default_team'])) {
            if (!is_string($config['default_team']) || preg_match('/^[a-z]+$/i', $config['default_team']) !== 1) {
                throw new ConfigException('default_team must be a short alphabetic team prefix (e.g. "gig")');
            }
        }

        if (isset($config['postmaclone'])) {
            $this->validatePostmacloneSection($config['postmaclone']);
        }

        if (isset($config['hooks'])) {
            if (!is_array($config['hooks'])) {
                throw new ConfigException('hooks must be an array');
            }
            $this->validateHooksSection($config['hooks']);
        }
    }

    /**
     * @param array<string, mixed> $hooks
     * @throws ConfigException
     */
    public function validateHooksSection(array $hooks): void
    {
        $known = HookEvent::values();

        foreach ($hooks as $eventName => $list) {
            if (!is_string($eventName) || $eventName === '') {
                throw new ConfigException('hooks event names must be non-empty strings');
            }

            if (!in_array($eventName, $known, true)) {
                throw new ConfigException(
                    "Unknown hooks event '{$eventName}'. Supported: " . implode(', ', $known)
                );
            }

            if (!is_array($list)) {
                throw new ConfigException("hooks.{$eventName} must be a list of commands");
            }

            if ($list !== [] && array_keys($list) !== range(0, count($list) - 1)) {
                throw new ConfigException("hooks.{$eventName} must be a list, not an associative map");
            }

            foreach ($list as $index => $entry) {
                $this->validateHookEntry($entry, "hooks.{$eventName}[{$index}]");
            }
        }
    }

    /**
     * @throws ConfigException
     */
    private function validateHookEntry(mixed $entry, string $path): void
    {
        if (is_string($entry)) {
            if (trim($entry) === '') {
                throw new ConfigException("{$path} must be a non-empty string");
            }

            return;
        }

        if (!is_array($entry)) {
            throw new ConfigException("{$path} must be a string or a mapping with a command");
        }

        if (!isset($entry['command']) || !is_string($entry['command']) || trim($entry['command']) === '') {
            throw new ConfigException("{$path} missing required field: command");
        }

        if (isset($entry['description']) && !is_string($entry['description'])) {
            throw new ConfigException("{$path}.description must be a string");
        }

        if (isset($entry['timeout']) && (!is_int($entry['timeout']) || $entry['timeout'] <= 0)) {
            throw new ConfigException("{$path}.timeout must be a positive integer");
        }

        if (isset($entry['ignore_failure']) && !is_bool($entry['ignore_failure'])) {
            throw new ConfigException("{$path}.ignore_failure must be a boolean");
        }

        if (isset($entry['cwd']) && !is_string($entry['cwd'])) {
            throw new ConfigException("{$path}.cwd must be a string");
        }
    }

    /**
     * @param mixed $postmaclone
     * @throws ConfigException
     */
    private function validatePostmacloneSection(mixed $postmaclone): void
    {
        if (!is_array($postmaclone)) {
            throw new ConfigException('postmaclone must be an array');
        }

        if (isset($postmaclone['engine'])) {
            if (!is_string($postmaclone['engine']) || !in_array($postmaclone['engine'], ['postgres', 'mysql', 'mariadb'], true)) {
                throw new ConfigException('postmaclone.engine must be one of: postgres, mysql, mariadb');
            }
        }

        if (isset($postmaclone['locale']) && !is_string($postmaclone['locale'])) {
            throw new ConfigException('postmaclone.locale must be a string');
        }

        if (array_key_exists('seed', $postmaclone) && $postmaclone['seed'] !== null && !is_int($postmaclone['seed'])) {
            throw new ConfigException('postmaclone.seed must be an integer or null');
        }

        if (isset($postmaclone['test_password']) && !is_string($postmaclone['test_password'])) {
            throw new ConfigException('postmaclone.test_password must be a string');
        }

        if (isset($postmaclone['deny_hosts'])) {
            if (!is_array($postmaclone['deny_hosts'])) {
                throw new ConfigException('postmaclone.deny_hosts must be an array');
            }
            foreach ($postmaclone['deny_hosts'] as $i => $host) {
                if (!is_string($host) || $host === '') {
                    throw new ConfigException("postmaclone.deny_hosts[$i] must be a non-empty string");
                }
            }
        }

        if (isset($postmaclone['backup'])) {
            $this->validatePostmacloneBackup($postmaclone['backup'], 'postmaclone.backup');
        }

        if (isset($postmaclone['prebuilt'])) {
            $this->validatePostmaclonePrebuilt($postmaclone['prebuilt']);
        }

        if (isset($postmaclone['target'])) {
            $this->validatePostmacloneTarget($postmaclone['target']);
        }

        $hasPrebuilt = isset($postmaclone['prebuilt'])
            && is_array($postmaclone['prebuilt'])
            && isset($postmaclone['prebuilt']['path'])
            && is_string($postmaclone['prebuilt']['path'])
            && $postmaclone['prebuilt']['path'] !== '';

        $hasTables = isset($postmaclone['tables']) && is_array($postmaclone['tables']) && $postmaclone['tables'] !== [];
        if (!$hasTables && !$hasPrebuilt) {
            throw new ConfigException(
                'postmaclone requires either prebuilt (consumer) or tables (anonymize rules / full pipeline)'
            );
        }

        if ($hasTables) {
            $this->validatePostmacloneTables($postmaclone['tables'], 'postmaclone.tables');
        }
    }

    /**
     * Validate a factory postmaclone.yml document (no ngramx docker section).
     *
     * @param array<string, mixed> $config
     * @throws ConfigException
     */
    public function validateFactory(array $config): void
    {
        if (!isset($config['datasets']) || !is_array($config['datasets']) || $config['datasets'] === []) {
            throw new ConfigException('Factory postmaclone.yml requires a non-empty datasets map');
        }

        foreach ($config['datasets'] as $name => $dataset) {
            if (!is_string($name) || $name === '') {
                throw new ConfigException('Factory datasets keys must be non-empty strings');
            }
            if (!is_array($dataset)) {
                throw new ConfigException("Factory datasets.{$name} must be an object");
            }
            if (isset($dataset['engine'])
                && (!is_string($dataset['engine']) || !in_array($dataset['engine'], ['postgres', 'mysql', 'mariadb'], true))) {
                throw new ConfigException("Factory datasets.{$name}.engine must be postgres, mysql, or mariadb");
            }
            if (!isset($dataset['backup']) || !is_array($dataset['backup'])) {
                throw new ConfigException("Factory datasets.{$name}.backup is required");
            }
            $this->validatePostmacloneBackup($dataset['backup'], "datasets.{$name}.backup");
            if (!isset($dataset['publish']) || !is_array($dataset['publish'])) {
                throw new ConfigException("Factory datasets.{$name}.publish is required");
            }
            $this->validatePublish($dataset['publish'], "datasets.{$name}.publish");
            if (!isset($dataset['tables']) || !is_array($dataset['tables']) || $dataset['tables'] === []) {
                throw new ConfigException("Factory datasets.{$name}.tables is required");
            }
            $this->validatePostmacloneTables($dataset['tables'], "datasets.{$name}.tables");
            foreach (['include_tables', 'exclude_tables'] as $listKey) {
                if (!isset($dataset[$listKey])) {
                    continue;
                }
                if (!is_array($dataset[$listKey])) {
                    throw new ConfigException("Factory datasets.{$name}.{$listKey} must be a list of table names");
                }
                foreach ($dataset[$listKey] as $i => $table) {
                    if (!is_string($table) || trim($table) === '') {
                        throw new ConfigException("Factory datasets.{$name}.{$listKey}[{$i}] must be a non-empty string");
                    }
                }
            }
            if (isset($dataset['target'])) {
                $this->validatePostmacloneTarget($dataset['target'], "datasets.{$name}.target");
            }
        }
    }

    /**
     * @param mixed $tables
     * @throws ConfigException
     */
    private function validatePostmacloneTables(mixed $tables, string $prefix): void
    {
        if (!is_array($tables) || $tables === []) {
            throw new ConfigException("{$prefix} must list at least one table with columns to anonymize (opt-in)");
        }

        foreach ($tables as $tableName => $tableConfig) {
            if (!is_string($tableName) || $tableName === '') {
                throw new ConfigException("{$prefix} keys must be non-empty table names");
            }
            if (!is_array($tableConfig)) {
                throw new ConfigException("{$prefix}.{$tableName} must be an array of column rules");
            }

            $columns = $tableConfig;
            if (isset($tableConfig['columns']) && is_array($tableConfig['columns'])) {
                $columns = $tableConfig['columns'];
            }
            unset($columns['primary_key'], $columns['columns']);

            if ($columns === []) {
                throw new ConfigException(
                    "{$prefix}.{$tableName} must list at least one column to anonymize"
                );
            }

            if (isset($tableConfig['primary_key']) && (!is_string($tableConfig['primary_key']) || $tableConfig['primary_key'] === '')) {
                throw new ConfigException("{$prefix}.{$tableName}.primary_key must be a non-empty string");
            }

            foreach ($columns as $columnName => $rule) {
                if (!is_string($columnName) || $columnName === '') {
                    throw new ConfigException("{$prefix}.{$tableName} column keys must be non-empty strings");
                }
                if (is_string($rule)) {
                    if (trim($rule) === '') {
                        throw new ConfigException("{$prefix}.{$tableName}.{$columnName} faker method must be a non-empty string");
                    }
                    continue;
                }
                if (!is_array($rule)) {
                    throw new ConfigException(
                        "{$prefix}.{$tableName}.{$columnName} must be a faker method string or an object"
                    );
                }
                if (!isset($rule['faker']) || !is_string($rule['faker']) || trim($rule['faker']) === '') {
                    throw new ConfigException("{$prefix}.{$tableName}.{$columnName}.faker is required");
                }
                if (isset($rule['unique']) && !is_bool($rule['unique'])) {
                    throw new ConfigException("{$prefix}.{$tableName}.{$columnName}.unique must be a boolean");
                }
                if (isset($rule['preserve_nulls']) && !is_bool($rule['preserve_nulls'])) {
                    throw new ConfigException("{$prefix}.{$tableName}.{$columnName}.preserve_nulls must be a boolean");
                }
                if (isset($rule['where']) && (!is_string($rule['where']) || $rule['where'] === '')) {
                    throw new ConfigException("{$prefix}.{$tableName}.{$columnName}.where must be a non-empty string");
                }
            }
        }
    }

    /**
     * @param mixed $prebuilt
     * @throws ConfigException
     */
    private function validatePostmaclonePrebuilt(mixed $prebuilt): void
    {
        if (!is_array($prebuilt)) {
            throw new ConfigException('postmaclone.prebuilt must be an array');
        }
        if (isset($prebuilt['source']) && !in_array($prebuilt['source'], ['local', 's3'], true)) {
            throw new ConfigException('postmaclone.prebuilt.source must be local or s3');
        }
        if (!isset($prebuilt['path']) || !is_string($prebuilt['path']) || $prebuilt['path'] === '') {
            throw new ConfigException('postmaclone.prebuilt.path is required');
        }
        if (isset($prebuilt['region']) && !is_string($prebuilt['region'])) {
            throw new ConfigException('postmaclone.prebuilt.region must be a string');
        }
        if (isset($prebuilt['endpoint']) && !is_string($prebuilt['endpoint'])) {
            throw new ConfigException('postmaclone.prebuilt.endpoint must be a string');
        }
        if (isset($prebuilt['path_style']) && !is_bool($prebuilt['path_style'])) {
            throw new ConfigException('postmaclone.prebuilt.path_style must be a boolean');
        }
        if (isset($prebuilt['file'])) {
            if (!is_string($prebuilt['file']) || trim($prebuilt['file']) === '') {
                throw new ConfigException('postmaclone.prebuilt.file must be a non-empty string');
            }
            if (str_contains($prebuilt['file'], '/') || str_contains($prebuilt['file'], '\\')) {
                throw new ConfigException('postmaclone.prebuilt.file must be a basename only');
            }
        }
        if (isset($prebuilt['max_age_hours']) && (!is_int($prebuilt['max_age_hours']) || $prebuilt['max_age_hours'] <= 0)) {
            throw new ConfigException('postmaclone.prebuilt.max_age_hours must be a positive integer');
        }
        if (isset($prebuilt['credentials'])) {
            $this->validateOpCredentials($prebuilt['credentials'], 'postmaclone.prebuilt.credentials');
        }
    }

    /**
     * @param mixed $publish
     * @throws ConfigException
     */
    private function validatePublish(mixed $publish, string $prefix): void
    {
        if (!is_array($publish)) {
            throw new ConfigException("{$prefix} must be an array");
        }
        if (!isset($publish['path']) || !is_string($publish['path']) || $publish['path'] === '') {
            throw new ConfigException("{$prefix}.path is required");
        }
        if (isset($publish['region']) && !is_string($publish['region'])) {
            throw new ConfigException("{$prefix}.region must be a string");
        }
        if (isset($publish['endpoint']) && !is_string($publish['endpoint'])) {
            throw new ConfigException("{$prefix}.endpoint must be a string");
        }
        if (isset($publish['path_style']) && !is_bool($publish['path_style'])) {
            throw new ConfigException("{$prefix}.path_style must be a boolean");
        }
        if (isset($publish['file'])) {
            if (!is_string($publish['file']) || trim($publish['file']) === '') {
                throw new ConfigException("{$prefix}.file must be a non-empty string");
            }
            if (str_contains($publish['file'], '/') || str_contains($publish['file'], '\\')) {
                throw new ConfigException("{$prefix}.file must be a basename only");
            }
        }
        if (isset($publish['credentials'])) {
            $this->validateOpCredentials($publish['credentials'], "{$prefix}.credentials");
        }
    }

    /**
     * @param mixed $credentials
     * @throws ConfigException
     */
    private function validateOpCredentials(mixed $credentials, string $prefix): void
    {
        if (!is_array($credentials)) {
            throw new ConfigException("{$prefix} must be an object with key and secret");
        }
        $key = $credentials['key'] ?? null;
        $secret = $credentials['secret'] ?? null;
        if (!is_string($key) || trim($key) === '' || !is_string($secret) || trim($secret) === '') {
            throw new ConfigException("{$prefix} requires non-empty key and secret");
        }
        if (!str_starts_with($key, 'op://') || !str_starts_with($secret, 'op://')) {
            throw new ConfigException(
                "{$prefix} key/secret must be 1Password references (op://vault/item/field). "
                . 'Do not put plaintext access keys in YAML.'
            );
        }
    }

    /**
     * @param mixed $backup
     * @throws ConfigException
     */
    private function validatePostmacloneBackup(mixed $backup, string $prefix = 'postmaclone.backup'): void
    {
        if (!is_array($backup)) {
            throw new ConfigException("{$prefix} must be an array");
        }

        if (isset($backup['source']) && !in_array($backup['source'], ['local', 's3', 'connection'], true)) {
            throw new ConfigException("{$prefix}.source must be local, s3, or connection");
        }

        if (isset($backup['path']) && !is_string($backup['path'])) {
            throw new ConfigException("{$prefix}.path must be a string");
        }

        if (isset($backup['region']) && !is_string($backup['region'])) {
            throw new ConfigException("{$prefix}.region must be a string");
        }

        if (isset($backup['endpoint']) && !is_string($backup['endpoint'])) {
            throw new ConfigException("{$prefix}.endpoint must be a string");
        }

        if (isset($backup['path_style']) && !is_bool($backup['path_style'])) {
            throw new ConfigException("{$prefix}.path_style must be a boolean");
        }

        if (isset($backup['file'])) {
            if (!is_string($backup['file']) || trim($backup['file']) === '') {
                throw new ConfigException("{$prefix}.file must be a non-empty string");
            }
            if (str_contains($backup['file'], '/') || str_contains($backup['file'], '\\')) {
                throw new ConfigException("{$prefix}.file must be a basename only (e.g. earl_kendrick_prod.sql.gz)");
            }
        }

        if (isset($backup['credentials'])) {
            $this->validateOpCredentials($backup['credentials'], "{$prefix}.credentials");
        }

        if (isset($backup['roles'])) {
            if (!is_array($backup['roles'])) {
                throw new ConfigException("{$prefix}.roles must be a list of role name strings");
            }
            foreach ($backup['roles'] as $role) {
                if (!is_string($role) || trim($role) === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
                    throw new ConfigException(
                        "{$prefix}.roles entries must be simple identifiers (e.g. earl_kendrick_prod)"
                    );
                }
            }
        }
    }

    /**
     * @param mixed $target
     * @throws ConfigException
     */
    private function validatePostmacloneTarget(mixed $target, string $prefix = 'postmaclone.target'): void
    {
        if (!is_array($target)) {
            throw new ConfigException("{$prefix} must be an array");
        }

        if (isset($target['provider']) && !in_array($target['provider'], ['neon', 'docker', 'remote', 'auto'], true)) {
            throw new ConfigException("{$prefix}.provider must be neon, docker, remote, or auto");
        }

        if (isset($target['ttl_hours']) && (!is_int($target['ttl_hours']) || $target['ttl_hours'] <= 0)) {
            throw new ConfigException("{$prefix}.ttl_hours must be a positive integer");
        }

        if (isset($target['remote_threshold_bytes'])
            && (!is_int($target['remote_threshold_bytes']) || $target['remote_threshold_bytes'] <= 0)) {
            throw new ConfigException("{$prefix}.remote_threshold_bytes must be a positive integer");
        }

        if (isset($target['neon']) && is_array($target['neon'])) {
            if (array_key_exists('project_id', $target['neon'])
                && $target['neon']['project_id'] !== null
                && !is_string($target['neon']['project_id'])) {
                throw new ConfigException("{$prefix}.neon.project_id must be a string or null");
            }
            if (array_key_exists('region_id', $target['neon'])
                && $target['neon']['region_id'] !== null
                && !is_string($target['neon']['region_id'])) {
                throw new ConfigException("{$prefix}.neon.region_id must be a string or null");
            }
        }

        if (isset($target['docker']) && is_array($target['docker'])) {
            if (array_key_exists('image', $target['docker'])
                && $target['docker']['image'] !== null
                && !is_string($target['docker']['image'])) {
                throw new ConfigException("{$prefix}.docker.image must be a string or null");
            }
            if (isset($target['docker']['port']) && (!is_int($target['docker']['port']) || $target['docker']['port'] < 0)) {
                throw new ConfigException("{$prefix}.docker.port must be a non-negative integer");
            }
        }

        if (isset($target['remote']) && is_array($target['remote'])) {
            if (isset($target['remote']['url']) && (!is_string($target['remote']['url']) || $target['remote']['url'] === '')) {
                throw new ConfigException("{$prefix}.remote.url must be a non-empty string");
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     * @throws ConfigException
     */
    private function validateRequiredFields(array $config): void
    {
        if (!isset($config['version'])) {
            throw new ConfigException('Missing required field: version');
        }

        if (!isset($config['docker'])) {
            throw new ConfigException('Missing required field: docker');
        }
    }

    /**
     * @param array<string, mixed> $docker
     * @throws ConfigException
     */
    public function validateDockerSection(array $docker): void
    {
        if (!isset($docker['compose_file'])) {
            throw new ConfigException('Missing required field: docker.compose_file');
        }

        if (!isset($docker['primary_service'])) {
            throw new ConfigException('Missing required field: docker.primary_service');
        }

        if (!isset($docker['app_url'])) {
            throw new ConfigException('Missing required field: docker.app_url');
        }

        if (isset($docker['verify_timeout'])) {
            if (!is_int($docker['verify_timeout']) || $docker['verify_timeout'] <= 0) {
                throw new ConfigException('docker.verify_timeout must be a positive integer (seconds)');
            }
        }

        if (isset($docker['wait_for']) && !is_array($docker['wait_for'])) {
            throw new ConfigException('docker.wait_for must be an array');
        }

        if (isset($docker['wait_for'])) {
            foreach ($docker['wait_for'] as $index => $waitConfig) {
                if (!isset($waitConfig['service'])) {
                    throw new ConfigException("docker.wait_for[$index] missing required field: service");
                }
                if (!isset($waitConfig['timeout'])) {
                    throw new ConfigException("docker.wait_for[$index] missing required field: timeout");
                }
                if (!is_int($waitConfig['timeout']) || $waitConfig['timeout'] <= 0) {
                    throw new ConfigException("docker.wait_for[$index].timeout must be a positive integer");
                }

                if (isset($waitConfig['healthcheck']) && !is_bool($waitConfig['healthcheck'])) {
                    throw new ConfigException("docker.wait_for[$index].healthcheck must be a boolean");
                }

                if (isset($waitConfig['ready_command'])) {
                    if (!is_string($waitConfig['ready_command']) || trim($waitConfig['ready_command']) === '') {
                        throw new ConfigException("docker.wait_for[$index].ready_command must be a non-empty string");
                    }
                }

                if (isset($waitConfig['ready_log'])) {
                    if (!is_string($waitConfig['ready_log']) || $waitConfig['ready_log'] === '') {
                        throw new ConfigException("docker.wait_for[$index].ready_log must be a non-empty string");
                    }
                    if (@preg_match('~' . str_replace('~', '\~', $waitConfig['ready_log']) . '~', '') === false) {
                        throw new ConfigException("docker.wait_for[$index].ready_log is not a valid regular expression");
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $setup
     * @throws ConfigException
     */
    public function validateSetupSection(array $setup): void
    {
        if (isset($setup['pre_start'])) {
            $this->validateCommandList($setup['pre_start'], 'setup.pre_start');
        }

        if (isset($setup['initialize'])) {
            $this->validateCommandList($setup['initialize'], 'setup.initialize');
        }
    }

    /**
     * @param array<string, mixed> $commands
     * @throws ConfigException
     */
    private function validateCommandsSection(array $commands): void
    {
        foreach ($commands as $name => $command) {
            $this->validateCommandDefinition($command, "commands.$name", allowParallel: true);
        }
    }

    /**
     * @param array<int, mixed> $commands
     * @throws ConfigException
     */
    private function validateCommandList(array $commands, string $path): void
    {
        foreach ($commands as $index => $command) {
            $this->validateCommandDefinition($command, "$path[$index]", allowParallel: false);
        }
    }

    /**
     * @param mixed $command
     * @throws ConfigException
     */
    private function validateCommandDefinition(mixed $command, string $path, bool $allowParallel): void
    {
        if (!is_array($command)) {
            throw new ConfigException("$path must be an array");
        }

        if (!isset($command['command'])) {
            throw new ConfigException("$path missing required field: command");
        }

        if (is_array($command['command'])) {
            if (!$allowParallel) {
                throw new ConfigException("$path.command must be a string (parallel list form is only supported under the `commands:` section)");
            }

            if ($command['command'] === []) {
                throw new ConfigException("$path.command list must contain at least one command");
            }

            if (array_keys($command['command']) !== range(0, count($command['command']) - 1)) {
                throw new ConfigException("$path.command must be a list, not an associative map");
            }

            foreach ($command['command'] as $index => $item) {
                if (!is_string($item)) {
                    throw new ConfigException("$path.command[$index] must be a string");
                }
                if (trim($item) === '') {
                    throw new ConfigException("$path.command[$index] must be a non-empty string");
                }
            }
        } elseif (!is_string($command['command'])) {
            throw new ConfigException("$path.command must be a string" . ($allowParallel ? ' or a list of strings' : ''));
        }

        if (!isset($command['description'])) {
            throw new ConfigException("$path missing required field: description");
        }

        if (isset($command['timeout']) && (!is_int($command['timeout']) || $command['timeout'] <= 0)) {
            throw new ConfigException("$path.timeout must be a positive integer");
        }

        if (isset($command['retry']) && (!is_int($command['retry']) || $command['retry'] < 0)) {
            throw new ConfigException("$path.retry must be a non-negative integer");
        }

        if (isset($command['ignore_failure']) && !is_bool($command['ignore_failure'])) {
            throw new ConfigException("$path.ignore_failure must be a boolean");
        }

        if (isset($command['parallel'])) {
            if (!is_bool($command['parallel'])) {
                throw new ConfigException("$path.parallel must be a boolean");
            }
            if (!is_array($command['command'])) {
                throw new ConfigException("$path.parallel only applies to a list of commands");
            }
        }
    }

    /**
     * @param mixed $secrets
     * @throws ConfigException
     */
    private function validateSecretsSection(mixed $secrets): void
    {
        if (!is_array($secrets)) {
            throw new ConfigException('secrets must be an array');
        }

        $secrets = SecretsSectionNormalizer::normalize($secrets);

        if (isset($secrets['providers'])) {
            if (isset($secrets['provider']) || isset($secrets['required'])) {
                throw new ConfigException(
                    'secrets cannot mix the legacy provider/required keys with secrets.providers — use one format or the other'
                );
            }

            $this->validateSecretsProvidersList($secrets['providers']);

            return;
        }

        if (isset($secrets['provider']) && !is_string($secrets['provider'])) {
            throw new ConfigException('secrets.provider must be a string');
        }

        if (isset($secrets['provider'])) {
            $this->validateSecretProvider($secrets['provider'], 'secrets.provider');
        }

        if (isset($secrets['required'])) {
            $this->validateSecretsRequiredList($secrets['required'], 'secrets.required');
        }
    }

    /**
     * @param mixed $providers
     * @throws ConfigException
     */
    private function validateSecretsProvidersList(mixed $providers): void
    {
        if (!is_array($providers)) {
            throw new ConfigException('secrets.providers must be an array');
        }

        foreach ($providers as $index => $entry) {
            if (!is_array($entry)) {
                throw new ConfigException("secrets.providers[$index] must be an array");
            }

            if (!isset($entry['provider']) || !is_string($entry['provider'])) {
                throw new ConfigException("secrets.providers[$index].provider must be a string");
            }

            $this->validateSecretProvider($entry['provider'], "secrets.providers[$index].provider");

            if (isset($entry['required'])) {
                $this->validateSecretsRequiredList($entry['required'], "secrets.providers[$index].required");
            }
        }
    }

    /**
     * @throws ConfigException
     */
    private function validateSecretProvider(string $provider, string $path): void
    {
        if (!in_array($provider, self::SUPPORTED_SECRET_PROVIDERS, true)) {
            $supported = implode(', ', self::DOCUMENTED_SECRET_PROVIDERS);
            throw new ConfigException("Unsupported secrets provider at {$path}: {$provider}. Supported providers: {$supported}");
        }
    }

    /**
     * @param mixed $required
     * @throws ConfigException
     */
    private function validateSecretsRequiredList(mixed $required, string $path): void
    {
        if (!is_array($required)) {
            throw new ConfigException("{$path} must be an array");
        }

        foreach ($required as $index => $name) {
            if (!is_string($name) || $name === '') {
                throw new ConfigException("{$path}[$index] must be a non-empty string");
            }
        }
    }

    /**
     * @param mixed $agents
     * @throws ConfigException
     */
    private function validateAgentsSection(mixed $agents): void
    {
        if (!is_array($agents)) {
            throw new ConfigException('agents must be an array');
        }

        if (isset($agents['targets'])) {
            if (!is_array($agents['targets'])) {
                throw new ConfigException('agents.targets must be an array');
            }

            foreach ($agents['targets'] as $index => $target) {
                if (!is_string($target) || $target === '') {
                    throw new ConfigException("agents.targets[$index] must be a non-empty string");
                }
                if (!in_array($target, AgentsConfig::VALID_TARGETS, true)) {
                    throw new ConfigException(
                        "agents.targets[$index]: unknown target '$target'. Valid targets: " . implode(', ', AgentsConfig::VALID_TARGETS)
                    );
                }
            }
        }

        if (isset($agents['skills'])) {
            if (!is_array($agents['skills'])) {
                throw new ConfigException('agents.skills must be an array');
            }

            foreach ($agents['skills'] as $index => $skill) {
                if (!is_string($skill) || $skill === '') {
                    throw new ConfigException("agents.skills[$index] must be a non-empty string");
                }
                if (!in_array($skill, AgentsConfig::VALID_SKILLS, true)) {
                    throw new ConfigException(
                        "agents.skills[$index]: unknown skill target '$skill'. Valid targets: " . implode(', ', AgentsConfig::VALID_SKILLS)
                    );
                }
            }
        }
    }
}

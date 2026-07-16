<?php

declare(strict_types=1);

namespace Ngramx\Config;

use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\AgentsConfig;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SecretsConfig;
use Ngramx\Config\Schema\SecretsProviderConfig;
use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Config\Validator\ConfigValidator;
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
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new ConfigException('Failed to get current working directory');
        }

        return $cwd . '/' . $path;
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
        );
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
        $composeFile = $dockerConfig['compose_file'];
        if (!str_starts_with($composeFile, '/')) {
            $composeFile = $configDir . '/' . $composeFile;
        }

        return new DockerConfig(
            composeFile: $composeFile,
            primaryService: $dockerConfig['primary_service'],
            appUrl: $dockerConfig['app_url'],
            waitFor: $waitFor,
            sslPath: $dockerConfig['ssl_path'] ?? 'docker/nginx/ssl',
            verifyTimeout: isset($dockerConfig['verify_timeout'])
                ? (int) $dockerConfig['verify_timeout']
                : null,
        );
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
        if (isset($secretsConfig['providers'])) {
            $providers = [];
            foreach ($secretsConfig['providers'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $providers[] = new SecretsProviderConfig(
                    provider: is_string($entry['provider'] ?? null) ? $entry['provider'] : SecretsProviderConfig::PROVIDER_ENV,
                    required: is_array($entry['required'] ?? null) ? $entry['required'] : [],
                );
            }

            return new SecretsConfig(providers: $providers);
        }

        return new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: is_string($secretsConfig['provider'] ?? null)
                    ? $secretsConfig['provider']
                    : SecretsProviderConfig::PROVIDER_ENV,
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
            retry: $command['retry'] ?? 0,
            ignoreFailure: $command['ignore_failure'] ?? false,
            commands: $commands,
            parallel: $parallel,
        );
    }
}

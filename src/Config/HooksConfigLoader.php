<?php

declare(strict_types=1);

namespace Ngramx\Config;

use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\HookDefinition;
use Ngramx\Config\Schema\HookEvent;
use Ngramx\Config\Schema\HooksConfig;
use Ngramx\Config\Validator\ConfigValidator;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads event hooks from user + project sources and deep-merges them.
 *
 * Precedence (later wins per event key):
 * 1. `~/.ngramx.yaml` / `~/.ngramx.yml`
 * 2. `<project>/.ngramx/config.yaml` / `.yml`
 * 3. `hooks:` block in the project's `ngramx.yml`
 */
class HooksConfigLoader
{
    public function __construct(
        private readonly ConfigValidator $validator = new ConfigValidator(),
        private readonly ArrayDeepMerger $merger = new ArrayDeepMerger(),
        private readonly ?string $homeDirectory = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $ngramxYmlHooks Raw `hooks:` map from ngramx.yml, if already parsed
     *
     * @throws ConfigException
     */
    public function load(string $projectRoot, ?array $ngramxYmlHooks = null): HooksConfig
    {
        $merged = [];

        $userPath = $this->resolveExistingPath($this->userConfigCandidates());
        if ($userPath !== null) {
            $merged = $this->merger->merge($merged, $this->loadHooksMapFromFile($userPath));
        }

        $projectPath = $this->resolveExistingPath($this->projectConfigCandidates($projectRoot));
        if ($projectPath !== null) {
            $merged = $this->merger->merge($merged, $this->loadHooksMapFromFile($projectPath));
        }

        if ($ngramxYmlHooks === null) {
            $ngramxYmlHooks = $this->loadHooksMapFromNgramxYml($projectRoot);
        }

        if ($ngramxYmlHooks !== []) {
            $this->validator->validateHooksSection($ngramxYmlHooks);
            $merged = $this->merger->merge($merged, $ngramxYmlHooks);
        }

        if ($merged === []) {
            return new HooksConfig();
        }

        $this->validator->validateHooksSection($merged);

        return $this->build($merged);
    }

    /**
     * @param array<string, mixed> $hooks
     */
    public function build(array $hooks): HooksConfig
    {
        $events = [];

        foreach ($hooks as $eventName => $rawList) {
            if (!is_string($eventName) || !is_array($rawList)) {
                continue;
            }

            $definitions = [];
            foreach ($rawList as $entry) {
                $definitions[] = $this->buildDefinition($entry);
            }
            $events[$eventName] = $definitions;
        }

        return new HooksConfig(events: $events);
    }

    /**
     * @return list<string>
     */
    public function userConfigCandidates(?string $home = null): array
    {
        $home ??= $this->resolveHomeDirectory();
        if ($home === null) {
            return [];
        }

        return [
            $home . '/.ngramx.yaml',
            $home . '/.ngramx.yml',
        ];
    }

    /**
     * @return list<string>
     */
    public function projectConfigCandidates(string $projectRoot): array
    {
        return [
            $projectRoot . '/.ngramx/config.yaml',
            $projectRoot . '/.ngramx/config.yml',
        ];
    }

    /**
     * @param list<string> $candidates
     */
    private function resolveExistingPath(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConfigException
     */
    private function loadHooksMapFromFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new ConfigException("Failed to read hooks config: {$path}");
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (\Exception $e) {
            throw new ConfigException("Failed to parse hooks YAML ({$path}): {$e->getMessage()}", 0, $e);
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed)) {
            throw new ConfigException("Invalid hooks config ({$path}): expected a YAML mapping");
        }

        $hooks = $this->extractHooksMap($parsed);
        if ($hooks === []) {
            return [];
        }

        $this->validator->validateHooksSection($hooks);

        return $hooks;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConfigException
     */
    private function loadHooksMapFromNgramxYml(string $projectRoot): array
    {
        foreach (['ngramx.yml', 'ngramx.yaml'] as $name) {
            $path = $projectRoot . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                throw new ConfigException("Failed to read configuration file: {$path}");
            }

            try {
                $parsed = Yaml::parse($content);
            } catch (\Exception $e) {
                throw new ConfigException("Failed to parse YAML ({$path}): {$e->getMessage()}", 0, $e);
            }

            if (!is_array($parsed) || !isset($parsed['hooks']) || !is_array($parsed['hooks'])) {
                return [];
            }

            /** @var array<string, mixed> $hooks */
            $hooks = $parsed['hooks'];

            return $hooks;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function extractHooksMap(array $parsed): array
    {
        if (isset($parsed['hooks']) && is_array($parsed['hooks'])) {
            /** @var array<string, mixed> $hooks */
            $hooks = $parsed['hooks'];

            return $hooks;
        }

        // Allow a dedicated hooks file whose top-level keys are event names.
        $eventValues = HookEvent::values();
        $keys = array_keys($parsed);
        if ($keys === []) {
            return [];
        }

        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, $eventValues, true)) {
                return [];
            }
        }

        return $parsed;
    }

    private function buildDefinition(mixed $entry): HookDefinition
    {
        if (is_string($entry)) {
            return new HookDefinition(command: $entry);
        }

        if (!is_array($entry)) {
            throw new ConfigException('Hook entry must be a string or a mapping');
        }

        $command = $entry['command'] ?? null;
        if (!is_string($command) || trim($command) === '') {
            throw new ConfigException('Hook entry missing a non-empty command');
        }

        $description = $entry['description'] ?? '';
        if (!is_string($description)) {
            throw new ConfigException('Hook description must be a string');
        }

        $timeout = $entry['timeout'] ?? HookDefinition::DEFAULT_TIMEOUT;
        if (!is_int($timeout) || $timeout <= 0) {
            throw new ConfigException('Hook timeout must be a positive integer');
        }

        $ignoreFailure = $entry['ignore_failure'] ?? true;
        if (!is_bool($ignoreFailure)) {
            throw new ConfigException('Hook ignore_failure must be a boolean');
        }

        $cwd = $entry['cwd'] ?? null;
        if ($cwd !== null && !is_string($cwd)) {
            throw new ConfigException('Hook cwd must be a string');
        }

        return new HookDefinition(
            command: $command,
            description: $description,
            timeout: $timeout,
            ignoreFailure: $ignoreFailure,
            cwd: $cwd,
        );
    }

    private function resolveHomeDirectory(): ?string
    {
        if ($this->homeDirectory !== null && $this->homeDirectory !== '') {
            return $this->homeDirectory;
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }

        $home = getenv('USERPROFILE');
        if (is_string($home) && $home !== '') {
            return $home;
        }

        return null;
    }
}

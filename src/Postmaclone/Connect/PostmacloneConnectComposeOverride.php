<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

use Ngramx\Docker\ComposeFiles;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Yaml\Yaml;

/**
 * Overrides hardcoded compose DB_HOST=db with postmaclone connect settings.
 *
 * Laravel honours process env over .env, so patching .env alone is not enough
 * when docker-compose.yml sets DB_HOST on the app service.
 */
final class PostmacloneConnectComposeOverride
{
    /** @var list<string> */
    private const DB_ENV_KEYS = [
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    public function apply(string $composeFile, PostmacloneConnectLockData $lock): void
    {
        $serviceNames = $this->databaseServiceNames($composeFile);
        if ($serviceNames === []) {
            return;
        }

        $overridePath = $this->overridePath($composeFile);
        $hadOverride = file_exists($overridePath);
        $override = $hadOverride
            ? $this->parseOverrideFile($overridePath)
            : ['services' => []];

        if (!is_array($override) || !isset($override['services']) || !is_array($override['services'])) {
            $override = ['services' => []];
        }

        foreach ($serviceNames as $serviceName) {
            $serviceOverride = $override['services'][$serviceName] ?? [];
            if (!is_array($serviceOverride)) {
                $serviceOverride = [];
            }

            $environment = $serviceOverride['environment'] ?? [];
            if (!is_array($environment)) {
                $environment = [];
            }

            $environment = array_merge(
                $this->normalizeEnvironmentMap($environment),
                $this->dbEnvironment($lock),
            );
            $serviceOverride['environment'] = $environment;

            if ($lock->mode === PostmacloneConnectLockData::MODE_TUNNEL) {
                $serviceOverride['extra_hosts'] = ['host.docker.internal:host-gateway'];
            }

            $override['services'][$serviceName] = $serviceOverride;
        }

        $this->writeOverride($overridePath, $override, $hadOverride);
    }

    public function remove(string $composeFile): void
    {
        $overridePath = $this->overridePath($composeFile);
        if (!file_exists($overridePath)) {
            return;
        }

        $override = $this->parseOverrideFile($overridePath);
        if (!isset($override['services']) || !is_array($override['services'])) {
            return;
        }

        foreach ($this->databaseServiceNames($composeFile) as $serviceName) {
            if (!isset($override['services'][$serviceName]) || !is_array($override['services'][$serviceName])) {
                continue;
            }

            $serviceOverride = $override['services'][$serviceName];
            if (isset($serviceOverride['environment']) && is_array($serviceOverride['environment'])) {
                $environment = $this->normalizeEnvironmentMap($serviceOverride['environment']);
                foreach (self::DB_ENV_KEYS as $key) {
                    unset($environment[$key]);
                }
                if ($environment === []) {
                    unset($serviceOverride['environment']);
                } else {
                    $serviceOverride['environment'] = $environment;
                }
            }

            unset($serviceOverride['extra_hosts']);

            if ($serviceOverride === []) {
                unset($override['services'][$serviceName]);
            } else {
                $override['services'][$serviceName] = $serviceOverride;
            }
        }

        if ($override['services'] === []) {
            unlink($overridePath);

            return;
        }

        $this->writeOverride($overridePath, $override, true);
    }

    /**
     * @return list<string>
     */
    public function databaseServiceNames(string $composeFile): array
    {
        if (!is_file($composeFile)) {
            return [];
        }

        try {
            $config = Yaml::parseFile($composeFile);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($config) || !isset($config['services']) || !is_array($config['services'])) {
            return [];
        }

        $names = [];
        foreach ($config['services'] as $serviceName => $service) {
            if (!is_string($serviceName) || !is_array($service)) {
                continue;
            }
            if ($this->serviceUsesDatabase($service)) {
                $names[] = $serviceName;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $serviceOverride
     * @return array<string, mixed>
     */
    public function mergeServiceOverride(array $serviceOverride, PostmacloneConnectLockData $lock): array
    {
        $environment = $serviceOverride['environment'] ?? [];
        if (!is_array($environment)) {
            $environment = [];
        }

        $serviceOverride['environment'] = array_merge(
            $this->normalizeEnvironmentMap($environment),
            $this->dbEnvironment($lock),
        );

        if ($lock->mode === PostmacloneConnectLockData::MODE_TUNNEL) {
            $serviceOverride['extra_hosts'] = ['host.docker.internal:host-gateway'];
        }

        return $serviceOverride;
    }

    /**
     * @param array<string, mixed> $service
     */
    public function serviceUsesDatabase(array $service): bool
    {
        if (!isset($service['environment']) || !is_array($service['environment'])) {
            return false;
        }

        $environment = $this->normalizeEnvironmentMap($service['environment']);

        return array_key_exists('DB_HOST', $environment);
    }

    /**
     * @return array<string, string>
     */
    private function dbEnvironment(PostmacloneConnectLockData $lock): array
    {
        $connection = $lock->engine === 'postgres' ? 'pgsql' : 'mysql';

        return [
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $lock->host,
            'DB_PORT' => (string) $lock->port,
            'DB_DATABASE' => $lock->database,
            'DB_USERNAME' => $lock->username,
            'DB_PASSWORD' => $lock->password,
        ];
    }

    /**
     * @param array<int|string, mixed> $environment
     * @return array<string, string>
     */
    private function normalizeEnvironmentMap(array $environment): array
    {
        $map = [];
        foreach ($environment as $key => $value) {
            if (is_int($key) && is_string($value) && str_contains($value, '=')) {
                [$name, $envValue] = explode('=', $value, 2);
                $map[$name] = $envValue;
                continue;
            }
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    private function overridePath(string $composeFile): string
    {
        return dirname($composeFile) . '/' . ComposeFiles::OVERRIDE_FILE;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOverrideFile(string $overridePath): array
    {
        $content = file_get_contents($overridePath);
        if ($content === false) {
            throw new PostmacloneException("Failed to read {$overridePath}");
        }

        try {
            $parsed = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable $e) {
            throw new PostmacloneException(
                "Failed to parse {$overridePath}: {$e->getMessage()}"
            );
        }

        return is_array($parsed) ? $parsed : ['services' => []];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function writeOverride(string $overridePath, array $override, bool $preserveExistingHeader): void
    {
        $header = $this->resolveOverrideHeader($overridePath, $preserveExistingHeader);

        $encoded = Yaml::dump($override, 10, 2);
        if ($encoded === false) {
            throw new PostmacloneException('Failed to encode docker-compose override for Post Maclone connect');
        }

        // Match ComposeOverrideGenerator: plain ports arrays need an explicit tag.
        $encoded = preg_replace('/^(\s+ports:)$/m', '$1 !override', $encoded) ?? $encoded;
        $encoded = preg_replace('/^(\s+ports:) \{  \}$/m', '$1 !reset []', $encoded) ?? $encoded;

        if (file_put_contents($overridePath, $header . $encoded) === false) {
            throw new PostmacloneException("Failed to write {$overridePath}");
        }
    }

    private function resolveOverrideHeader(string $overridePath, bool $preserveExistingHeader): string
    {
        if ($preserveExistingHeader && file_exists($overridePath)) {
            $existing = file_get_contents($overridePath);
            if (is_string($existing) && preg_match('/^(#.*\n)+\n/s', $existing, $matches) === 1) {
                return $matches[0];
            }
        }

        if (file_exists($overridePath)) {
            return '';
        }

        return "# Generated by Ngramx CLI - DO NOT EDIT MANUALLY\n"
            . "# Post Maclone connect overrides DB_* for services that hardcode DB_HOST=db.\n"
            . "# Run 'ngramx postmaclone disconnect' to remove.\n\n";
    }
}

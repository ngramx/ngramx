<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

use Ngramx\Docker\ComposeFiles;
use Ngramx\Docker\DockerCompose;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Stops the project's compose DB service so a Postmaclone container can take
 * the `db` DNS name on the shared network (no compose override file).
 */
final class ComposeDbServiceSwitcher
{
    public const DEFAULT_ALIAS = 'db';

    public function __construct(
        private readonly DockerCompose $compose = new DockerCompose(),
    ) {
    }

    /**
     * Prefer a service literally named `db`, else the first postgres/mysql/mariadb image.
     */
    public function detectServiceName(?string $composeFile): ?string
    {
        if ($composeFile === null || $composeFile === '' || !is_file($composeFile)) {
            return null;
        }

        try {
            $parsed = Yaml::parseFile($composeFile);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($parsed) || !isset($parsed['services']) || !is_array($parsed['services'])) {
            return null;
        }

        if (isset($parsed['services'][self::DEFAULT_ALIAS]) && is_array($parsed['services'][self::DEFAULT_ALIAS])) {
            return self::DEFAULT_ALIAS;
        }

        foreach ($parsed['services'] as $name => $service) {
            if (!is_string($name) || !is_array($service)) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            if ($image === '') {
                continue;
            }
            if (
                str_contains($image, 'postgres')
                || str_contains($image, 'postgis')
                || str_contains($image, 'mysql')
                || str_contains($image, 'mariadb')
            ) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Network alias apps already use (hardcoded DB_HOST=db). Falls back to service name.
     */
    public function networkAlias(?string $composeFile): string
    {
        $service = $this->detectServiceName($composeFile);

        return $service ?? self::DEFAULT_ALIAS;
    }

    public function stop(string $composeFile, string $service, ?string $projectName = null): void
    {
        try {
            $this->compose->stopService($composeFile, $service, $projectName);
        } catch (\Throwable $e) {
            throw new PostmacloneException(
                "Failed to stop compose DB service '{$service}' so Postmaclone can use the '{$service}' network alias: "
                . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function start(string $composeFile, string $service, ?string $projectName = null): void
    {
        try {
            $this->compose->startService($composeFile, $service, $projectName);
        } catch (\Throwable $e) {
            throw new PostmacloneException(
                "Failed to restart compose DB service '{$service}' after Postmaclone teardown: "
                . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Best-effort Laravel config clear after .env credential swap (mounted .env).
     */
    public function refreshAppConfig(string $composeFile, string $primaryService, ?string $projectName = null): void
    {
        $args = array_merge(
            ['docker-compose'],
            ComposeFiles::fileArgs($composeFile),
        );
        if ($projectName !== null) {
            $args[] = '-p';
            $args[] = $projectName;
        }
        $args = array_merge($args, [
            'exec', '-T', $primaryService,
            'sh', '-c',
            'php artisan config:clear 2>/dev/null || true',
        ]);

        $process = new Process($args);
        $process->setTimeout(60);
        $process->run();
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Yaml\Yaml;

class EngineDetector
{
    /**
     * @return PostmacloneConfig::ENGINE_*
     */
    public function detect(?string $configuredEngine, string $composeFilePath, ?string $hint = null): string
    {
        $detected = $this->detectFromCompose($composeFilePath);

        if ($configuredEngine !== null) {
            return $this->normalize($configuredEngine);
        }

        if ($hint !== null) {
            return $this->normalize($hint);
        }

        if ($detected !== null) {
            return $this->normalize($detected);
        }

        throw new PostmacloneException(
            'Could not detect database engine from docker-compose. '
            . 'Set postmaclone.engine to postgres, mysql, or mariadb.'
        );
    }

    public function detectFromCompose(string $composeFilePath): ?string
    {
        if ($composeFilePath === '' || !is_file($composeFilePath)) {
            return null;
        }

        try {
            $parsed = Yaml::parseFile($composeFilePath);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($parsed) || !isset($parsed['services']) || !is_array($parsed['services'])) {
            return null;
        }

        foreach ($parsed['services'] as $service) {
            if (!is_array($service)) {
                continue;
            }
            $image = (string) ($service['image'] ?? '');
            $engine = $this->engineFromImage($image);
            if ($engine !== null) {
                return $engine;
            }
        }

        return null;
    }

    public function engineFromImage(string $image): ?string
    {
        $image = strtolower($image);
        if ($image === '') {
            return null;
        }

        if (str_contains($image, 'postgres') || str_contains($image, 'postgis')) {
            return PostmacloneConfig::ENGINE_POSTGRES;
        }
        if (str_contains($image, 'mariadb')) {
            return PostmacloneConfig::ENGINE_MARIADB;
        }
        if (str_contains($image, 'mysql') || str_contains($image, 'percona')) {
            return PostmacloneConfig::ENGINE_MYSQL;
        }

        return null;
    }

    /**
     * @return PostmacloneConfig::ENGINE_*
     */
    public function normalize(string $engine): string
    {
        $engine = strtolower(trim($engine));

        return match ($engine) {
            PostmacloneConfig::ENGINE_POSTGRES => PostmacloneConfig::ENGINE_POSTGRES,
            PostmacloneConfig::ENGINE_MYSQL => PostmacloneConfig::ENGINE_MYSQL,
            PostmacloneConfig::ENGINE_MARIADB => PostmacloneConfig::ENGINE_MARIADB,
            default => throw new PostmacloneException(
                "Unsupported postmaclone.engine '{$engine}'. Allowed: postgres, mysql, mariadb"
            ),
        };
    }

    /**
     * Whether compose detection disagreed with configured engine.
     */
    public function detectionMismatch(?string $configuredEngine, string $composeFilePath): ?string
    {
        if ($configuredEngine === null) {
            return null;
        }

        $detected = $this->detectFromCompose($composeFilePath);
        if ($detected === null) {
            return null;
        }

        $configured = $this->normalize($configuredEngine);
        if ($detected === $configured) {
            return null;
        }

        return "postmaclone.engine is '{$configured}' but docker-compose image suggests '{$detected}'";
    }
}

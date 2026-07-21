<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Reads Dockerfile COPY/ENTRYPOINT directives so we can compare baked image
 * files with the current project tree.
 */
class DockerfileBuildContextParser
{
    /**
     * @return list<array{host: string, image: string}>
     */
    public function copiedFiles(string $dockerfilePath): array
    {
        if (!is_file($dockerfilePath)) {
            return [];
        }

        $content = file_get_contents($dockerfilePath);
        if ($content === false) {
            return [];
        }

        $contextDir = dirname($dockerfilePath);
        $copies = [];

        if (preg_match_all(
            '/^COPY\s+(?:--[^\s=]+(?:=[^\s]+)?\s+)*(\S+)\s+(\S+)/mi',
            $content,
            $matches,
            PREG_SET_ORDER
        ) !== false) {
            foreach ($matches as $match) {
                $host = $this->resolveHostPath($contextDir, $match[1]);
                $image = $match[2];
                if ($host === null || !is_file($host)) {
                    continue;
                }

                $copies[] = ['host' => $host, 'image' => $image];
            }
        }

        return $copies;
    }

    public function defaultEntrypointPath(string $dockerfilePath): ?string
    {
        if (!is_file($dockerfilePath)) {
            return null;
        }

        $content = file_get_contents($dockerfilePath);
        if ($content === false) {
            return null;
        }

        if (preg_match('/^ENTRYPOINT\s+\[(["\'])([^"\']+)\1\]/m', $content, $match) !== 1) {
            return null;
        }

        return $match[2];
    }

    public function hostPathForImagePath(string $dockerfilePath, string $imagePath): ?string
    {
        foreach ($this->copiedFiles($dockerfilePath) as $copy) {
            if ($copy['image'] === $imagePath) {
                return $copy['host'];
            }
        }

        return null;
    }

    private function resolveHostPath(string $contextDir, string $source): ?string
    {
        if (str_starts_with($source, '/')) {
            return $source;
        }

        $path = $contextDir . '/' . $source;

        return is_file($path) ? $path : null;
    }
}

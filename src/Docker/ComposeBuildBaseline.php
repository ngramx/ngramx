<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Records the content hashes of the full compose stack (base file plus any
 * layered overrides) at the last image build so we can detect drift without
 * false positives from ngramx regenerating docker-compose.override.yml.
 */
class ComposeBuildBaseline
{
    private const BASELINE_FILE = '.ngramx/docker-build-baseline.json';

    /**
     * @return array<string, string> Relative compose path => sha256 hash
     */
    public function hashInputs(string $composeFile): array
    {
        $hashes = [];

        foreach (ComposeFiles::allInputFiles($composeFile) as $path) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                continue;
            }

            $hashes[$this->relativeComposePath($composeFile, $path)] = $hash;
        }

        return $hashes;
    }

    /**
     * Persist the current compose stack hashes after a successful image build.
     */
    public function record(string $composeFile): void
    {
        $path = $this->baselinePath($composeFile);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = json_encode(
            ['compose_inputs' => $this->hashInputs($composeFile)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            return;
        }

        file_put_contents($path, $payload . "\n");
    }

    /**
     * Compose inputs whose content differs from the last recorded build baseline.
     *
     * @return list<string> Relative paths such as docker-compose.override.yml
     */
    public function changedInputs(string $composeFile): array
    {
        $baseline = $this->read($composeFile);
        if ($baseline === null) {
            return [];
        }

        $current = $this->hashInputs($composeFile);
        $changed = [];

        foreach ($current as $relativePath => $hash) {
            if (($baseline[$relativePath] ?? null) !== $hash) {
                $changed[] = $relativePath;
            }
        }

        foreach (array_keys($baseline) as $relativePath) {
            if (!array_key_exists($relativePath, $current) && !in_array($relativePath, $changed, true)) {
                $changed[] = $relativePath;
            }
        }

        sort($changed);

        if ($this->onlyGeneratedOverrideDrift($composeFile, $changed)) {
            return [];
        }

        return $changed;
    }

    public function hasBaseline(string $composeFile): bool
    {
        return is_file($this->baselinePath($composeFile));
    }

    /**
     * @return array<string, string>|null
     */
    private function read(string $composeFile): ?array
    {
        $path = $this->baselinePath($composeFile);
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['compose_inputs']) || !is_array($decoded['compose_inputs'])) {
            return null;
        }

        $inputs = [];
        foreach ($decoded['compose_inputs'] as $relativePath => $hash) {
            if (is_string($relativePath) && is_string($hash)) {
                $inputs[$relativePath] = $hash;
            }
        }

        return $inputs === [] ? null : $inputs;
    }

    /**
     * Port offsets and container-name prefixes live only in the generated override.
     * Those changes do not require rebuilding the image.
     *
     * @param list<string> $changed
     */
    private function onlyGeneratedOverrideDrift(string $composeFile, array $changed): bool
    {
        if ($changed === [] || $changed !== [ComposeFiles::OVERRIDE_FILE]) {
            return false;
        }

        return ComposeFiles::isGeneratedOverride(dirname($composeFile) . '/' . ComposeFiles::OVERRIDE_FILE);
    }

    private function baselinePath(string $composeFile): string
    {
        return dirname($composeFile) . '/' . self::BASELINE_FILE;
    }

    private function relativeComposePath(string $composeFile, string $inputPath): string
    {
        $projectDir = dirname($composeFile);

        if (str_starts_with($inputPath, $projectDir . '/')) {
            return substr($inputPath, strlen($projectDir) + 1);
        }

        return basename($inputPath);
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Detects when a locally cached Docker image was built from older Dockerfile,
 * entrypoint, or compose inputs than the current project tree.
 *
 * Images do not retain docker-compose.yml, so compose drift is detected via
 * file mtime only. Entrypoint and other COPY'd scripts are compared by content.
 */
class ImageBuildFreshnessChecker
{
    public function __construct(
        private readonly DockerfileBuildContextParser $dockerfileParser = new DockerfileBuildContextParser(),
        private readonly ComposeBuildBaseline $composeBuildBaseline = new ComposeBuildBaseline(),
    ) {
    }

    /**
     * @return list<StaleBuildFinding>
     */
    public function findStaleBuildInputs(string $composeFile, ?string $projectName): array
    {
        if ($projectName === null || !file_exists($composeFile)) {
            return [];
        }

        $findings = [];

        foreach ($this->builtServiceNames($composeFile) as $service) {
            $image = $projectName . '-' . $service;
            if (!$this->imageExists($image)) {
                continue;
            }

            foreach ($this->scriptsToCompare($composeFile, $service) as $script) {
                $imageContents = $this->readFileFromImage($image, $script['image']);
                if ($imageContents === null || !is_file($script['host'])) {
                    continue;
                }

                if (!$this->contentsMatch($script['host'], $imageContents)) {
                    $findings[] = new StaleBuildFinding(
                        service: $service,
                        image: $image,
                        reason: str_contains($script['image'], 'entrypoint')
                            ? StaleBuildFinding::REASON_ENTRYPOINT_CHANGED
                            : StaleBuildFinding::REASON_STARTUP_SCRIPT_CHANGED,
                        hostPath: $script['host'],
                        imagePath: $script['image'],
                    );
                }
            }

            $createdAt = $this->imageCreatedAt($image);
            if ($createdAt === null) {
                continue;
            }

            $composeChanged = $this->composeInputsChanged($composeFile, $createdAt);
            if ($composeChanged !== []) {
                $findings[] = new StaleBuildFinding(
                    service: $service,
                    image: $image,
                    reason: StaleBuildFinding::REASON_COMPOSE_NEWER_THAN_IMAGE,
                    hostPath: dirname($composeFile) . '/' . $composeChanged[0],
                    composeInputPaths: $composeChanged,
                );
            }
        }

        return $this->dedupeFindings($findings);
    }

    public function hasStaleBuildInputs(string $composeFile, ?string $projectName): bool
    {
        return $this->findStaleBuildInputs($composeFile, $projectName) !== [];
    }

    public function isServiceImageStale(string $composeFile, ?string $projectName, string $service): bool
    {
        foreach ($this->findStaleBuildInputs($composeFile, $projectName) as $finding) {
            if ($finding->service === $service) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<StaleBuildFinding> $findings
     */
    public function formatAdvisory(array $findings): string
    {
        if ($findings === []) {
            return '';
        }

        $lines = ['The local Docker image is out of date relative to the project files:'];
        foreach ($findings as $finding) {
            $lines[] = '  - ' . $finding->describe();
        }
        $lines[] = 'Run `ngramx rebuild` to bake the latest Dockerfile, entrypoint, and compose changes into the image before starting.';

        return implode("\n", $lines);
    }

    public function contentsMatch(string $hostPath, string $imageContents): bool
    {
        $hostHash = hash_file('sha256', $hostPath);
        if ($hostHash === false) {
            return true;
        }

        return hash_equals($hostHash, hash('sha256', $imageContents));
    }

    /**
     * @return list<array{host: string, image: string}>
     */
    private function scriptsToCompare(string $composeFile, string $serviceName): array
    {
        $service = $this->serviceConfig($composeFile, $serviceName);
        if ($service === null) {
            return [];
        }

        $dockerfilePath = $this->resolveDockerfilePath($composeFile, $service);
        if ($dockerfilePath === null) {
            return [];
        }

        $scripts = [];
        $entrypointPath = $this->serviceEntrypointPath($service);
        if ($entrypointPath !== null) {
            $hostPath = $this->dockerfileParser->hostPathForImagePath($dockerfilePath, $entrypointPath)
                ?? $this->guessHostScriptPath($composeFile, $entrypointPath);
            if ($hostPath !== null) {
                $scripts[] = ['host' => $hostPath, 'image' => $entrypointPath];
            }
        } else {
            $defaultEntrypoint = $this->dockerfileParser->defaultEntrypointPath($dockerfilePath);
            if ($defaultEntrypoint !== null) {
                $hostPath = $this->dockerfileParser->hostPathForImagePath($dockerfilePath, $defaultEntrypoint)
                    ?? $this->guessHostScriptPath($composeFile, $defaultEntrypoint);
                if ($hostPath !== null) {
                    $scripts[] = ['host' => $hostPath, 'image' => $defaultEntrypoint];
                }
            }
        }

        return $scripts;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function serviceEntrypointPath(array $service): ?string
    {
        if (!isset($service['entrypoint'])) {
            return null;
        }

        $entrypoint = $service['entrypoint'];
        if (is_string($entrypoint)) {
            return $entrypoint;
        }

        if (is_array($entrypoint) && count($entrypoint) === 1 && is_string($entrypoint[0])) {
            return $entrypoint[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function resolveDockerfilePath(string $composeFile, array $service): ?string
    {
        if (!isset($service['build'])) {
            return null;
        }

        $projectDir = dirname($composeFile);
        $build = $service['build'];
        $context = is_array($build) ? ($build['context'] ?? '.') : '.';
        if (!is_string($context)) {
            $context = '.';
        }

        if (!str_starts_with($context, '/')) {
            $context = rtrim($projectDir, '/') . '/' . ltrim($context, './');
        }

        $dockerfile = 'Dockerfile';
        if (is_array($build) && isset($build['dockerfile']) && is_string($build['dockerfile'])) {
            $dockerfile = $build['dockerfile'];
        }

        $path = rtrim($context, '/') . '/' . ltrim($dockerfile, '/');

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serviceConfig(string $composeFile, string $serviceName): ?array
    {
        $content = file_get_contents($composeFile);
        if ($content === false) {
            return null;
        }

        $config = Yaml::parse($content);
        if (!is_array($config) || !isset($config['services'][$serviceName]) || !is_array($config['services'][$serviceName])) {
            return null;
        }

        return $config['services'][$serviceName];
    }

    private function guessHostScriptPath(string $composeFile, string $imagePath): ?string
    {
        $basename = basename($imagePath);
        $candidate = dirname($composeFile) . '/docker/' . $basename;

        return is_file($candidate) ? $candidate : null;
    }

    private function readFileFromImage(string $image, string $path): ?string
    {
        $process = new Process(['docker', 'run', '--rm', '--entrypoint', 'cat', $image, $path]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }

    private function imageExists(string $image): bool
    {
        $process = new Process(['docker', 'image', 'inspect', $image]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    private function imageCreatedAt(string $image): ?int
    {
        $process = new Process(['docker', 'image', 'inspect', '--format', '{{.Created}}', $image]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $created = strtotime(trim($process->getOutput()));

        return $created === false ? null : $created;
    }

    /**
     * @param list<StaleBuildFinding> $findings
     * @return list<StaleBuildFinding>
     */
    private function dedupeFindings(array $findings): array
    {
        $seen = [];
        $unique = [];

        foreach ($findings as $finding) {
            $key = implode('|', [
                $finding->service,
                $finding->reason,
                $finding->hostPath,
                $finding->imagePath ?? '',
                implode(',', $finding->composeInputPaths),
            ]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }

    /**
     * @return list<string>
     */
    private function builtServiceNames(string $composeFile): array
    {
        $content = file_get_contents($composeFile);
        if ($content === false) {
            return [];
        }

        $config = Yaml::parse($content);
        if (!is_array($config) || !isset($config['services']) || !is_array($config['services'])) {
            return [];
        }

        $names = [];
        foreach ($config['services'] as $name => $service) {
            if (is_array($service) && isset($service['build']) && !isset($service['image'])) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function composeInputsChanged(string $composeFile, int $imageCreatedAt): array
    {
        $changed = $this->composeBuildBaseline->changedInputs($composeFile);
        if ($changed !== []) {
            return $changed;
        }

        if ($this->composeBuildBaseline->hasBaseline($composeFile)) {
            return [];
        }

        return $this->composeInputsNewerThanImageByMtime($composeFile, $imageCreatedAt);
    }

    /**
     * @return list<string>
     */
    private function composeInputsNewerThanImageByMtime(string $composeFile, int $imageCreatedAt): array
    {
        $changed = [];

        foreach (ComposeFiles::allInputFiles($composeFile) as $path) {
            if (ComposeFiles::isGeneratedOverride($path)) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime === false || $mtime <= $imageCreatedAt) {
                continue;
            }

            $changed[] = $this->relativeComposePath($composeFile, $path);
        }

        sort($changed);

        return $changed;
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

<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Detects when a locally cached Docker image was built from older Dockerfile
 * or entrypoint scripts than the current project tree.
 *
 * Only COPY'd boot scripts (entrypoint, service entrypoint overrides) are
 * compared, because docker-compose.yml changes are runtime config and do not
 * require an image rebuild.
 */
class ImageBuildFreshnessChecker
{
    public function __construct(
        private readonly DockerfileBuildContextParser $dockerfileParser = new DockerfileBuildContextParser(),
        private readonly BuildFingerprint $fingerprint = new BuildFingerprint(),
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

            $fingerprintFinding = $this->fingerprintFinding($composeFile, $service, $image);
            if ($fingerprintFinding !== null) {
                $findings[] = $fingerprintFinding;
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
        $lines[] = 'Run `ngramx rebuild` to bake the latest Dockerfile and entrypoint into the image before starting.';

        return implode("\n", $lines);
    }

    public function contentsMatch(string $hostPath, string $imageContents): bool
    {
        $hostContents = file_get_contents($hostPath);
        if ($hostContents === false) {
            return true;
        }

        return hash_equals(
            hash('sha256', $this->normalizeScriptContent($hostContents)),
            hash('sha256', $this->normalizeScriptContent($imageContents))
        );
    }

    private function normalizeScriptContent(string $content): string
    {
        $normalized = str_replace("\r\n", "\n", $content);

        return rtrim($normalized, "\n") . "\n";
    }

    /**
     * Compare the Dockerfile fingerprint baked into the image against the
     * Dockerfile on disk.
     *
     * Returns null — deliberately, not a finding — when the image carries no
     * fingerprint label. Images built before this check existed, or built
     * outside Ngramx, simply have nothing to compare; reporting them as stale
     * would flag every pre-existing installation on first upgrade. They pick up
     * a fingerprint on their next rebuild.
     */
    private function fingerprintFinding(string $composeFile, string $serviceName, string $image): ?StaleBuildFinding
    {
        $service = $this->serviceConfig($composeFile, $serviceName);
        if ($service === null) {
            return null;
        }

        $dockerfilePath = $this->fingerprint->dockerfilePathFor($composeFile, $service);
        if ($dockerfilePath === null) {
            return null;
        }

        $labels = $this->readLabelsFromImage($image);
        $bakedSha = $labels[BuildFingerprint::LABEL_DOCKERFILE_SHA] ?? null;
        if (!is_string($bakedSha) || $bakedSha === '') {
            return null;
        }

        $currentSha = $this->fingerprint->dockerfileSha($dockerfilePath);
        if ($currentSha === null || hash_equals($bakedSha, $currentSha)) {
            return null;
        }

        // The Dockerfile changed. Prefer the sharper "base image changed"
        // message when the `FROM` line is what moved, since that is the case
        // whose downstream symptoms are least recognisable.
        $bakedFrom = $labels[BuildFingerprint::LABEL_FROM] ?? null;
        $currentFrom = implode(',', $this->fingerprint->fromReferences($dockerfilePath));

        if (is_string($bakedFrom) && $bakedFrom !== '' && $currentFrom !== '' && $bakedFrom !== $currentFrom) {
            return new StaleBuildFinding(
                service: $serviceName,
                image: $image,
                reason: StaleBuildFinding::REASON_BASE_IMAGE_CHANGED,
                hostPath: $dockerfilePath,
                previousFrom: $bakedFrom,
                currentFrom: $currentFrom,
            );
        }

        return new StaleBuildFinding(
            service: $serviceName,
            image: $image,
            reason: StaleBuildFinding::REASON_DOCKERFILE_CHANGED,
            hostPath: $dockerfilePath,
        );
    }

    /**
     * @return array<string, string>
     */
    private function readLabelsFromImage(string $image): array
    {
        $process = new Process(['docker', 'image', 'inspect', $image, '--format', '{{json .Config.Labels}}']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!is_array($decoded)) {
            return [];
        }

        $labels = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $labels[$key] = $value;
            }
        }

        return $labels;
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
        return $this->fingerprint->dockerfilePathFor($composeFile, $service);
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
}

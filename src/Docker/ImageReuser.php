<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Lets a worktree environment reuse an image that has already been built for the
 * main checkout instead of rebuilding it from scratch.
 *
 * Docker Compose names a built image `<project>-<service>` when the service has
 * no explicit `image:` key. Because each worktree runs under its own project
 * name, Compose would otherwise build a brand-new image per worktree. By tagging
 * the parent project's existing image to the name the worktree's Compose project
 * expects, `docker compose up` finds the image already present and skips the build.
 *
 * Services that declare an explicit `image:` already share a tag across projects,
 * so they need no help here and are skipped.
 */
class ImageReuser
{
    public function __construct(
        private readonly ImageBuildFreshnessChecker $freshnessChecker = new ImageBuildFreshnessChecker(),
    ) {
    }

    /**
     * Reuse already-built images for built-from-source services.
     *
     * @param list<string> $sourceProjects Candidate project names whose images may already exist, in priority order
     * @return list<string> Names of services whose image was reused (tagged or already present)
     */
    public function reuse(string $composeFile, array $sourceProjects, string $targetProject): array
    {
        $reused = [];

        foreach ($this->builtServiceNames($composeFile) as $service) {
            $target = $targetProject . '-' . $service;

            // A previous run for this same worktree may already have the image.
            if ($this->imageExists($target) && !$this->freshnessChecker->isServiceImageStale($composeFile, $targetProject, $service)) {
                $reused[] = $service;
                continue;
            }

            foreach ($sourceProjects as $sourceProject) {
                $source = $sourceProject . '-' . $service;
                if ($source === $target || !$this->imageExists($source)) {
                    continue;
                }

                if ($this->freshnessChecker->isServiceImageStale($composeFile, $sourceProject, $service)) {
                    break;
                }

                if ($this->tagImage($source, $target)) {
                    $reused[] = $service;
                }
                break;
            }
        }

        return $reused;
    }

    /**
     * @return list<StaleBuildFinding>
     */
    public function findStaleBuildInputs(string $composeFile, string $targetProject): array
    {
        return $this->freshnessChecker->findStaleBuildInputs($composeFile, $targetProject);
    }

    public function formatStaleBuildAdvisory(string $composeFile, string $targetProject): string
    {
        return $this->freshnessChecker->formatAdvisory(
            $this->findStaleBuildInputs($composeFile, $targetProject)
        );
    }

    /**
     * Names of services that are built from source (have `build:`) and do not
     * pin an explicit `image:` tag.
     *
     * @return list<string>
     */
    public function builtServiceNames(string $composeFile): array
    {
        if (!file_exists($composeFile)) {
            return [];
        }

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

    private function imageExists(string $image): bool
    {
        $process = new Process(['docker', 'image', 'inspect', $image]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    private function tagImage(string $source, string $target): bool
    {
        $process = new Process(['docker', 'tag', $source, $target]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }
}

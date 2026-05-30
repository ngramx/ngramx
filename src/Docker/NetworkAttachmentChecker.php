<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Process;

/**
 * Detect the "container is running but has zero networks attached" desync
 * that Docker Desktop occasionally produces — usually after the daemon or a
 * `docker network rm` runs while a container persisted across the change.
 *
 * Symptoms are catastrophic but quiet: peer containers can't resolve the
 * service's name (e.g. an app container's `getent hosts db` returns
 * nothing), entrypoints sit in wait-for-db loops, php-fpm never starts,
 * nginx hands out 502s. Docker reports every container as `running`, so
 * ngramx's existing readiness checks have no way to spot it.
 *
 * This checker enumerates each compose service, asks Docker for the
 * container's real network attachment, and reports any service that's
 * running but flying solo. Recovery is a single `compose rm -sf $service`
 * followed by `compose up -d $service`, which forces the container to be
 * re-created and re-attached.
 */
class NetworkAttachmentChecker
{
    public function __construct(
        private readonly DockerCompose $dockerCompose = new DockerCompose(),
    ) {
    }

    /**
     * Inspect every compose service and return one report per service that
     * is running but has no networks attached. Services that aren't running,
     * or that were intentionally configured with `network_mode: host|none`,
     * are skipped.
     *
     * @return list<NetworkAttachmentIssue>
     */
    public function checkAll(string $composeFile, ?string $namespace = null): array
    {
        $services = $this->dockerCompose->listServices($composeFile, $namespace);
        if ($services === []) {
            return [];
        }

        $issues = [];
        foreach ($services as $service) {
            $issue = $this->checkService($composeFile, $service, $namespace);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    public function checkService(string $composeFile, string $service, ?string $namespace = null): ?NetworkAttachmentIssue
    {
        $containerId = $this->getContainerId($composeFile, $service, $namespace);
        if ($containerId === null) {
            return null;
        }

        $inspection = $this->inspectContainer($containerId);
        if ($inspection === null) {
            return null;
        }

        [$status, $networkMode, $networks] = $inspection;

        if ($status !== 'running') {
            return null;
        }

        if (in_array($networkMode, ['host', 'none'], true)) {
            return null;
        }

        if ($networks !== []) {
            return null;
        }

        return new NetworkAttachmentIssue(
            service: $service,
            containerId: $containerId,
            declaredNetworkMode: $networkMode,
        );
    }

    /**
     * @return string|null container id, or null if not running
     */
    private function getContainerId(string $composeFile, string $service, ?string $namespace): ?string
    {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($namespace !== null) {
            $command[] = '-p';
            $command[] = $namespace;
        }

        $command = array_merge($command, ['ps', '-q', $service]);

        $process = new Process($command);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $id = trim($process->getOutput());
        return $id === '' ? null : $id;
    }

    /**
     * Run `docker inspect` and return [status, networkMode, networks].
     *
     * The format string emits three tab-separated fields so we can split
     * deterministically even when network names contain whitespace or JSON
     * could otherwise confuse single-field parsing.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}|null
     */
    private function inspectContainer(string $containerId): ?array
    {
        $process = new Process([
            'docker',
            'inspect',
            '--format',
            '{{.State.Status}}'."\t".'{{.HostConfig.NetworkMode}}'."\t".'{{json .NetworkSettings.Networks}}',
            $containerId,
        ]);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $parts = explode("\t", trim($process->getOutput()), 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$status, $networkMode, $networksJson] = $parts;

        /** @var array<string, mixed>|null $networks */
        $networks = json_decode($networksJson, true);
        if (!is_array($networks)) {
            $networks = [];
        }

        return [$status, $networkMode, $networks];
    }
}

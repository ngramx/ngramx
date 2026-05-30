<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Symfony\Component\Process\Process;

class HealthChecker
{
    /**
     * Check if a service is healthy
     */
    public function isHealthy(string $composeFile, string $service, ?string $projectName = null): bool
    {
        $status = $this->getHealthStatus($composeFile, $service, $projectName);
        return $status === 'healthy' || $status === 'running';
    }

    /**
     * Wait for a service to become healthy
     *
     * @throws ServiceNotHealthyException
     */
    public function waitForHealth(string $composeFile, string $service, int $timeout, ?string $projectName = null): void
    {
        $startTime = time();
        $pollInterval = 2; // Check every 2 seconds

        while (true) {
            if ($this->isHealthy($composeFile, $service, $projectName)) {
                return;
            }

            $elapsed = time() - $startTime;
            if ($elapsed >= $timeout) {
                $projectFlag = $projectName ? " -p $projectName" : '';
                throw new ServiceNotHealthyException(
                    "Service '$service' did not become healthy within {$timeout}s. " .
                    "Check logs with: docker-compose -f $composeFile$projectFlag logs $service"
                );
            }

            sleep($pollInterval);
        }
    }

    /**
     * Get the health status of a service
     *
     * Returns the Docker healthcheck status (`healthy`, `unhealthy`, `starting`)
     * when the container declares a healthcheck, otherwise the raw container
     * state (`running`, `exited`, `restarting`, ...). Returns `unknown` when no
     * container exists for the service or it cannot be inspected.
     *
     * @return string Status: 'healthy', 'unhealthy', 'starting', 'running', 'exited', 'unknown'
     */
    public function getHealthStatus(string $composeFile, string $service, ?string $projectName = null): string
    {
        $containerId = $this->getContainerId($composeFile, $service, $projectName);
        if ($containerId === null) {
            return 'unknown';
        }

        $status = $this->inspect(
            $containerId,
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}'
        );

        return $status ?? 'unknown';
    }

    /**
     * Whether the service's container declares a Docker healthcheck.
     */
    public function hasHealthcheck(string $composeFile, string $service, ?string $projectName = null): bool
    {
        $containerId = $this->getContainerId($composeFile, $service, $projectName);
        if ($containerId === null) {
            return false;
        }

        $value = $this->inspect($containerId, '{{if .State.Health}}yes{{else}}no{{end}}');

        return $value === 'yes';
    }

    /**
     * Get the raw container state (`running`, `restarting`, `exited`, `dead`,
     * `created`, `paused`). Returns `unknown` when no container exists.
     */
    public function getContainerState(string $composeFile, string $service, ?string $projectName = null): string
    {
        $containerId = $this->getContainerId($composeFile, $service, $projectName);
        if ($containerId === null) {
            return 'unknown';
        }

        return $this->inspect($containerId, '{{.State.Status}}') ?? 'unknown';
    }

    /**
     * Get the container's restart count. A climbing restart count is a strong
     * signal that a container is crash-looping even while Docker still reports
     * it as `running` between restarts. Returns 0 when unavailable.
     */
    public function getRestartCount(string $composeFile, string $service, ?string $projectName = null): int
    {
        $containerId = $this->getContainerId($composeFile, $service, $projectName);
        if ($containerId === null) {
            return 0;
        }

        $value = $this->inspect($containerId, '{{.RestartCount}}');
        if ($value === null || !ctype_digit($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * Resolve the container ID backing a compose service, or null when none
     * exists yet.
     */
    private function getContainerId(string $composeFile, string $service, ?string $projectName = null): ?string
    {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command = array_merge($command, ['ps', '-q', $service]);

        $process = new Process($command);
        $process->run();

        $containerId = trim($process->getOutput());

        // `ps -q` can return multiple IDs for scaled services; the first is enough.
        if ($containerId !== '' && str_contains($containerId, "\n")) {
            $containerId = trim(strtok($containerId, "\n") ?: '');
        }

        return $containerId === '' ? null : $containerId;
    }

    /**
     * Run `docker inspect` with the given Go template, returning the trimmed
     * output or null on failure.
     */
    private function inspect(string $containerId, string $format): ?string
    {
        $process = new Process(['docker', 'inspect', '--format', $format, $containerId]);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }
}

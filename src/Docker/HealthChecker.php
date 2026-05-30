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
     * @return string Status: 'healthy', 'unhealthy', 'starting', 'running', 'exited', 'unknown'
     */
    public function getHealthStatus(string $composeFile, string $service, ?string $projectName = null): string
    {
        // First, get the container name for this service
        $command = ['docker-compose', '-f', $composeFile];

        // Add override file if it exists
        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $command[] = '-f';
            $command[] = $overrideFile;
        }

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command = array_merge($command, ['ps', '-q', $service]);

        $process = new Process($command);
        $process->run();

        $containerId = trim($process->getOutput());
        if (empty($containerId)) {
            return 'unknown';
        }

        // Check if container has healthcheck
        $process = new Process([
            'docker',
            'inspect',
            '--format',
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}',
            $containerId,
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            return 'unknown';
        }

        return trim($process->getOutput());
    }
}

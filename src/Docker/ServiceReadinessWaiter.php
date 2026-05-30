<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

/**
 * Waits for a set of Docker Compose services to become healthy while
 * rendering a live, in-place status panel with rolling log excerpts.
 *
 * Each service shows a single status line followed by up to {@see LOG_LINES}
 * lines of recent container output (dim grey). Once a service is healthy the
 * log excerpt for it disappears, leaving only the final status.
 */
class ServiceReadinessWaiter
{
    private const LOG_LINES = 3;
    private const POLL_INTERVAL_SECONDS = 2;

    /**
     * Container states that indicate a service has failed or is crash-looping
     * and will not recover on its own.
     */
    private const FAILED_STATES = ['restarting', 'exited', 'dead', 'unhealthy'];

    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * Poll each service until healthy or timed out, rendering live status.
     *
     * In parallel, every service listed in $monitorServices is checked on each
     * poll for crash-loop states. If any monitored service enters a failed
     * state (see {@see FAILED_STATES}) we abort immediately with a pointer to
     * that service's logs, rather than silently declaring the environment
     * ready when a non-waited-for service is dead.
     *
     * @param ServiceWaitConfig[] $waitFor
     * @param list<string>        $monitorServices Services whose failure should
     *     abort the wait immediately. Typically every compose service that is
     *     not already in $waitFor.
     *
     * @throws ServiceNotHealthyException if any service exceeds its (possibly
     *         multiplied) timeout, or if any monitored service is failing.
     */
    public function waitForAll(
        string $composeFile,
        array $waitFor,
        ?string $namespace = null,
        int $timeoutMultiplier = 1,
        array $monitorServices = []
    ): void {
        if ($waitFor === [] && $monitorServices === []) {
            return;
        }

        if ($waitFor === []) {
            $this->verifyNoServicesFailed($composeFile, $monitorServices, $namespace);
            return;
        }

        $section = $this->formatter->createSection();

        $serviceState = [];
        $startTimes = [];
        $resolvedTimes = [];

        foreach ($waitFor as $waitConfig) {
            $serviceState[$waitConfig->service] = [
                'status' => 'waiting',
                'elapsed' => null,
                'logLines' => [],
            ];
            $startTimes[$waitConfig->service] = microtime(true);
        }

        while (true) {
            // Before waiting another cycle, verify no monitored (non-waited-for)
            // service has entered a failed state. This catches crash-looping
            // containers like an nginx whose upstream app never became reachable.
            if ($monitorServices !== []) {
                $this->verifyNoServicesFailed($composeFile, $monitorServices, $namespace);
            }

            $allHealthy = true;

            foreach ($waitFor as $waitConfig) {
                $service = $waitConfig->service;

                if (isset($resolvedTimes[$service])) {
                    continue;
                }

                $status = $this->healthChecker->getHealthStatus($composeFile, $service, $namespace);
                $isHealthy = ($status === 'healthy' || $status === 'running');

                if ($isHealthy) {
                    $elapsed = microtime(true) - $startTimes[$service];
                    $resolvedTimes[$service] = $elapsed;
                    $serviceState[$service] = [
                        'status' => $status,
                        'elapsed' => $elapsed,
                        'logLines' => [],
                    ];
                    continue;
                }

                $allHealthy = false;
                $logLines = $this->dockerCompose->getLatestLogLines(
                    $composeFile,
                    $service,
                    self::LOG_LINES,
                    $namespace
                );
                $serviceState[$service] = [
                    'status' => $status !== '' ? $status : 'waiting',
                    'elapsed' => null,
                    'logLines' => $logLines,
                ];

                $effectiveTimeout = $waitConfig->timeout * $timeoutMultiplier;
                $elapsed = microtime(true) - $startTimes[$service];
                if ($elapsed >= $effectiveTimeout) {
                    if ($section !== null) {
                        $this->formatter->renderServiceStatus($section, $serviceState);
                    }

                    $projectFlag = $namespace !== null ? " -p $namespace" : '';
                    throw new ServiceNotHealthyException(
                        "Service '$service' did not become healthy within {$effectiveTimeout}s. " .
                        "Check logs with: docker-compose -f $composeFile$projectFlag logs $service"
                    );
                }
            }

            if ($section !== null) {
                $this->formatter->renderServiceStatus($section, $serviceState);
            } elseif ($allHealthy) {
                // Non-interactive fallback: print once when everything is ready.
                foreach ($serviceState as $name => $info) {
                    $elapsed = $info['elapsed'] ?? 0.0;
                    $this->formatter->info(sprintf('%s (%s after %.1fs)', $name, $info['status'], $elapsed));
                }
            }

            if ($allHealthy) {
                break;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        if ($section instanceof ConsoleSectionOutput) {
            // Render the final healthy state persistently (no log excerpts)
            // then leave it in the scroll-back.
            $this->formatter->renderServiceStatus($section, $serviceState);
        }

        // After wait_for completes, do a final sweep across every monitored
        // service so any container that crashed right as the wait ended is
        // reported instead of masked by a success message.
        if ($monitorServices !== []) {
            $this->verifyNoServicesFailed($composeFile, $monitorServices, $namespace);
        }
    }

    /**
     * One-shot verification that no service is in a crash-loop or terminal
     * failure state. Throws on the first failure so the caller can surface
     * a single, actionable error.
     *
     * @param list<string> $serviceNames
     *
     * @throws ServiceNotHealthyException if any service is in a state listed
     *         in {@see FAILED_STATES}.
     */
    public function verifyNoServicesFailed(
        string $composeFile,
        array $serviceNames,
        ?string $namespace = null
    ): void {
        if ($serviceNames === []) {
            return;
        }

        $failed = [];
        foreach ($serviceNames as $service) {
            $status = $this->healthChecker->getHealthStatus($composeFile, $service, $namespace);
            if (in_array($status, self::FAILED_STATES, true)) {
                $failed[$service] = $status;
            }
        }

        if ($failed === []) {
            return;
        }

        $details = [];
        foreach ($failed as $service => $status) {
            $details[] = "$service ($status)";
        }

        $projectFlag = $namespace !== null ? " -p $namespace" : '';
        $firstService = array_key_first($failed);

        throw new ServiceNotHealthyException(
            'One or more services are in a failed state: ' . implode(', ', $details) . '. ' .
            "Check logs with: docker-compose -f $composeFile$projectFlag logs $firstService"
        );
    }
}

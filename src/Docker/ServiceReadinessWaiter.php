<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

/**
 * Waits for a set of Docker Compose services to become genuinely ready while
 * rendering a live, in-place status panel with rolling log excerpts.
 *
 * "Ready" is no longer simply "the container is running". For each service the
 * waiter evaluates a configurable readiness probe, in priority order:
 *
 *   1. Docker healthcheck reaching `healthy` (when `healthcheck: true` is set
 *      and the container declares one, or auto-detected when nothing else is
 *      configured).
 *   2. A `ready_command` that must exit 0 inside the container.
 *   3. A `ready_log` regex matched against the container's recent logs.
 *   4. Fallback to the weak "container is running" signal — with a warning that
 *      readiness is not actually being verified.
 *
 * While waiting, target and monitored containers are watched for crash loops
 * (Restarting/Exited/Dead state or a climbing restart count). On a crash the
 * wait aborts immediately, dumps the container's recent logs and throws, rather
 * than silently retrying forever or firing exec commands at a dead container.
 */
class ServiceReadinessWaiter
{
    private const LOG_LINES = 3;

    /** Number of log lines dumped when a service crashes or times out. */
    private const CRASH_LOG_LINES = 50;

    /** Backoff bounds for the readiness poll loop (seconds): 1s → 2s → … capped. */
    private const INITIAL_POLL_SECONDS = 1;
    private const MAX_POLL_SECONDS = 5;

    /** Timeout for an individual `ready_command` probe exec. */
    private const READY_COMMAND_TIMEOUT = 15;

    /**
     * Container states that indicate a service has failed or is crash-looping
     * and will not recover on its own.
     */
    private const FAILED_STATES = ['restarting', 'exited', 'dead', 'unhealthy'];

    /** Raw container states that mean the container is crash-looping/dead. */
    private const CRASH_STATES = ['restarting', 'exited', 'dead'];

    private readonly ContainerExecutor $containerExecutor;

    /** @var array<string, true> Services we've already warned have weak readiness. */
    private array $warnedWeak = [];

    /** @var array<string, true> Services warned about a missing-but-requested healthcheck. */
    private array $warnedMissingHealthcheck = [];

    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly OutputFormatter $formatter,
        ?ContainerExecutor $containerExecutor = null,
    ) {
        $this->containerExecutor = $containerExecutor ?? new ContainerExecutor();
    }

    /**
     * Poll each service until ready or timed out, rendering live status.
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
     *         multiplied) timeout, crash-loops, or if any monitored service is
     *         failing.
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
        $baselineRestarts = [];

        foreach ($waitFor as $waitConfig) {
            $serviceState[$waitConfig->service] = [
                'status' => 'waiting',
                'elapsed' => null,
                'logLines' => [],
            ];
            $startTimes[$waitConfig->service] = microtime(true);
            $baselineRestarts[$waitConfig->service] = $this->healthChecker->getRestartCount(
                $composeFile,
                $waitConfig->service,
                $namespace
            );
        }

        $interval = self::INITIAL_POLL_SECONDS;

        while (true) {
            // Before waiting another cycle, verify no monitored (non-waited-for)
            // service has entered a failed state. This catches crash-looping
            // containers like an nginx whose upstream app never became reachable.
            if ($monitorServices !== []) {
                $this->verifyNoServicesFailed($composeFile, $monitorServices, $namespace);
            }

            $allReady = true;

            foreach ($waitFor as $waitConfig) {
                $service = $waitConfig->service;

                if (isset($resolvedTimes[$service])) {
                    continue;
                }

                $state = $this->healthChecker->getContainerState($composeFile, $service, $namespace);
                $restarts = $this->healthChecker->getRestartCount($composeFile, $service, $namespace);

                // Fail fast: a waited-for container that is crash-looping will
                // never become ready, so abort with its logs instead of
                // burning the entire timeout budget.
                $crash = $this->crashReason($state, $restarts, $baselineRestarts[$service]);
                if ($crash !== null) {
                    $serviceState[$service] = [
                        'status' => $state,
                        'elapsed' => null,
                        'logLines' => $this->dockerCompose->getLatestLogLines($composeFile, $service, self::LOG_LINES, $namespace),
                    ];
                    if ($section !== null) {
                        $this->formatter->renderServiceStatus($section, $serviceState);
                    }

                    throw new ServiceNotHealthyException(
                        $this->buildFailureMessage($composeFile, $service, $namespace, "is crash-looping ($crash)")
                    );
                }

                if ($this->isServiceReady($composeFile, $waitConfig, $namespace, warnWeak: true)) {
                    $elapsed = microtime(true) - $startTimes[$service];
                    $resolvedTimes[$service] = $elapsed;
                    $serviceState[$service] = [
                        'status' => 'ready',
                        'elapsed' => $elapsed,
                        'logLines' => [],
                    ];
                    continue;
                }

                $allReady = false;
                $logLines = $this->dockerCompose->getLatestLogLines(
                    $composeFile,
                    $service,
                    self::LOG_LINES,
                    $namespace
                );
                $serviceState[$service] = [
                    'status' => $state !== '' && $state !== 'unknown' ? $state : 'waiting',
                    'elapsed' => null,
                    'logLines' => $logLines,
                ];

                $effectiveTimeout = $waitConfig->timeout * $timeoutMultiplier;
                $elapsed = microtime(true) - $startTimes[$service];
                if ($elapsed >= $effectiveTimeout) {
                    if ($section !== null) {
                        $this->formatter->renderServiceStatus($section, $serviceState);
                    }

                    throw new ServiceNotHealthyException(
                        $this->buildFailureMessage(
                            $composeFile,
                            $service,
                            $namespace,
                            "did not become ready within {$effectiveTimeout}s"
                        )
                    );
                }
            }

            if ($section !== null) {
                $this->formatter->renderServiceStatus($section, $serviceState);
            } elseif ($allReady) {
                // Non-interactive fallback: print once when everything is ready.
                foreach ($serviceState as $name => $info) {
                    $elapsed = $info['elapsed'] ?? 0.0;
                    $this->formatter->info(sprintf('%s (%s after %.1fs)', $name, $info['status'], $elapsed));
                }
            }

            if ($allReady) {
                break;
            }

            sleep($interval);
            $interval = min(self::MAX_POLL_SECONDS, $interval * 2);
        }

        if ($section instanceof ConsoleSectionOutput) {
            // Render the final ready state persistently (no log excerpts)
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
     * Block until a single service passes its readiness probe, failing fast and
     * loud if the container is missing or crash-looping. Used as a gate before
     * running post-up commands (`fresh`/`clear`/custom) so we never fire
     * `docker compose exec` at a container that isn't actually usable yet.
     *
     * @throws ServiceNotHealthyException on timeout, crash loop, or a missing
     *         container.
     */
    public function waitForReady(
        string $composeFile,
        ServiceWaitConfig $config,
        ?string $namespace = null,
        int $timeoutMultiplier = 1,
        bool $warnWeak = false
    ): void {
        $service = $config->service;
        $start = microtime(true);
        $effectiveTimeout = $config->timeout * max(1, $timeoutMultiplier);
        $baselineRestarts = $this->healthChecker->getRestartCount($composeFile, $service, $namespace);
        $interval = self::INITIAL_POLL_SECONDS;
        $announcedWait = false;
        $sawContainer = false;

        while (true) {
            $state = $this->healthChecker->getContainerState($composeFile, $service, $namespace);

            if ($state === 'unknown') {
                if (!$sawContainer) {
                    throw new ServiceNotHealthyException(
                        "Service '$service' is not running. Start the environment with `ngramx up` first."
                    );
                }

                throw new ServiceNotHealthyException(
                    $this->buildFailureMessage(
                        $composeFile,
                        $service,
                        $namespace,
                        'stopped unexpectedly while waiting for it to become ready'
                    )
                );
            }

            $sawContainer = true;

            $restarts = $this->healthChecker->getRestartCount($composeFile, $service, $namespace);
            $crash = $this->crashReason($state, $restarts, $baselineRestarts);
            if ($crash !== null) {
                throw new ServiceNotHealthyException(
                    $this->buildFailureMessage($composeFile, $service, $namespace, "is crash-looping ($crash)")
                );
            }

            if ($this->isServiceReady($composeFile, $config, $namespace, $warnWeak)) {
                return;
            }

            if ((microtime(true) - $start) >= $effectiveTimeout) {
                throw new ServiceNotHealthyException(
                    $this->buildFailureMessage(
                        $composeFile,
                        $service,
                        $namespace,
                        "did not become ready within {$effectiveTimeout}s"
                    )
                );
            }

            if (!$announcedWait) {
                $this->formatter->info("Waiting for service '$service' to become ready...");
                $announcedWait = true;
            }

            sleep($interval);
            $interval = min(self::MAX_POLL_SECONDS, $interval * 2);
        }
    }

    /**
     * Evaluate the configured readiness probe for a service, in priority order.
     */
    private function isServiceReady(
        string $composeFile,
        ServiceWaitConfig $config,
        ?string $namespace,
        bool $warnWeak
    ): bool {
        $service = $config->service;

        // (a) Docker healthcheck, when explicitly opted in.
        if ($config->healthcheck) {
            if ($this->healthChecker->hasHealthcheck($composeFile, $service, $namespace)) {
                return $this->healthChecker->getHealthStatus($composeFile, $service, $namespace) === 'healthy';
            }

            if (!isset($this->warnedMissingHealthcheck[$service])) {
                $this->warnedMissingHealthcheck[$service] = true;
                $this->formatter->warning(
                    "  ⚠ Service '$service' sets `healthcheck: true` but no Docker healthcheck is defined — using the next configured probe."
                );
            }
        }

        // (b) Readiness command must exit 0 inside the container.
        if ($config->readyCommand !== null) {
            return $this->containerExecutor->succeeds(
                $composeFile,
                $service,
                $config->readyCommand,
                self::READY_COMMAND_TIMEOUT,
                $namespace
            );
        }

        // (c) Sentinel log line/regex.
        if ($config->readyLog !== null) {
            $lines = $this->dockerCompose->getLatestLogLines($composeFile, $service, self::CRASH_LOG_LINES, $namespace);
            return $this->logMatches($config->readyLog, $lines);
        }

        // (d) Nothing explicitly configured. Prefer an auto-detected healthcheck
        //     so readiness still means "healthy" when the container declares one;
        //     otherwise fall back to the weak "running" signal with a warning.
        if (!$config->healthcheck && $this->healthChecker->hasHealthcheck($composeFile, $service, $namespace)) {
            return $this->healthChecker->getHealthStatus($composeFile, $service, $namespace) === 'healthy';
        }

        if ($warnWeak && !isset($this->warnedWeak[$service])) {
            $this->warnedWeak[$service] = true;
            $this->formatter->warning(
                "  ⚠ Service '$service' has no readiness probe configured — treating `running` as ready. " .
                'Add healthcheck/ready_command/ready_log under docker.wait_for for a real readiness check.'
            );
        }

        $status = $this->healthChecker->getHealthStatus($composeFile, $service, $namespace);
        return $status === 'healthy' || $status === 'running';
    }

    /**
     * Classify a crash-looping container from an already-fetched container
     * state and restart count: a terminal/restarting state, or a restart count
     * that has climbed above the baseline captured when the wait began. Returns
     * a human-readable reason, or null when healthy enough to keep waiting.
     */
    private function crashReason(string $state, int $restarts, int $baselineRestarts): ?string
    {
        if (in_array($state, self::CRASH_STATES, true)) {
            return $state;
        }

        if ($restarts > $baselineRestarts) {
            return "restart count climbed {$baselineRestarts} → {$restarts}";
        }

        return null;
    }

    /**
     * Match a sentinel regex against a set of log lines. The pattern is treated
     * as a PCRE body (no delimiters), using a control-character delimiter so the
     * user can freely use `/`, `#`, etc.
     *
     * @param list<string> $lines
     */
    private function logMatches(string $pattern, array $lines): bool
    {
        $regex = "\1" . str_replace("\1", '', $pattern) . "\1";

        foreach ($lines as $line) {
            if (@preg_match($regex, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compose a failure message that points the user at the failing service's
     * logs and includes the last {@see CRASH_LOG_LINES} lines inline.
     */
    private function buildFailureMessage(
        string $composeFile,
        string $service,
        ?string $namespace,
        string $reason
    ): string {
        $projectFlag = $namespace !== null ? " -p $namespace" : '';
        $message = "Service '$service' $reason. "
            . "Check logs with: docker-compose -f $composeFile$projectFlag logs $service";

        $logs = $this->dockerCompose->getLatestLogLines($composeFile, $service, self::CRASH_LOG_LINES, $namespace);
        if ($logs !== []) {
            $message .= "\n\nLast " . count($logs) . " log line(s) from '$service':\n  " . implode("\n  ", $logs);
        }

        return $message;
    }

    /**
     * One-shot verification that no service is in a crash-loop or terminal
     * failure state. Throws on the first failure so the caller can surface
     * a single, actionable error, including the failing service's logs.
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
        $firstService = (string) array_key_first($failed);

        $message = 'One or more services are in a failed state: ' . implode(', ', $details) . '. '
            . "Check logs with: docker-compose -f $composeFile$projectFlag logs $firstService";

        $logs = $this->dockerCompose->getLatestLogLines($composeFile, $firstService, self::CRASH_LOG_LINES, $namespace);
        if ($logs !== []) {
            $message .= "\n\nLast " . count($logs) . " log line(s) from '$firstService':\n  " . implode("\n  ", $logs);
        }

        throw new ServiceNotHealthyException($message);
    }
}

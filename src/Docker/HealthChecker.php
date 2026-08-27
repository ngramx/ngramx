<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Symfony\Component\Process\Process;

/**
 * Answers "what is this service's container doing?" for the readiness waiter
 * and anything else that needs to look at a running stack.
 *
 * Everything here is a *probe*: it is asked repeatedly, inside a loop that has
 * its own timeout budget, so a single unanswered question is never interesting
 * on its own. Two rules follow from that.
 *
 * **A probe never throws.** `Process::run()` throws when a command exceeds its
 * timeout, and with no timeout set Symfony's default is 60 seconds — so a busy
 * Docker daemon could abort `ngramx up` mid-wait with "The process ... exceeded
 * the timeout of 60 seconds", tearing down a stack whose containers were all
 * healthy. Probes are capped far shorter than that and report that they could
 * not tell, instead of exploding.
 *
 * **"Could not tell" is not "not there".** A failed probe returns
 * {@see self::STATE_UNAVAILABLE} or null, never `unknown` or 0, so a caller can
 * retry rather than conclude that the container vanished or that a
 * crash-looping one has settled down.
 */
class HealthChecker
{
    /**
     * Reported when a probe could not answer — a stalled daemon, or a command
     * that ran out of time. Distinct from `unknown`, which means Docker did
     * answer and has no container for this service.
     */
    public const STATE_UNAVAILABLE = 'unavailable';

    /**
     * Cap for a single probe. Deliberately short: these run in a poll loop, so
     * a slow answer is worth abandoning and asking for again, and the caller's
     * own timeout still governs how long we wait overall.
     */
    private const PROBE_TIMEOUT = 5;

    /**
     * Resolved container IDs, keyed by compose file + project + service.
     *
     * A container's ID does not change while it exists, but `docker-compose ps`
     * was being re-run for every question asked about it — several times per
     * service per poll, each one a fresh Compose CLI start-up against a daemon
     * already busy bringing the stack up. The ID is remembered, and dropped the
     * moment an inspect on it fails, which is what a recreated or removed
     * container looks like.
     *
     * @var array<string, string>
     */
    private array $containerIds = [];

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
     * container exists for the service, and {@see self::STATE_UNAVAILABLE} when
     * the probe itself could not answer.
     *
     * @return string Status: 'healthy', 'unhealthy', 'starting', 'running', 'exited', 'unknown', 'unavailable'
     */
    public function getHealthStatus(string $composeFile, string $service, ?string $projectName = null): string
    {
        return $this->probeState(
            $composeFile,
            $service,
            $projectName,
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}'
        );
    }

    /**
     * Whether the service's container declares a Docker healthcheck.
     *
     * A probe that cannot answer reports `false` — the caller falls back to
     * another readiness signal, which is the safe direction to be wrong in.
     */
    public function hasHealthcheck(string $composeFile, string $service, ?string $projectName = null): bool
    {
        $containerId = $this->getContainerId($composeFile, $service, $projectName);
        if ($containerId === null) {
            return false;
        }

        return $this->inspect($containerId, '{{if .State.Health}}yes{{else}}no{{end}}') === 'yes';
    }

    /**
     * Get the raw container state (`running`, `restarting`, `exited`, `dead`,
     * `created`, `paused`). Returns `unknown` when no container exists, and
     * {@see self::STATE_UNAVAILABLE} when the probe could not answer.
     */
    public function getContainerState(string $composeFile, string $service, ?string $projectName = null): string
    {
        return $this->probeState($composeFile, $service, $projectName, '{{.State.Status}}');
    }

    /**
     * Get the container's restart count. A climbing restart count is a strong
     * signal that a container is crash-looping even while Docker still reports
     * it as `running` between restarts.
     *
     * Returns null when the count could not be read. Callers must not read that
     * as zero: a failed probe reported as 0 would first hide a crash loop, then
     * invent one the moment the real count came back.
     */
    public function getRestartCount(string $composeFile, string $service, ?string $projectName = null): ?int
    {
        $containerId = $this->getContainerId($composeFile, $service, $projectName);
        if ($containerId === null) {
            return null;
        }

        $value = $this->inspect($containerId, '{{.RestartCount}}');
        if ($value === null || $value === '') {
            // The ID we held is no longer inspectable — recreated or removed.
            $this->forgetContainerId($composeFile, $service, $projectName);
            return null;
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Inspect one field of the service's container, mapping the two ways of
     * having no answer onto the two states that mean different things.
     */
    private function probeState(string $composeFile, string $service, ?string $projectName, string $format): string
    {
        $probeFailed = false;
        $containerId = $this->getContainerId($composeFile, $service, $projectName, $probeFailed);
        if ($containerId === null) {
            return $probeFailed ? self::STATE_UNAVAILABLE : 'unknown';
        }

        $unanswered = false;
        $value = $this->inspect($containerId, $format, $unanswered);
        if ($value === null || $value === '') {
            // The ID we held did not answer: either the container was recreated
            // or removed (a real answer — `unknown`, as before), or the daemon
            // did not reply in time (worth asking again). Either way the cached
            // ID is suspect, so drop it and resolve afresh next poll.
            $this->forgetContainerId($composeFile, $service, $projectName);

            return $unanswered ? self::STATE_UNAVAILABLE : 'unknown';
        }

        return $value;
    }

    /**
     * Resolve the container ID backing a compose service, or null when none
     * exists yet or the lookup could not be completed.
     *
     * Cached: this is the expensive question — a Compose CLI invocation — and
     * the answer is stable for the life of the container.
     *
     * @param bool $probeFailed Set to true when the lookup itself failed, as
     *                          opposed to succeeding and finding no container.
     */
    private function getContainerId(
        string $composeFile,
        string $service,
        ?string $projectName = null,
        bool &$probeFailed = false
    ): ?string {
        $key = $this->cacheKey($composeFile, $service, $projectName);
        $probeFailed = false;

        if (isset($this->containerIds[$key])) {
            return $this->containerIds[$key];
        }

        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command = array_merge($command, ['ps', '-q', $service]);

        $unanswered = false;
        $output = $this->runProbe($command, $unanswered);

        if ($output === null) {
            $probeFailed = $unanswered;
            return null;
        }

        $containerId = trim($output);

        // `ps -q` can return multiple IDs for scaled services; the first is enough.
        if ($containerId !== '' && str_contains($containerId, "\n")) {
            $containerId = trim(strtok($containerId, "\n") ?: '');
        }

        if ($containerId === '') {
            return null;
        }

        return $this->containerIds[$key] = $containerId;
    }

    private function forgetContainerId(string $composeFile, string $service, ?string $projectName): void
    {
        unset($this->containerIds[$this->cacheKey($composeFile, $service, $projectName)]);
    }

    private function cacheKey(string $composeFile, string $service, ?string $projectName): string
    {
        return $composeFile . "\0" . ($projectName ?? '') . "\0" . $service;
    }

    /**
     * Run `docker inspect` with the given Go template, returning the trimmed
     * output or null when it could not be read.
     */
    private function inspect(string $containerId, string $format, bool &$unanswered = false): ?string
    {
        $output = $this->runProbe(['docker', 'inspect', '--format', $format, $containerId], $unanswered);

        return $output === null ? null : trim($output);
    }

    /**
     * Run a probe command, returning its output, or null when it failed.
     *
     * Every path out of here is a return, never a throw: a probe that cannot
     * answer must cost the caller one poll, not the whole environment.
     *
     * The two ways of failing are kept apart, because they deserve opposite
     * responses. A command that *answered*, non-zero — no such compose file, no
     * such service — is a real answer, and retrying will not change it. A
     * command that ran out of time or could not be started answered nothing at
     * all, and is worth asking again.
     *
     * @param list<string> $command
     * @param bool $unanswered Set to true when the probe timed out or could not
     *                         be started, as opposed to failing on its merits.
     */
    protected function runProbe(array $command, bool &$unanswered = false): ?string
    {
        $unanswered = false;

        $process = new Process($command);
        $process->setTimeout(self::PROBE_TIMEOUT);

        try {
            $process->run();
        } catch (\Throwable) {
            $unanswered = true;
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}

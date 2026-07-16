<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DockerCompose
{
    /**
     * Number of times to probe the Docker daemon before declaring it down.
     */
    private const DAEMON_PROBE_ATTEMPTS = 3;

    /**
     * Per-attempt timeout for the daemon probe, in seconds.
     */
    private const DAEMON_PROBE_TIMEOUT = 30;

    /**
     * Seconds to wait between daemon probes.
     */
    private const DAEMON_PROBE_BACKOFF = 2;

    /**
     * Check if the Docker daemon is running and accessible.
     *
     * Docker Desktop — especially under WSL2 — can take well over ten seconds
     * to answer the first `docker info` after the engine has been idle, while
     * the daemon spins back up. A single short-timeout probe then reports a
     * false "Docker is not running", which is exactly why re-running the same
     * command "just works" a moment later. To avoid that flake we probe a few
     * times with a generous timeout and a short backoff, giving a cold daemon
     * a fair chance to respond before we give up.
     *
     * A daemon that is genuinely down fails fast (connection refused) rather
     * than timing out, so the retry loop adds only a couple of seconds in the
     * true-negative case.
     */
    public function isDockerRunning(): bool
    {
        for ($attempt = 1; $attempt <= self::DAEMON_PROBE_ATTEMPTS; $attempt++) {
            $process = new Process(['docker', 'info']);
            $process->setTimeout(self::DAEMON_PROBE_TIMEOUT);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                // The daemon was reachable but slow to answer; fall through and
                // retry rather than reporting it as down.
            }

            if ($process->isSuccessful()) {
                return true;
            }

            if ($attempt < self::DAEMON_PROBE_ATTEMPTS) {
                sleep(self::DAEMON_PROBE_BACKOFF);
            }
        }

        return false;
    }

    /**
     * Check if Docker images already exist for this compose project.
     * Returns false on first run when everything needs to be built/pulled.
     */
    public function hasExistingImages(string $composeFile, ?string $projectName = null): bool
    {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'config';
        $command[] = '--images';

        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            // Don't silently assume "images exist" — that masks a broken
            // compose config and lies about first-run state. We can't
            // throw here without breaking back-compat with the existing
            // boolean contract, but we *can* tell the user something is
            // wrong instead of vanishing the failure.
            $this->warnAboutComposeFailure('config --images', $process->getErrorOutput());
            return true;
        }

        $imageNames = array_filter(explode("\n", trim($process->getOutput())));
        if (empty($imageNames)) {
            return true;
        }

        foreach ($imageNames as $image) {
            $inspect = new Process(['docker', 'image', 'inspect', $image]);
            $inspect->setTimeout(5);
            $inspect->run();

            if (!$inspect->isSuccessful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the latest log line from a service container.
     */
    public function getLatestLogLine(string $composeFile, string $service, ?string $projectName = null): ?string
    {
        $lines = $this->getLatestLogLines($composeFile, $service, 1, $projectName);
        return $lines[0] ?? null;
    }

    /**
     * Get the most recent log lines from a service container.
     *
     * Returns lines in chronological order (oldest first). When the service
     * has no logs yet or cannot be queried, returns an empty array.
     *
     * @return list<string>
     */
    public function getLatestLogLines(
        string $composeFile,
        string $service,
        int $lines = 3,
        ?string $projectName = null
    ): array {
        $lines = max(1, $lines);

        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'logs';
        $command[] = '--tail=' . $lines;
        $command[] = '--no-log-prefix';
        $command[] = $service;

        $process = new Process($command);
        $process->setTimeout(5);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $raw = $process->getOutput();
        if (trim($raw) === '') {
            $raw = $process->getErrorOutput();
        }

        $result = [];
        foreach (explode("\n", $raw) as $line) {
            $clean = trim($line);
            if ($clean !== '') {
                $result[] = $clean;
            }
        }

        return array_slice($result, -$lines);
    }

    /**
     * Start Docker Compose services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     * @param callable(string, string): void|null $outputCallback Optional streaming callback that
     *        receives (type, buffer) pairs from the underlying Process; use to drive a live log.
     * @throws \RuntimeException
     */
    public function up(
        string $composeFile,
        ?string $projectName = null,
        bool $rebuild = false,
        ?int $timeout = null,
        ?callable $outputCallback = null
    ): void {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'up';
        $command[] = '-d';

        if ($rebuild) {
            $command[] = '--build';
        }

        $effectiveTimeout = $timeout ?? ($rebuild ? 1800 : 300);

        $process = new Process($command);
        $process->setTimeout($effectiveTimeout);
        // Ensure docker-compose emits progress without buffering/TTY detection.
        $process->setEnv(['DOCKER_BUILDKIT_PROGRESS' => 'plain', 'BUILDKIT_PROGRESS' => 'plain']);

        try {
            $process->run($outputCallback);
        } catch (ProcessTimedOutException) {
            throw new \RuntimeException(
                "Docker Compose timed out after {$effectiveTimeout}s. Use --timeout to increase the limit (e.g. --timeout 3600)."
            );
        }

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to start Docker Compose services: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Rebuild images and start Docker Compose services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     * @param callable(string, string): void|null $outputCallback Optional streaming callback.
     * @throws \RuntimeException
     */
    public function upWithBuild(
        string $composeFile,
        ?string $projectName = null,
        ?callable $outputCallback = null
    ): void {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'up';
        $command[] = '-d';
        $command[] = '--build';

        $process = new Process($command);
        $process->setTimeout(1800);
        $process->setEnv(['DOCKER_BUILDKIT_PROGRESS' => 'plain', 'BUILDKIT_PROGRESS' => 'plain']);
        $process->run($outputCallback);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to rebuild Docker Compose services: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Forcefully recreate a single compose service.
     *
     * Used to recover from "container running but networks detached" desyncs
     * (see {@see NetworkAttachmentChecker}). Equivalent to
     * `docker compose rm -sf $service && docker compose up -d $service`,
     * but executed as a single orchestrated step so callers can wrap the
     * whole thing in one try/catch.
     *
     * @throws \RuntimeException When either subcommand fails.
     */
    public function recreateService(string $composeFile, string $service, ?string $projectName = null): void
    {
        $this->runComposeCommand(
            $composeFile,
            $projectName,
            ['rm', '-sf', $service],
            timeout: 60,
            failureLabel: "remove stale `$service`",
        );

        $this->runComposeCommand(
            $composeFile,
            $projectName,
            ['up', '-d', '--no-deps', $service],
            timeout: 120,
            failureLabel: "recreate `$service`",
        );
    }

    /**
     * Run an arbitrary docker-compose subcommand against the same compose
     * + override + project that the rest of this class uses, throwing on
     * non-zero exit.
     *
     * @param list<string> $subcommand The compose subcommand and its args, e.g. ['rm', '-sf', 'db'].
     */
    private function runComposeCommand(
        string $composeFile,
        ?string $projectName,
        array $subcommand,
        int $timeout,
        string $failureLabel,
    ): void {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        foreach ($subcommand as $part) {
            $command[] = $part;
        }

        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            throw new \RuntimeException(
                "Failed to $failureLabel" . ($stderr === '' ? '' : ": $stderr")
            );
        }
    }

    /**
     * Recreate the project's containers (docker compose up -d --force-recreate).
     *
     * Used instead of `docker compose restart` because a plain restart reuses
     * the mount spec captured at container creation. On Docker Desktop + WSL2,
     * host bind mounts are proxied through
     * /run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/<id>, and that id
     * goes stale when the source directory is deleted and recreated (e.g. a
     * git worktree removed and re-added) or when Docker Desktop/WSL restarts.
     * Restarting such a container fails with "no such file or directory";
     * recreating it rebinds the mount against the directory as it exists now,
     * while still making the proxy re-read a certificate that changed on disk.
     *
     * @throws \RuntimeException When the recreate fails.
     */
    public function forceRecreate(string $composeFile, ?string $projectName = null): void
    {
        $this->runComposeCommand(
            $composeFile,
            $projectName,
            ['up', '-d', '--force-recreate'],
            timeout: 300,
            failureLabel: 'recreate services',
        );
    }

    /**
     * Stop Docker Compose services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param bool $volumes Remove volumes as well
     * @param string|null $projectName Optional project name for container isolation
     * @param callable(string, string): void|null $outputCallback Optional streaming callback.
     * @throws \RuntimeException
     */
    public function down(
        string $composeFile,
        bool $volumes = false,
        ?string $projectName = null,
        ?callable $outputCallback = null
    ): void {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'down';

        if ($volumes) {
            $command[] = '-v';
        }

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run($outputCallback);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to stop Docker Compose services: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Stop and remove a compose project's containers, networks and (optionally)
     * volumes by project name alone — no compose file required.
     *
     * Compose reconstructs the project from container labels, so this works
     * even when the project's compose/override files no longer exist (e.g. a
     * worktree directory that has already been deleted). A project with no
     * resources is a warning-only no-op that still exits 0.
     *
     * @throws \RuntimeException When the teardown fails.
     */
    public function downProject(string $projectName, bool $volumes = false): void
    {
        $command = ['docker-compose', '-p', $projectName, 'down', '--remove-orphans'];

        if ($volumes) {
            $command[] = '-v';
        }

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to remove compose project '$projectName': {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * List every service declared in the compose file (whether running or not).
     *
     * Uses `docker-compose config --services`, which enumerates the services
     * defined in the merged compose files without touching their runtime state.
     * Returns an empty list if the compose file cannot be parsed.
     *
     * @return list<string>
     */
    public function listServices(string $composeFile, ?string $projectName = null): array
    {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'config';
        $command[] = '--services';

        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            // Returning `[]` here lets the caller continue, but it also
            // silently disables crash-loop detection and the network
            // reconciler downstream. Surface the underlying compose
            // error so the user can do something about it rather than
            // wondering why their broken compose file produced a
            // smoothly "successful" boot with none of the safety nets.
            $this->warnAboutComposeFailure('config --services', $process->getErrorOutput());
            return [];
        }

        $services = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $name = trim($line);
            if ($name !== '') {
                $services[] = $name;
            }
        }

        return $services;
    }

    /**
     * Emit a one-line stderr warning when a "shouldn't fail" subcommand
     * (compose config, compose ps -q, etc.) returned non-zero. We use PHP's
     * STDERR directly because this helper is several layers below the
     * OutputFormatter and we don't want to thread an output interface all
     * the way down for an exceptional-but-non-fatal case. The user still
     * sees the message because Symfony Console doesn't capture STDERR by
     * default.
     */
    private function warnAboutComposeFailure(string $subcommand, string $stderr): void
    {
        $stderr = trim($stderr);
        $detail = $stderr === '' ? '(no stderr)' : $stderr;
        if (defined('STDERR') && is_resource(STDERR)) {
            fwrite(STDERR, "[ngramx] warning: docker-compose {$subcommand} failed: {$detail}\n");
        }
    }

    /**
     * List running services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     * @return array<string, array<string, string>>
     */
    public function ps(string $composeFile, ?string $projectName = null): array
    {
        $command = array_merge(['docker-compose'], ComposeFiles::fileArgs($composeFile));

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'ps';
        $command[] = '--format';
        $command[] = 'json';

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return [];
        }

        $services = [];
        foreach (explode("\n", $output) as $line) {
            $data = json_decode($line, true);
            if ($data !== null && isset($data['Service'])) {
                $services[$data['Service']] = $data;
            }
        }

        return $services;
    }

    /**
     * Check if any services are genuinely running.
     *
     * Only containers whose State is "running" count. A stack whose containers
     * are all crash-looping ("restarting") or stopped ("exited", "dead",
     * "created", "paused") merely *looks* alive in `compose ps`; treating it as
     * running makes callers skip startup and then fail downstream on a broken
     * environment. Falling through to the normal startup path instead gives
     * compose a chance to recreate the containers.
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     */
    public function isRunning(string $composeFile, ?string $projectName = null): bool
    {
        foreach ($this->ps($composeFile, $projectName) as $service) {
            if (($service['State'] ?? null) === 'running') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether one specific compose service has a container in the
     * "running" state.
     *
     * This is the right probe for "is the environment usable?" decisions:
     * a half-dead stack (databases up, app exited) counts as running under
     * {@see isRunning}, which makes callers skip startup and then fail
     * downstream against the dead primary service. `compose ps` omits
     * stopped containers by default, so an exited or never-created service
     * simply isn't listed — both correctly report false here.
     */
    public function isServiceRunning(string $composeFile, string $service, ?string $projectName = null): bool
    {
        $services = $this->ps($composeFile, $projectName);

        return ($services[$service]['State'] ?? null) === 'running';
    }
}

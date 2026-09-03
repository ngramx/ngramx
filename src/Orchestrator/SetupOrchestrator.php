<?php

declare(strict_types=1);

namespace Ngramx\Orchestrator;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Validator\SecretsValidator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Docker\HealthChecker;
use Ngramx\Docker\ImageBuildFreshnessChecker;
use Ngramx\Docker\NetworkAttachmentChecker;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Docker\StaleBindMountSweeper;
use Ngramx\Executor\ContainerCommandExecutor;
use Ngramx\Executor\HostCommandExecutor;
use Ngramx\Executor\Retry\RetryPolicy;
use Ngramx\Host\EtcHostsHint;
use Ngramx\Http\AppUrlProbe;
use Ngramx\Http\LoopbackUrl;
use Ngramx\Http\ProbeResult;
use Ngramx\Http\UrlPortOffset;
use Ngramx\Output\LiveLogPanel;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Process\Process;

class SetupOrchestrator
{
    /**
     * Budget for the post-start HTTP probe. 30 attempts at 2s each ≈ 60s
     * total — generous enough for a Laravel/Symfony entrypoint that runs
     * composer install or dumps caches on startup (verafind cold boot
     * takes ~42s on a warm Docker), short enough that a genuinely broken
     * upstream doesn't keep the user waiting indefinitely.
     */
    private const DEFAULT_APP_URL_PROBE_ATTEMPTS = 30;
    private const DEFAULT_APP_URL_PROBE_RETRY_SECONDS = 2;

    private readonly ServiceReadinessWaiter $readinessWaiter;
    private readonly AppUrlProbe $appUrlProbe;
    private readonly NetworkAttachmentChecker $networkAttachmentChecker;
    private readonly RetryPolicy $retryPolicy;
    private readonly StaleBindMountSweeper $staleBindMountSweeper;

    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly HostCommandExecutor $hostExecutor,
        private readonly HealthChecker $healthChecker,
        private readonly OutputFormatter $formatter,
        private readonly SecretsValidator $secretsValidator = new SecretsValidator(),
        private readonly ImageBuildFreshnessChecker $buildFreshnessChecker = new ImageBuildFreshnessChecker(),
        ?ServiceReadinessWaiter $readinessWaiter = null,
        ?AppUrlProbe $appUrlProbe = null,
        ?NetworkAttachmentChecker $networkAttachmentChecker = null,
        ?RetryPolicy $retryPolicy = null,
        ?StaleBindMountSweeper $staleBindMountSweeper = null,
        private readonly int $appUrlProbeAttempts = self::DEFAULT_APP_URL_PROBE_ATTEMPTS,
        private readonly int $appUrlProbeRetrySeconds = self::DEFAULT_APP_URL_PROBE_RETRY_SECONDS,
    ) {
        $this->readinessWaiter = $readinessWaiter ?? new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
            new \Ngramx\Docker\ContainerExecutor(),
        );
        $this->appUrlProbe = $appUrlProbe ?? new AppUrlProbe();
        $this->networkAttachmentChecker = $networkAttachmentChecker
            ?? new NetworkAttachmentChecker($this->dockerCompose);
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        $this->staleBindMountSweeper = $staleBindMountSweeper ?? new StaleBindMountSweeper();
    }

    /**
     * Orchestrate the full setup flow
     *
     * @param NgramxConfig $config Configuration
     * @param bool $skipWait Skip health checks
     * @param bool $skipInit Skip initialize commands
     * @param string|null $namespace Container namespace
     * @param int|null $portOffset Port offset to apply
     * @param bool $verifyAppUrl When true, probe `docker.app_url` after setup and
     *        throw on 5xx. Connection/DNS failures after a healthy stack are
     *        warnings so `.ngramx.lock` still lets `ngramx shell` attach.
     *        Disable with `ngramx up --no-verify`
     *        for CI / non-HTTP stacks.
     * @param array<int, int> $portMap Per-port conflict remap (conflicted base host
     *        port => replacement) so the post-start probe follows a remapped web port.
     * @param (callable(): void)|null $onReady Invoked after containers are healthy
     *        (and initialize commands have run) and before the HTTP probe. Used
     *        by `ngramx up` to write `.ngramx.lock` so a later probe warning or
     *        5xx cannot leave `ngramx shell` looking at the default compose project.
     * @return array{time: float, namespace: string, port_offset: int, app_url_probe: ?ProbeResult} Setup results
     * @throws \RuntimeException
     * @throws ServiceNotHealthyException
     */
    public function setup(
        NgramxConfig $config,
        bool $skipWait = false,
        bool $skipInit = false,
        ?string $namespace = null,
        ?int $portOffset = null,
        bool $rebuild = false,
        ?int $timeout = null,
        bool $verifyAppUrl = true,
        array $portMap = [],
        string $configDirectory = '',
        ?callable $onReady = null,
    ): array {
        $startTime = microtime(true);

        // Validate required secrets are available
        if (!$config->secrets->isEmpty()) {
            $this->validateSecrets($config, $configDirectory);
        }

        // Detect first run (no existing images)
        $firstRun = !$this->dockerCompose->hasExistingImages($config->docker->composeFile, $namespace);
        if ($firstRun) {
            $this->formatter->section('First run detected');
            $this->formatter->info('Building containers may take a few minutes');
        }

        // Phase 1: Pre-start commands
        if (!empty($config->setup->preStart)) {
            $this->runPreStartCommands($config->setup->preStart);
        }

        // Phase 2: Start Docker services
        if (!$rebuild) {
            $this->warnAboutStaleBuildInputs($config->docker->composeFile, $namespace);
        }

        $this->startDockerServices($config->docker->composeFile, $namespace, $rebuild, $timeout, $firstRun);

        // Phase 2.5: Detect and auto-recover network-detached containers.
        // Has to run *before* readiness waits, otherwise we'd sit watching
        // a service that can never reach its peers and only timeout after
        // the wait_for budget expires.
        $this->reconcileNetworkAttachments($config->docker->composeFile, $namespace);

        // Phase 3: Wait for services with live status display. Even when the
        // user has no explicit wait_for entries, we still scan every compose
        // service for crash-loops so a broken container never masquerades as a
        // successful startup.
        if (!$skipWait) {
            $this->waitForServices($config->docker->composeFile, $config->docker->waitFor, $namespace, $firstRun);
        }

        // Phase 4: Initialize commands
        if (!$skipInit && !empty($config->setup->initialize)) {
            $this->runInitializeCommands(
                $config->setup->initialize,
                $config->docker->composeFile,
                $config->docker->primaryService,
                $namespace
            );
        }

        // Phase 5: persist instance identity (lock file) before the HTTP probe
        // so a DNS/connect miss on WSL cannot strand `ngramx shell`.
        if ($onReady !== null) {
            $onReady();
        }

        // Phase 6: HTTP probe of app_url. Catches the "containers are running
        // but the upstream is broken" failure mode that Docker-level checks
        // cannot detect (e.g. nginx returns 502 because php-fpm is stuck in
        // its own entrypoint waiting for a desynced db container).
        $probe = null;
        if ($verifyAppUrl && $config->docker->appUrl !== '') {
            $probe = $this->verifyAppUrl($config, $namespace, $portOffset ?? 0, $portMap);
        }

        return [
            'time' => microtime(true) - $startTime,
            'namespace' => $namespace ?? '',
            'port_offset' => $portOffset ?? 0,
            'app_url_probe' => $probe,
        ];
    }

    /**
     * Detect any running containers that have been left without a network
     * attachment (a known Docker Desktop hazard, especially after the daemon
     * restarts mid-lifecycle) and attempt a single targeted recreate of each
     * offender. If the recreate doesn't clear the desync, throw — the user
     * is going to have a bad time and we'd rather surface it loudly here
     * than let it cascade into a 502.
     */
    private function reconcileNetworkAttachments(string $composeFile, ?string $namespace): void
    {
        $issues = $this->networkAttachmentChecker->checkAll($composeFile, $namespace);
        if ($issues === []) {
            return;
        }

        $this->formatter->section('Reconciling container networks');

        foreach ($issues as $issue) {
            $this->formatter->warning('⚠ ' . $issue->describe());
            $this->formatter->info("Recreating `{$issue->service}` to restore its network attachment...");

            try {
                $this->dockerCompose->recreateService($composeFile, $issue->service, $namespace);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(
                    $issue->describe()
                        . "\n\nAutomatic recovery failed: " . $e->getMessage()
                );
            }

            $stillBroken = $this->networkAttachmentChecker->checkService($composeFile, $issue->service, $namespace);
            if ($stillBroken !== null) {
                throw new \RuntimeException(
                    'After recreating `' . $issue->service . '` it is still running with no networks attached.'
                        . ' This usually means the compose-declared network has been deleted underneath Docker.'
                        . ' Try `ngramx down` followed by `docker network prune` and re-run `ngramx up`.'
                );
            }

            $this->formatter->info("✓ `{$issue->service}` reattached.");
        }
    }

    /**
     * Probe `docker.app_url` after services report healthy. Retries a few
     * times because php-fpm / Laravel boot can race the first request even
     * after Docker says the container is up.
     *
     * Throws {@see \RuntimeException} on 5xx. Connection/DNS failures after
     * containers are healthy are returned as a warning (the lock is already
     * written). `localhost` / `*.localhost` are probed via 127.0.0.1 with
     * the original Host header so WSL's resolver cannot mask a running stack.
     */
    /**
     * @param array<int, int> $portMap
     */
    private function verifyAppUrl(NgramxConfig $config, ?string $namespace, int $portOffset, array $portMap = []): ProbeResult
    {
        $this->formatter->section('Verifying app URL');
        // When --avoid-conflicts / --port-offset shifted the stack, the
        // app's host port is no longer the scheme default — probe the
        // actually-bound port, not the original ngramx.yml URL. The same goes
        // for targeted conflict resolution moving the web port individually.
        $url = UrlPortOffset::applyMap(
            UrlPortOffset::apply($config->docker->appUrl, $portOffset),
            $portMap,
        );

        // A project may declare `docker.verify_timeout` (seconds) to widen the
        // probe budget — useful for stacks whose cold boot reliably outlasts the
        // ~60s default and 502s until php-fpm/Laravel finishes its entrypoint.
        // We keep the fixed retry cadence and derive the attempt count from it.
        $retrySeconds = $this->appUrlProbeRetrySeconds;
        $attempts = $this->appUrlProbeAttempts;
        $verifyTimeout = $config->docker->verifyTimeout;
        if ($verifyTimeout !== null && $verifyTimeout > 0 && $retrySeconds > 0) {
            $attempts = max(1, (int) ceil($verifyTimeout / $retrySeconds));
        }

        $probeUrl = $url;
        $hostHeader = null;
        $loopback = LoopbackUrl::probeTarget($url);
        if ($loopback !== null) {
            $probeUrl = $loopback['url'];
            $hostHeader = $loopback['host'];
            $this->formatter->info("Probing {$url} via 127.0.0.1 (Host: {$hostHeader}) ...");
        } else {
            $this->formatter->info("Probing {$url} ...");
        }

        $result = $this->appUrlProbe->probeWithHost(
            $probeUrl,
            $hostHeader,
            attempts: $attempts,
            retrySeconds: $retrySeconds,
        );
        if ($loopback !== null) {
            $result = $result->withUrl($url);
        }

        if ($result->isHealthy()) {
            $this->formatter->info(sprintf(
                '✓ %s responded with HTTP %d',
                $url,
                (int) $result->statusCode,
            ));
            return $result;
        }

        // DNS / connect failures after a healthy stack are a warning: the
        // namespaced containers are up and the lock is already written.
        // 5xx still fails the command — that is a real php-fpm/nginx miss.
        if (!$result->reachable) {
            $this->formatter->warning($result->describeFailure());
            $hostsLine = EtcHostsHint::suggestedHostsLine($url);
            if ($hostsLine !== null) {
                $this->formatter->warning('This hostname does not resolve on your machine yet (normal for made-up dev domains).');
                $this->formatter->info('Add this line to /etc/hosts so your browser can open the URL:');
                $this->formatter->info('  '.$hostsLine);
            }

            return $result;
        }

        $hint = $this->collectUpstreamHint($config, $namespace);
        $message = $result->describeFailure();
        if ($hint !== null) {
            $message .= "\n\n" . $hint;
        }

        $this->formatter->error($message);

        throw new \RuntimeException($message);
    }

    /**
     * Pull a few recent log lines from likely-culprit services so the
     * "verification failed" message actually tells the user what to look at.
     * Best-effort — if anything throws we fall back to no hint rather than
     * obscuring the original probe error.
     */
    private function collectUpstreamHint(NgramxConfig $config, ?string $namespace): ?string
    {
        $services = $this->uniqueNonEmpty([
            $config->docker->primaryService,
            'nginx',
            'web',
            'caddy',
        ]);

        $sections = [];
        foreach ($services as $service) {
            try {
                $lines = $this->dockerCompose->getLatestLogLines(
                    $config->docker->composeFile,
                    $service,
                    3,
                    $namespace,
                );
            } catch (\Throwable) {
                continue;
            }

            if ($lines === []) {
                continue;
            }

            $sections[] = "  Last log lines from `{$service}`:\n    " . implode("\n    ", $lines);
        }

        if ($sections === []) {
            return null;
        }

        return "Upstream diagnostic:\n" . implode("\n\n", $sections);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueNonEmpty(array $values): array
    {
        $seen = [];
        foreach ($values as $v) {
            $trim = trim($v);
            if ($trim !== '' && !isset($seen[$trim])) {
                $seen[$trim] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Validate that all required secrets are available before setup proceeds
     */
    private function validateSecrets(NgramxConfig $config, string $configDirectory): void
    {
        $this->formatter->section('Validating secrets');

        $missingByProvider = $this->secretsValidator->validate(
            $config->secrets,
            $configDirectory !== '' ? $configDirectory : (getcwd() ?: '.')
        );

        if ($missingByProvider !== []) {
            foreach ($missingByProvider as $provider => $missing) {
                $this->formatter->error(sprintf(
                    'Missing required secrets from %s: %s',
                    SecretsValidator::describeProviderLabel($provider),
                    implode(', ', $missing)
                ));
            }

            throw new \RuntimeException(SecretsValidator::buildFailureMessage($missingByProvider));
        }

        $count = $config->secrets->totalRequiredCount();
        $this->formatter->info("All $count required secret(s) available");
    }

    private function warnAboutStaleBuildInputs(string $composeFile, ?string $namespace): void
    {
        $findings = $this->buildFreshnessChecker->findStaleBuildInputs($composeFile, $namespace);
        if ($findings === []) {
            return;
        }

        $this->formatter->section('Docker image out of date');
        foreach (explode("\n", $this->buildFreshnessChecker->formatAdvisory($findings)) as $line) {
            if ($line === '') {
                continue;
            }

            $this->formatter->warning($line);
        }
    }

    /**
     * Execute pre-start commands on host
     *
     * @param CommandDefinition[] $commands
     */
    private function runPreStartCommands(array $commands): void
    {
        $this->formatter->section('Pre-start commands');

        foreach ($commands as $cmd) {
            $this->executeHostCommand($cmd);
        }
    }

    /**
     * Start Docker Compose services with a live, rolling 3-line log panel
     * showing the latest docker-compose output (build progress, container
     * creation, etc.). The panel is cleared when the command completes so
     * it leaves no trace on the console.
     */
    private function startDockerServices(
        string $composeFile,
        ?string $namespace = null,
        bool $rebuild = false,
        ?int $timeout = null,
        bool $firstRun = false
    ): void {
        $this->formatter->section('Starting Docker services');

        if ($namespace !== null) {
            $this->formatter->info("Using namespace: {$namespace}");
        }

        if ($rebuild) {
            $this->formatter->info('Rebuilding Docker images...');
        }

        // On first run the images have to be built (or pulled), which can take
        // well over the default 5-minute non-rebuild timeout. Extend it to 30
        // minutes unless the caller passed an explicit --timeout.
        $effectiveTimeout = $timeout;
        if ($effectiveTimeout === null && $firstRun && !$rebuild) {
            $effectiveTimeout = 1800;
        }

        // Docker Desktop under WSL stages bind mounts behind a hashed path that
        // can outlive the file it stages (see StaleBindMountSweeper). Clearing
        // the corpses for this project first turns an unreadable OCI runtime
        // error into a no-op.
        $this->staleBindMountSweeper->sweepUnder(dirname($composeFile), $this->formatter);

        try {
            $this->runComposeUp($composeFile, $namespace, $rebuild, $effectiveTimeout);
        } catch (\RuntimeException $e) {
            // A staged mount can also go stale between our sweep and the
            // container create, and compose can name paths outside the project
            // that the scoped sweep deliberately leaves alone. Either way the
            // engine has just told us exactly which entries it could not
            // resolve, so clear those and give the start one more go.
            if (!$this->staleBindMountSweeper->recoverFromFailure($e->getMessage(), $this->formatter)) {
                throw $e;
            }

            $this->formatter->info('Retrying now that the stale mounts are gone...');
            $this->runComposeUp($composeFile, $namespace, $rebuild, $effectiveTimeout);
        }

        $this->formatter->info('Docker services started');
    }

    /**
     * One `docker compose up` behind the live 3-line log panel. Split out so
     * the stale-bind-mount retry can run the same command twice without
     * leaving a half-drawn panel behind.
     */
    private function runComposeUp(
        string $composeFile,
        ?string $namespace,
        bool $rebuild,
        ?int $timeout
    ): void {
        $panel = new LiveLogPanel($this->formatter->createSection(), 3);

        try {
            $this->dockerCompose->up(
                $composeFile,
                $namespace,
                $rebuild,
                $timeout,
                static function (string $type, string $buffer) use ($panel): void {
                    $panel->appendBuffer($buffer);
                }
            );
        } finally {
            $panel->clear();
        }
    }

    /**
     * Wait for services to become healthy with live-updating status display,
     * delegating to the shared {@see ServiceReadinessWaiter}.
     *
     * Every compose service that is NOT in the explicit wait list is passed
     * through as a monitored service: while we wait on the explicit ones,
     * crashes in any other container (for example an nginx whose upstream
     * `app` is dead) will abort immediately rather than being silently
     * ignored.
     *
     * @param \Ngramx\Config\Schema\ServiceWaitConfig[] $waitFor
     */
    private function waitForServices(string $composeFile, array $waitFor, ?string $namespace = null, bool $firstRun = false): void
    {
        if (empty($waitFor)) {
            // No explicit wait list: the section header would be misleading. We
            // still want to detect crash-looping services, so run the one-shot
            // verification silently.
            $allServices = $this->dockerCompose->listServices($composeFile, $namespace);
            $this->readinessWaiter->verifyNoServicesFailed($composeFile, $allServices, $namespace);
            return;
        }

        $this->formatter->section('Waiting for services');

        $monitorServices = $this->computeMonitorServices($composeFile, $waitFor, $namespace);

        $this->readinessWaiter->waitForAll(
            $composeFile,
            $waitFor,
            $namespace,
            $firstRun ? 10 : 1,
            $monitorServices,
        );
    }

    /**
     * Return the list of compose services that are not already covered by
     * $waitFor. These are the services we want to watch for crash-loops while
     * the explicit wait is in progress.
     *
     * @param \Ngramx\Config\Schema\ServiceWaitConfig[] $waitFor
     *
     * @return list<string>
     */
    private function computeMonitorServices(string $composeFile, array $waitFor, ?string $namespace): array
    {
        $allServices = $this->dockerCompose->listServices($composeFile, $namespace);
        if ($allServices === []) {
            return [];
        }

        $waitForNames = [];
        foreach ($waitFor as $waitConfig) {
            $waitForNames[$waitConfig->service] = true;
        }

        return array_values(array_filter(
            $allServices,
            static fn (string $service): bool => !isset($waitForNames[$service])
        ));
    }

    /**
     * Execute initialize commands in container
     *
     * @param CommandDefinition[] $commands
     */
    private function runInitializeCommands(
        array $commands,
        string $composeFile,
        string $primaryService,
        ?string $namespace = null
    ): void {
        $this->formatter->section('Initialize commands');

        $containerExecutor = new ContainerCommandExecutor(
            new \Ngramx\Docker\ContainerExecutor(),
            $composeFile,
            $primaryService,
            $namespace
        );

        foreach ($commands as $cmd) {
            $this->executeContainerCommand($cmd, $containerExecutor);
        }
    }

    /**
     * Execute a host command with real-time output
     */
    private function executeHostCommand(CommandDefinition $cmd): void
    {
        $this->formatter->command($cmd);

        // Create output callback for real-time streaming
        $outputCallback = function ($type, $buffer) {
            if ($type === Process::OUT || $type === Process::ERR) {
                $lines = explode("\n", rtrim($buffer));
                foreach ($lines as $line) {
                    if (!empty(trim($line))) {
                        $this->formatter->commandOutput($line);
                    }
                }
            }
        };

        $result = $this->hostExecutor->execute($cmd, $outputCallback);

        if (!$result->isSuccessful() && !$cmd->ignoreFailure) {
            $this->formatter->error("Command failed: {$cmd->command}");
            throw new \RuntimeException("Host command failed: {$cmd->command}");
        }
    }

    /**
     * Execute a container command with real-time output
     */
    private function executeContainerCommand(CommandDefinition $cmd, ContainerCommandExecutor $executor): void
    {
        $this->formatter->command($cmd);

        // Create output callback for real-time streaming
        $outputCallback = function ($type, $buffer) {
            if ($type === Process::OUT || $type === Process::ERR) {
                $lines = explode("\n", rtrim($buffer));
                foreach ($lines as $line) {
                    if (!empty(trim($line))) {
                        $this->formatter->commandOutput($line);
                    }
                }
            }
        };

        // Initialize commands run seconds after the containers appear, which is
        // exactly when the environment is least settled — a socket not yet
        // listening, an entrypoint still installing. Re-run a failure that looks
        // environmental rather than failing the whole `up`.
        $maxAttempts = $this->retryPolicy->attemptsFor($cmd);

        for ($attempt = 1;; $attempt++) {
            $result = $executor->execute($cmd, $outputCallback);

            if ($result->isSuccessful() || $attempt >= $maxAttempts) {
                break;
            }

            if (!$this->retryPolicy->shouldRetry($cmd, $result->exitCode, $result->output, $result->errorOutput)) {
                break;
            }

            $this->formatter->info(sprintf(
                'Attempt %d of %d failed with what looks like an environment problem — retrying.',
                $attempt,
                $maxAttempts,
            ));
            $this->retryPolicy->pauseBeforeRetry($attempt);
        }

        if (!$result->isSuccessful() && !$cmd->ignoreFailure) {
            $this->formatter->error("Command failed: {$cmd->command}");
            throw new \RuntimeException("Container command failed: {$cmd->command}");
        }
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Orchestrator;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Validator\SecretsValidator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Docker\HealthChecker;
use Ngramx\Docker\NetworkAttachmentChecker;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Executor\ContainerCommandExecutor;
use Ngramx\Executor\HostCommandExecutor;
use Ngramx\Http\AppUrlProbe;
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

    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly HostCommandExecutor $hostExecutor,
        private readonly HealthChecker $healthChecker,
        private readonly OutputFormatter $formatter,
        private readonly SecretsValidator $secretsValidator = new SecretsValidator(),
        ?ServiceReadinessWaiter $readinessWaiter = null,
        ?AppUrlProbe $appUrlProbe = null,
        ?NetworkAttachmentChecker $networkAttachmentChecker = null,
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
     *        throw on 5xx / connection refused. Disable with `ngramx up --no-verify`
     *        for CI / non-HTTP stacks.
     * @param array<int, int> $portMap Per-port conflict remap (conflicted base host
     *        port => replacement) so the post-start probe follows a remapped web port.
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
        string $configDirectory = ''
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

        // Phase 5: HTTP probe of app_url. Catches the "containers are running
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
     * Throws {@see \RuntimeException} on 5xx or connection failure, with a
     * diagnostic message that includes the latest line from the most likely
     * culprit's logs (the primary service) so the user sees the actual error
     * rather than just "502 Bad Gateway".
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

        $this->formatter->info("Probing {$url} ...");

        $result = $this->appUrlProbe->probe(
            $url,
            attempts: $attempts,
            retrySeconds: $retrySeconds,
        );

        if ($result->isHealthy()) {
            $this->formatter->info(sprintf(
                '✓ %s responded with HTTP %d',
                $url,
                (int) $result->statusCode,
            ));
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

        $panel = new LiveLogPanel($this->formatter->createSection(), 3);
        try {
            $this->dockerCompose->up(
                $composeFile,
                $namespace,
                $rebuild,
                $effectiveTimeout,
                static function (string $type, string $buffer) use ($panel): void {
                    $panel->appendBuffer($buffer);
                }
            );
        } finally {
            $panel->clear();
        }

        $this->formatter->info('Docker services started');
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

        $result = $executor->execute($cmd, $outputCallback);

        if (!$result->isSuccessful() && !$cmd->ignoreFailure) {
            $this->formatter->error("Command failed: {$cmd->command}");
            throw new \RuntimeException("Container command failed: {$cmd->command}");
        }
    }
}

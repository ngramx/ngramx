<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Caddy\CaddyService;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Docker\ComposeOverrideGenerator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Docker\NamespaceResolver;
use Ngramx\Docker\PortOffsetManager;
use Ngramx\Herd\HerdService;
use Ngramx\Host\EtcHostsHint;
use Ngramx\Http\UrlPortOffset;
use Ngramx\Orchestrator\SetupOrchestrator;
use Ngramx\Output\OutputFormatter;
use Ngramx\Tls\CertInspector;
use Ngramx\Worktree\WorktreeGitMount;
use Ngramx\Worktree\WorktreeOwnershipReconciler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Process;

class UpCommand extends Command
{
    private readonly CertInspector $certInspector;
    private readonly WorktreeOwnershipReconciler $ownershipReconciler;

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly SetupOrchestrator $setupOrchestrator,
        private readonly LockFile $lockFile,
        private readonly NamespaceResolver $namespaceResolver,
        private readonly PortOffsetManager $portOffsetManager,
        private readonly ComposeOverrideGenerator $overrideGenerator,
        private readonly DockerCompose $dockerCompose,
        private readonly HerdService $herdService,
        private readonly CaddyService $caddyService,
        ?CertInspector $certInspector = null,
        ?WorktreeOwnershipReconciler $ownershipReconciler = null,
    ) {
        parent::__construct();
        $this->certInspector = $certInspector ?? new CertInspector();
        $this->ownershipReconciler = $ownershipReconciler ?? new WorktreeOwnershipReconciler();
    }

    protected function configure(): void
    {
        $this
            ->setName('up')
            ->setDescription('Set up the development environment')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Custom container namespace prefix')
            ->addOption('port-offset', null, InputOption::VALUE_REQUIRED, 'Port offset to add to all exposed ports')
            ->addOption('avoid-conflicts', null, InputOption::VALUE_NONE, 'Automatically avoid container and port conflicts')
            ->addOption('no-host-mapping', null, InputOption::VALUE_NONE, 'Do not expose container ports to the host')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Skip health checks')
            ->addOption('skip-init', null, InputOption::VALUE_NONE, 'Skip initialize commands')
            ->addOption('stop-herd', null, InputOption::VALUE_NONE, 'Stop Laravel Herd (nginx) and any Caddy process on ports 80/443 before starting Docker')
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Force rebuild of Docker images before starting')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in seconds for Docker Compose operations')
            ->addOption('no-verify', null, InputOption::VALUE_NONE, 'Skip post-start verification (HTTP probe of docker.app_url and other sanity checks)')
            ->addOption('no-prompt-secure', null, InputOption::VALUE_NONE, 'Do not offer to run `ngramx secure` when a self-signed dev cert is detected');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $formatter->welcome();

            // Check Docker daemon is running
            if (!$this->dockerCompose->isDockerRunning()) {
                $formatter->error('You must start Docker before running ngramx up');
                return Command::FAILURE;
            }

            // Check if already running
            if ($this->lockFile->exists()) {
                $formatter->error('Environment already running in this directory.');
                $formatter->info('Use "ngramx down" to stop it first.');
                return Command::FAILURE;
            }

            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $formatter->info("Loaded configuration from: $configPath");

            // Show config warnings after loading
            $app = $this->getApplication();
            if ($app instanceof \Ngramx\Application) {
                $warnings = $app->getConfigWarnings();
                if ($warnings !== []) {
                    $formatter->getOutput()->writeln('');
                    foreach ($warnings as $warning) {
                        $formatter->warning("⚠ $warning");
                    }
                }
            }

            // Determine namespace early (needed for stale container detection)
            $namespace = $this->resolveNamespace($input, $formatter);

            // Check for stale containers (containers exist but no lock file)
            // This can happen if a previous run failed before writing the lock file
            if ($namespace !== null) {
                $this->cleanupStaleContainers($config, $formatter, $namespace);
            }

            // Determine port offset and host mapping
            $noHostMapping = $input->getOption('no-host-mapping');
            $portOffset = $noHostMapping ? 0 : $this->resolvePortOffset($input, $config->docker->composeFile, $formatter);

            if ($noHostMapping) {
                $formatter->info('Host port mapping disabled - containers will not expose ports');
            }

            // With no global offset in play, resolve individual port conflicts:
            // only the conflicted ports are remapped, everything else (including
            // container names) stays exactly as configured, so a redis conflict
            // never pushes the web service off port 80.
            $portMap = [];
            if (!$noHostMapping && $portOffset === 0) {
                $portMap = $this->resolvePortConflicts($config->docker->composeFile, $formatter);
            }

            // Generate override file if port offset is needed, using namespace, no host
            // mapping, or when running from a linked git worktree (which needs the parent
            // repo's git dir bind-mounted in so git resolves inside containers).
            $worktreeRoot = dirname($configPath);
            $inWorktree = (new WorktreeGitMount())->resolve($worktreeRoot) !== null;
            $needsOverride = $portOffset > 0 || $namespace !== null || $noHostMapping || $inWorktree || $portMap !== [];
            if ($needsOverride) {
                $this->overrideGenerator->generate($config->docker->composeFile, $portOffset, $namespace, $noHostMapping, $portMap);
            }

            // Free host ports 80/443 when requested (Herd uses nginx; Caddy is a separate common listener)
            $herdStopped = false;
            $caddyStopped = false;
            if ($input->getOption('stop-herd')) {
                if ($this->herdService->isInstalled()) {
                    $formatter->info('Stopping Herd services...');
                    $this->herdService->stop();
                    $herdStopped = true;
                    $formatter->info('Herd services stopped');
                } else {
                    $formatter->warning('Herd is not installed — skipping Herd shutdown');
                }

                $caddyCount = $this->caddyService->stopListenersOnPorts([80, 443]);
                if ($caddyCount > 0) {
                    $caddyStopped = true;
                    $formatter->info($caddyCount === 1
                        ? 'Stopped 1 Caddy process listening on ports 80 or 443'
                        : "Stopped {$caddyCount} Caddy processes listening on ports 80 or 443");
                }
            }

            // Run setup through orchestrator
            $timeoutOption = $input->getOption('timeout');
            $timeout = $timeoutOption !== null ? (int) $timeoutOption : null;

            $result = $this->setupOrchestrator->setup(
                $config,
                $input->getOption('no-wait'),
                $input->getOption('skip-init'),
                $namespace,
                $portOffset,
                $input->getOption('rebuild'),
                $timeout,
                !$input->getOption('no-verify'),
                $portMap,
                dirname($configPath),
            );

            // In a linked worktree the container's root entrypoint (composer
            // install, migrations, ...) has just written runtime files as root,
            // leaving storage/ and bootstrap/cache un-writable for the non-root
            // runtime user — which surfaces as Laravel "Permission denied" on
            // storage or the tempnam() fallback notice from Filesystem::replace().
            // Hand those files back to the developer's uid now that the entrypoint
            // has finished. `review` does this too, but plain `up` (and every
            // restart) must as well, or ownership drifts back to root.
            if ($inWorktree) {
                $this->reconcileWorktreeOwnership($worktreeRoot, $formatter);
            }

            // Write lock file if we generated an override file or stopped Herd/Caddy
            if ($needsOverride || $herdStopped || $caddyStopped) {
                $lockData = new LockFileData(
                    namespace: $namespace,
                    portOffset: $portOffset > 0 ? $portOffset : null,
                    startedAt: date('c'),
                    noHostMapping: $noHostMapping,
                    herdStopped: $herdStopped,
                    caddyStopped: $caddyStopped,
                    portMap: $portMap,
                );
                $this->lockFile->write($lockData);
                $output->writeln('');
                $formatter->info('Instance details saved to .ngramx.lock');
            }

            // Display completion summary with port information
            $this->displayCompletionSummary($formatter, $result['time'], $config, $portOffset, $portMap);

            // Inspect the TLS cert (if any) and either reassure the user it's
            // browser-trusted or offer to upgrade self-signed -> mkcert via
            // `ngramx secure`. Non-interactive shells just get the warning.
            $this->reviewTlsCertificate($formatter, $input, $output, $configPath, $config);

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (ServiceNotHealthyException $e) {
            $formatter->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Hand the worktree's bind-mounted files back to the developer's uid/gid so
     * the container's non-root runtime user can write storage/ and
     * bootstrap/cache. See {@see WorktreeOwnershipReconciler} for why this is
     * keyed off the developer's checkout rather than the Ngramx process uid.
     */
    private function reconcileWorktreeOwnership(string $worktreeRoot, OutputFormatter $formatter): void
    {
        $result = $this->ownershipReconciler->reconcile($worktreeRoot);

        if ($result->isReconciled()) {
            $formatter->info('Reconciled worktree file ownership to the developer user');

            return;
        }

        if ($result->isFailed()) {
            // Non-fatal: the environment is still usable. Point at the manual fix
            // so a later "Permission denied" writing storage is easy to resolve.
            $formatter->warning(
                'Could not normalise worktree file ownership. If you hit a "Permission denied" '
                . "writing storage/logs, run:\n  sudo chown -R {$result->uid}:{$result->gid} {$worktreeRoot}"
            );
        }
    }

    /**
     * Resolve the namespace from input options
     * Returns null for default mode (no namespace isolation)
     */
    private function resolveNamespace(InputInterface $input, OutputFormatter $formatter): ?string
    {
        $avoidConflicts = $input->getOption('avoid-conflicts');
        $namespaceOption = $input->getOption('namespace');

        if ($namespaceOption !== null) {
            // Use explicit namespace
            $this->namespaceResolver->validate($namespaceOption);
            return $namespaceOption;
        }

        if ($avoidConflicts) {
            // Auto-generate namespace from directory
            $namespace = $this->namespaceResolver->deriveFromDirectory();
            $formatter->info("Auto-generated namespace: {$namespace}");
            return $namespace;
        }

        // Default mode: no namespace isolation
        return null;
    }

    /**
     * Resolve the port offset from input options
     */
    private function resolvePortOffset(InputInterface $input, string $composeFile, OutputFormatter $formatter): int
    {
        $avoidConflicts = $input->getOption('avoid-conflicts');
        $portOffsetOption = $input->getOption('port-offset');

        if ($portOffsetOption !== null) {
            // Use explicit port offset
            $offset = (int) $portOffsetOption;
            if ($offset < 0) {
                throw new \InvalidArgumentException('Port offset must be a positive integer');
            }
            return $offset;
        }

        if ($avoidConflicts) {
            // Auto-allocate port offset
            $basePorts = $this->portOffsetManager->extractBasePorts($composeFile);
            if (empty($basePorts)) {
                return 0; // No ports to offset
            }

            $formatter->info('Scanning for available ports...');
            $offset = $this->portOffsetManager->findAvailableOffset($basePorts);

            if ($offset > 0) {
                $formatter->info("Port offset allocated: +{$offset}");
            }

            return $offset;
        }

        // No port offset by default
        return 0;
    }

    /**
     * Detect host port conflicts on the ports the compose file wants to bind
     * and resolve them individually — only the conflicted ports move, and the
     * remap is announced so the developer knows exactly what changed.
     *
     * @return array<int, int> conflicted base port => replacement port
     */
    private function resolvePortConflicts(string $composeFile, OutputFormatter $formatter): array
    {
        $basePorts = $this->portOffsetManager->extractBasePorts($composeFile);
        if ($basePorts === []) {
            return [];
        }

        $portMap = $this->portOffsetManager->resolvePortConflicts($basePorts);
        if ($portMap === []) {
            return [];
        }

        $formatter->info(count($portMap) === 1
            ? 'Port conflict detected — resolved automatically:'
            : count($portMap) . ' port conflicts detected — resolved automatically:');
        foreach ($portMap as $from => $to) {
            $formatter->info("  {$from} is in use — using {$to} instead");
        }

        return $portMap;
    }

    /**
     * Display completion summary with port information
     *
     * @param array<int, int> $portMap
     */
    private function displayCompletionSummary(
        OutputFormatter $formatter,
        float $totalTime,
        \Ngramx\Config\Schema\NgramxConfig $config,
        int $portOffset,
        array $portMap = []
    ): void {
        $output = $formatter->getOutput();
        $output->writeln('');
        $output->writeln(sprintf('<fg=#7D55C7>✨ Environment ready in %.1fs!</>', $totalTime));
        $output->writeln('');

        // Display URL with port offset if applicable
        if (isset($config->docker->appUrl) && !empty($config->docker->appUrl)) {
            // UrlPortOffset handles both explicit (`:443`) and implicit
            // (https://host) ports; the old inline regex only matched the
            // explicit form, so URLs that relied on the scheme default were
            // silently shown un-shifted.
            $url = UrlPortOffset::applyMap(
                UrlPortOffset::apply($config->docker->appUrl, $portOffset),
                $portMap,
            );

            $output->writeln(sprintf('<fg=green>→</> Access at: <fg=cyan>%s</>', $url));
            $output->writeln('');

            $hostsLine = EtcHostsHint::suggestedHostsLine($url);
            if ($hostsLine !== null) {
                $formatter->warning('This hostname does not resolve on your machine yet (normal for made-up dev domains).');
                $formatter->info('Add this line to /etc/hosts so your browser can open the URL:');
                $formatter->info('  '.$hostsLine);
                $output->writeln('');
            }
        }
    }

    /**
     * After a successful start, look at the cert in `docker.ssl_path` and
     * either:
     *   - say nothing if there's no cert (the project probably isn't using
     *     TLS, or its proxy generates one at runtime — not our concern);
     *   - say nothing if the cert is mkcert-signed (browser will trust it);
     *   - print a one-line warning if the cert is self-signed AND we can't
     *     do better (mkcert not installed, or `--no-prompt-secure` was set,
     *     or we're not on a TTY);
     *   - print the warning AND offer to run `ngramx secure` interactively
     *     when mkcert is installed and we have a TTY.
     */
    private function reviewTlsCertificate(
        OutputFormatter $formatter,
        InputInterface $input,
        OutputInterface $output,
        string $configPath,
        \Ngramx\Config\Schema\NgramxConfig $config,
    ): void {
        $info = $this->certInspector->inspectForAppUrl(
            $config->docker->appUrl,
            dirname($configPath),
            $config->docker->sslPath,
        );

        if ($info === null || $info->isBrowserTrusted()) {
            return;
        }

        if (!$info->isSelfSigned) {
            return;
        }

        $output->writeln('');
        $formatter->warning(sprintf(
            '⚠ The SSL certificate at %s is self-signed (issuer: %s).',
            $config->docker->appUrl,
            $info->describeIssuer(),
        ));
        $formatter->info('Your browser will warn or refuse to load this URL.');

        $mkcertInstalled = $this->isMkcertInstalled();
        if (!$mkcertInstalled) {
            $formatter->info('Install mkcert and re-run `ngramx secure` to get a browser-trusted cert:');
            $formatter->info('  https://github.com/FiloSottile/mkcert');
            return;
        }

        if ($input->getOption('no-prompt-secure') || !$input->isInteractive()) {
            $formatter->info('Run `ngramx secure` to upgrade to a browser-trusted cert.');
            return;
        }

        $helper = $this->getHelper('question');
        \assert($helper instanceof QuestionHelper);
        $question = new ConfirmationQuestion(
            '<fg=yellow>Generate a browser-trusted cert now using mkcert? (y/N) </>',
            false,
        );

        if (!$helper->ask($input, $output, $question)) {
            $formatter->info('Skipped. You can run `ngramx secure` manually any time.');
            return;
        }

        $secure = $this->getApplication()?->find('secure');
        if ($secure === null) {
            $formatter->error('Internal error: `ngramx secure` command is not registered.');
            return;
        }

        $output->writeln('');
        $exit = $secure->run(new ArrayInput([]), $output);
        if ($exit !== Command::SUCCESS) {
            $formatter->warning('`ngramx secure` did not complete successfully. Run it manually for details.');
            return;
        }

        $formatter->info('You may need to restart your browser for the new CA to take effect.');
    }

    /**
     * Lightweight `which mkcert` check, matched with SecureCommand's behaviour.
     */
    private function isMkcertInstalled(): bool
    {
        $process = new Process(['which', 'mkcert']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    /**
     * Clean up stale containers from previous failed runs
     */
    private function cleanupStaleContainers(
        \Ngramx\Config\Schema\NgramxConfig $config,
        OutputFormatter $formatter,
        string $namespace
    ): void {
        // Check if containers exist with this namespace
        $command = ['docker', 'ps', '-a', '--filter', "name={$namespace}", '--format', '{{.Names}}'];
        $process = new Process($command);
        $process->run();

        if ($process->isSuccessful() && !empty(trim($process->getOutput()))) {
            $formatter->warning('Found containers from previous failed run. Cleaning up...');

            // Use docker-compose down to clean up properly
            try {
                $overrideFile = dirname($config->docker->composeFile) . '/docker-compose.override.yml';
                if (file_exists($overrideFile)) {
                    // Use the existing override file if present
                    $this->dockerCompose->down($config->docker->composeFile, false, $namespace);
                    $this->overrideGenerator->cleanup($config->docker->composeFile);
                } else {
                    // Just remove containers without override file
                    $this->dockerCompose->down($config->docker->composeFile, false, $namespace);
                }

                // Wait a moment for ports to be fully released
                usleep(500000); // 500ms

                $formatter->info('Cleanup complete');
            } catch (\Exception $e) {
                // If cleanup fails, just warn but continue
                $formatter->warning('Could not fully clean up containers: ' . $e->getMessage());
            }
        }
    }
}

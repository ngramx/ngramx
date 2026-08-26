<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Codabyte\CloudRunsClient;
use Ngramx\Codabyte\CloudRunsResult;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\HealthChecker;
use Ngramx\Git\GitRepositoryService;
use Ngramx\Output\EnvironmentOverviewRenderer;
use Ngramx\Output\OutputFormatter;
use Ngramx\Output\StatusJsonPresenter;
use Ngramx\Worktree\WorktreeInventory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ngramx status` — the repository's picture of itself: where the project
 * lives, whether the main checkout's environment is up, and every worktree
 * with its branch, running state and URL.
 *
 * Runnable from anywhere inside the project — the repo root, a subfolder, or
 * inside one of the worktrees — and always reports on the whole repository, so
 * "what have I got running?" has one answer wherever it is asked. The
 * per-service health table this command used to print lives behind
 * `--services`.
 *
 * `--json` emits the same picture as machine-readable JSON, for tools that
 * drive Ngramx rather than read its output (see {@see StatusJsonPresenter}).
 */
class StatusCommand extends Command
{
    private readonly WorktreeInventory $inventory;
    private readonly StatusJsonPresenter $jsonPresenter;
    private readonly CloudRunsClient $cloudRunsClient;
    private readonly GitRepositoryService $gitRepositoryService;

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly LockFile $lockFile,
        ?WorktreeInventory $inventory = null,
        ?StatusJsonPresenter $jsonPresenter = null,
        ?CloudRunsClient $cloudRunsClient = null,
        ?GitRepositoryService $gitRepositoryService = null,
    ) {
        parent::__construct();
        $this->inventory = $inventory ?? new WorktreeInventory($dockerCompose);
        $this->jsonPresenter = $jsonPresenter ?? new StatusJsonPresenter();
        $this->cloudRunsClient = $cloudRunsClient ?? CloudRunsClient::fromEnvironment();
        $this->gitRepositoryService = $gitRepositoryService ?? new GitRepositoryService();
    }

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Show the project overview: the main checkout and every worktree, with branch, running state and URL')
            ->addOption('services', null, InputOption::VALUE_NONE, 'Show the per-service health table for the current environment instead of the project overview')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON instead of the formatted overview')
            ->addOption('no-cloud', null, InputOption::VALUE_NONE, 'Skip the Codabyte lookup, reporting only on this machine');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $json = (bool) $input->getOption('json');

        if (!(bool) $input->getOption('services')) {
            return $this->showOverview($output, $formatter, $json, !(bool) $input->getOption('no-cloud'));
        }

        return $this->showServiceHealth($output, $formatter, $json);
    }

    /**
     * Render the repository-wide overview.
     */
    private function showOverview(
        OutputInterface $output,
        OutputFormatter $formatter,
        bool $json,
        bool $includeCloud
    ): int {
        try {
            $configPath = $this->configLoader->findConfigFile();
            $projectPath = dirname($configPath);

            // Run from inside a worktree, report on the repository that owns it
            // rather than on that single environment.
            $repositoryPath = WorktreeInventory::repositoryRootFor($projectPath);
            $config = $this->configLoader->load(
                $repositoryPath === $projectPath ? $configPath : $repositoryPath . '/ngramx.yml'
            );

            $snapshot = $this->inventory->collect($repositoryPath, $config, $projectPath);
            $cloud = $includeCloud
                ? $this->cloudRuns($repositoryPath)
                : CloudRunsResult::notConfigured();

            if ($json) {
                $output->writeln($this->jsonPresenter->encode(
                    $this->jsonPresenter->overview($repositoryPath, $snapshot['root'], $snapshot['worktrees'], $cloud)
                ));

                return Command::SUCCESS;
            }

            (new EnvironmentOverviewRenderer($output, $formatter))
                ->render($snapshot['root'], $snapshot['worktrees'], $cloud);

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            return $this->fail($output, $formatter, $json, "Configuration error: {$e->getMessage()}");
        } catch (\Exception $e) {
            return $this->fail($output, $formatter, $json, "Error: {$e->getMessage()}");
        }
    }

    /**
     * Ask Codabyte what it is running for this repository.
     *
     * Skipped entirely when there is no origin remote to identify the
     * repository by — a local-only checkout cannot be matched against a server
     * that clones from a URL.
     */
    private function cloudRuns(string $repositoryPath): CloudRunsResult
    {
        if (!$this->cloudRunsClient->isConfigured()) {
            return CloudRunsResult::notConfigured();
        }

        $remoteUrl = $this->gitRepositoryService->getRemoteUrl($repositoryPath);
        if ($remoteUrl === null) {
            return CloudRunsResult::notConfigured();
        }

        return $this->cloudRunsClient->fetch($remoteUrl);
    }

    /**
     * Render the per-service health table for the environment in the current
     * directory (`--services`).
     */
    private function showServiceHealth(OutputInterface $output, OutputFormatter $formatter, bool $json): int
    {
        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            // Read lock file to get namespace and port offset
            $namespace = null;
            $portOffset = 0;
            $startedAt = null;

            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData->namespace ?? null;
                $portOffset = $lockData->portOffset ?? 0;
                $startedAt = $lockData->startedAt ?? null;
            }

            // If no lock file, use null (default mode - no namespace isolation)

            $running = $this->dockerCompose->isRunning($config->docker->composeFile, $namespace);

            // Nothing running is a legitimate answer rather than an error, so
            // JSON callers get the same empty-but-valid envelope either way.
            if (!$running) {
                if ($json) {
                    $output->writeln($this->jsonPresenter->encode(
                        $this->jsonPresenter->services($namespace, $portOffset, $startedAt, false, [])
                    ));

                    return Command::SUCCESS;
                }

                $formatter->section('Environment Status');
                $this->writeInstanceInfo($output, $namespace, $portOffset, $startedAt);
                $formatter->warning('No services are currently running');
                $formatter->info('Run "ngramx up" to start the environment');

                return Command::SUCCESS;
            }

            // Get service info
            $services = $this->dockerCompose->ps($config->docker->composeFile, $namespace);

            if ($json) {
                $rows = [];
                foreach ($services as $serviceName => $serviceData) {
                    $rows[] = [
                        'name' => (string) $serviceName,
                        'state' => (string) ($serviceData['State'] ?? 'unknown'),
                        'health' => $this->healthChecker->getHealthStatus(
                            $config->docker->composeFile,
                            (string) $serviceName,
                            $namespace
                        ),
                    ];
                }

                $output->writeln($this->jsonPresenter->encode(
                    $this->jsonPresenter->services($namespace, $portOffset, $startedAt, true, $rows)
                ));

                return Command::SUCCESS;
            }

            $formatter->section('Environment Status');
            $this->writeInstanceInfo($output, $namespace, $portOffset, $startedAt);

            if (empty($services)) {
                $formatter->warning('No services found');
                return Command::SUCCESS;
            }

            // Build table data
            $table = new Table($output);
            $table->setHeaders(['Service', 'Status', 'Health']);

            foreach ($services as $serviceName => $serviceData) {
                $status = $serviceData['State'] ?? 'unknown';
                $health = $this->healthChecker->getHealthStatus($config->docker->composeFile, $serviceName, $namespace);

                // Color code the status
                $statusFormatted = match($status) {
                    'running' => "<fg=green>$status</>",
                    'exited' => "<fg=red>$status</>",
                    default => "<fg=yellow>$status</>",
                };

                // Color code the health
                $healthFormatted = match($health) {
                    'healthy' => "<fg=green>$health</>",
                    'unhealthy' => "<fg=red>$health</>",
                    'starting' => "<fg=yellow>$health</>",
                    'running' => "<fg=green>$health</>",
                    default => "<fg=gray>$health</>",
                };

                $table->addRow([
                    $serviceName,
                    $statusFormatted,
                    $healthFormatted,
                ]);
            }

            $output->writeln('');
            $table->render();
            $output->writeln('');

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            return $this->fail($output, $formatter, $json, "Configuration error: {$e->getMessage()}");
        } catch (\Exception $e) {
            return $this->fail($output, $formatter, $json, "Error: {$e->getMessage()}");
        }
    }

    /**
     * Display the instance header shared by both service-health paths.
     */
    private function writeInstanceInfo(
        OutputInterface $output,
        ?string $namespace,
        ?int $portOffset,
        ?string $startedAt
    ): void {
        if (!$this->lockFile->exists()) {
            return;
        }

        $output->writeln(sprintf('<fg=cyan>Namespace:</> %s', $namespace));
        if ($portOffset !== null && $portOffset > 0) {
            $output->writeln(sprintf('<fg=cyan>Port offset:</> +%d', $portOffset));
        }
        $output->writeln(sprintf('<fg=cyan>Started:</> %s', $startedAt ?? 'unknown'));
        $output->writeln('');
    }

    /**
     * Report a failure in whichever format the caller asked for.
     *
     * A tool parsing stdout must not be handed a prose error message where it
     * expects JSON, so the error itself is encoded in the same envelope.
     */
    private function fail(OutputInterface $output, OutputFormatter $formatter, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln($this->jsonPresenter->encode([
                'schema' => StatusJsonPresenter::SCHEMA_VERSION,
                'error' => $message,
            ]));

            return Command::FAILURE;
        }

        $formatter->error($message);

        return Command::FAILURE;
    }
}

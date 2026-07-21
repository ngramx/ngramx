<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Docker\ComposeOverrideGenerator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Docker\HealthChecker;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Output\LiveLogPanel;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly CommandOrchestrator $commandOrchestrator,
        private readonly LockFile $lockFile,
        private readonly ComposeOverrideGenerator $overrideGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('rebuild')
            ->setDescription('Rebuild Docker images, recreate containers, and run fresh');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $formatter->welcome('Rebuilding Development Environment');

            // Check Docker daemon is running
            if (!$this->dockerCompose->isDockerRunning()) {
                $formatter->error('You must start Docker before running ngramx rebuild');
                return Command::FAILURE;
            }

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
                        $formatter->warning("  ⚠ $warning");
                    }
                }
            }

            $startTime = microtime(true);

            // Read namespace from lock file if present
            $namespace = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData?->namespace;
            }

            // Phase 1: Tear down existing containers
            $formatter->section('Tearing down containers');
            $downPanel = new LiveLogPanel($formatter->createSection(), 3);
            try {
                $this->dockerCompose->down(
                    $config->docker->composeFile,
                    false,
                    $namespace,
                    static function (string $type, string $buffer) use ($downPanel): void {
                        $downPanel->appendBuffer($buffer);
                    }
                );
                $downPanel->clear();
                $formatter->info('Containers stopped');
            } catch (\RuntimeException $e) {
                $downPanel->clear();
                $formatter->warning('Could not stop containers (they may not be running): ' . $e->getMessage());
            }

            // Clean up override file from previous run
            $this->overrideGenerator->cleanup($config->docker->composeFile);

            // Phase 2: Rebuild images and start containers
            $formatter->section('Rebuilding images and starting containers');
            $buildPanel = new LiveLogPanel($formatter->createSection(), 3);
            try {
                $this->dockerCompose->upWithBuild(
                    $config->docker->composeFile,
                    $namespace,
                    static function (string $type, string $buffer) use ($buildPanel): void {
                        $buildPanel->appendBuffer($buffer);
                    }
                );
            } finally {
                $buildPanel->clear();
            }
            $formatter->info('Containers rebuilt and started');

            // Phase 3: Wait for services to be healthy (with live rolling logs)
            if (!empty($config->docker->waitFor)) {
                $formatter->section('Waiting for services');
                $waiter = new ServiceReadinessWaiter(
                    $this->dockerCompose,
                    $this->healthChecker,
                    $formatter,
                );
                $waiter->waitForAll($config->docker->composeFile, $config->docker->waitFor, $namespace);
            }

            // Phase 4: Run `fresh` if defined
            if (isset($config->commands['fresh']) && trim($config->commands['fresh']->command) !== '') {
                $formatter->section('Running fresh');
                $this->commandOrchestrator->run('fresh', $config);
            } else {
                $formatter->warning('Command \'fresh\' is not defined in ngramx.yml — skipping database reset');
                $formatter->info('Define a \'fresh\' command to have rebuild automatically reset your database');
            }

            $totalTime = microtime(true) - $startTime;
            $output->writeln('');
            $output->writeln(sprintf('<fg=#7D55C7>Environment rebuilt successfully (%.1fs)</>', $totalTime));
            $output->writeln('');

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
}

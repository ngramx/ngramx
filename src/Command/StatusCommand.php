<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\HealthChecker;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly LockFile $lockFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Check the health status of services');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            // Read lock file to get namespace and port offset
            $namespace = null;
            $portOffset = 0;

            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData->namespace ?? null;
                $portOffset = $lockData->portOffset ?? 0;
            }

            // If no lock file, use null (default mode - no namespace isolation)

            $formatter->section('Environment Status');

            // Display instance information
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $output->writeln(sprintf('<fg=cyan>Namespace:</> %s', $namespace));
                if ($portOffset > 0) {
                    $output->writeln(sprintf('<fg=cyan>Port offset:</> +%d', $portOffset));
                }
                $output->writeln(sprintf('<fg=cyan>Started:</> %s', $lockData->startedAt ?? 'unknown'));
                $output->writeln('');
            }

            // Check if services are running
            if (!$this->dockerCompose->isRunning($config->docker->composeFile, $namespace)) {
                $formatter->warning('No services are currently running');
                $formatter->info('Run "ngramx up" to start the environment');
                return Command::SUCCESS;
            }

            // Get service info
            $services = $this->dockerCompose->ps($config->docker->composeFile, $namespace);

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
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

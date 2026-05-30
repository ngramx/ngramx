<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Docker\ComposeOverrideGenerator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Herd\HerdService;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DownCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
        private readonly ComposeOverrideGenerator $overrideGenerator,
        private readonly HerdService $herdService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('down')
            ->setDescription('Tear down the development environment')
            ->addOption('volumes', null, InputOption::VALUE_NONE, 'Remove volumes as well');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            // Load configuration to get compose file path
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $formatter->section('Stopping environment');

            // Read lock file to get namespace and Herd state
            $namespace = null;
            $herdStopped = false;
            $caddyStopped = false;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                if ($lockData !== null) {
                    $namespace = $lockData->namespace;
                    $herdStopped = $lockData->herdStopped;
                    $caddyStopped = $lockData->caddyStopped;
                }
            }

            $removeVolumes = $input->getOption('volumes');

            // Stop Docker services
            $this->dockerCompose->down($config->docker->composeFile, $removeVolumes, $namespace);

            // Clean up override file
            $this->overrideGenerator->cleanup();

            // Delete lock file
            $this->lockFile->delete();

            if ($removeVolumes) {
                $formatter->info('Docker services stopped and volumes removed');
            } else {
                $formatter->info('Docker services stopped');
            }

            // Restart Herd if it was stopped during "ngramx up"
            if ($herdStopped) {
                $formatter->info('Restarting Herd services...');
                try {
                    $this->herdService->start();
                    $formatter->info('Herd services restarted');
                } catch (\RuntimeException $e) {
                    $formatter->warning('Could not restart Herd: ' . $e->getMessage());
                    $formatter->info('You can restart manually with: herd start');
                }
            }

            if ($caddyStopped) {
                $formatter->info('Caddy was stopped before this session; start it again manually if you still need it.');
            }

            $output->writeln('');
            $output->writeln(sprintf('<fg=#7D55C7>Environment stopped successfully</>'));
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

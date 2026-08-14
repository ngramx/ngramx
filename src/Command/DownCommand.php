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
use Ngramx\Postmaclone\PostmacloneLock;
use Ngramx\Postmaclone\PostmacloneService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class DownCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
        private readonly ComposeOverrideGenerator $overrideGenerator,
        private readonly HerdService $herdService,
        private readonly PostmacloneService $postmacloneService = new PostmacloneService(),
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
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
            $projectRoot = dirname($configPath);

            $formatter->section('Stopping environment');

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

            // Tear down Postmaclone clone first (restores .env, restarts real db).
            if ((new PostmacloneLock($projectRoot))->exists()) {
                $formatter->info('Tearing down Postmaclone clone…');
                try {
                    $this->postmacloneService->destroy($config, $projectRoot, force: true);
                    $formatter->info('Postmaclone clone removed');
                } catch (\Throwable $e) {
                    $formatter->warning('Postmaclone teardown: ' . $e->getMessage());
                }
            }

            $removeVolumes = $input->getOption('volumes');
            $composeProject = $namespace ?? $this->defaultComposeProjectName($config->docker->composeFile);

            $this->dockerCompose->down($config->docker->composeFile, $removeVolumes, $namespace);

            // Catch stragglers (e.g. services recreated with a different compose file set).
            try {
                $this->dockerCompose->downProject($composeProject, $removeVolumes);
            } catch (\Throwable $e) {
                $formatter->warning('Project cleanup: ' . $e->getMessage());
            }
            $this->removeLabeledLeftovers($composeProject, $formatter);

            $this->overrideGenerator->cleanup($config->docker->composeFile);
            $this->lockFile->delete();

            if ($removeVolumes) {
                $formatter->info('Docker services stopped and volumes removed');
            } else {
                $formatter->info('Docker services stopped');
            }

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

    private function defaultComposeProjectName(string $composeFile): string
    {
        $dir = realpath($composeFile) !== false
            ? dirname((string) realpath($composeFile))
            : dirname($composeFile);

        return strtolower(basename($dir));
    }

    private function removeLabeledLeftovers(string $composeProject, OutputFormatter $formatter): void
    {
        $list = new Process([
            'docker', 'ps', '-aq',
            '--filter', 'label=com.docker.compose.project=' . $composeProject,
        ]);
        $list->run();
        if (!$list->isSuccessful()) {
            return;
        }

        $ids = array_filter(preg_split('/\s+/', trim($list->getOutput())) ?: []);
        if ($ids === []) {
            return;
        }

        $rm = new Process(array_merge(['docker', 'rm', '-f'], $ids));
        $rm->setTimeout(60);
        $rm->run();
        if ($rm->isSuccessful()) {
            $formatter->info('Removed ' . count($ids) . ' leftover compose container(s)');
        }
    }
}

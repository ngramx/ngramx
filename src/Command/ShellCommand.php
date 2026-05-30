<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Docker\ContainerExecutor;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShellCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly ContainerExecutor $containerExecutor,
        private readonly LockFile $lockFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('shell')
            ->setDescription('Open an interactive bash shell in the primary service container');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $primaryService = $config->docker->primaryService;
            $composeFile = $config->docker->composeFile;

            // Read lock file to get namespace
            $namespace = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData?->namespace;
            }

            // If no lock file, use null (default mode - no namespace isolation)

            // Build a custom PS1 prompt with Gigabyte brand colors
            // Purple (#7D55C7) for container name, Teal (#2ED9C3) for directory path
            // Single-escaped: passed as Docker -e var, interpreted directly by bash
            $purple = '\[\033[38;2;125;85;199m\]';
            $teal = '\[\033[38;2;46;217;195m\]';
            $reset = '\[\033[0m\]';

            $prompt = $purple . $primaryService . $reset . ':' . $teal . '\w' . $reset . '\$ ';

            $exitCode = $this->containerExecutor->execInteractiveWithEnv(
                $composeFile,
                $primaryService,
                '/bin/bash',
                ['PS1' => $prompt],
                $namespace,
            );

            return $exitCode;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

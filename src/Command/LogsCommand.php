<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Docker\ContainerExecutor;
use Ngramx\Laravel\LaravelLogParser;
use Ngramx\Laravel\LaravelService;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LogsCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly ContainerExecutor $containerExecutor,
        private readonly LockFile $lockFile,
        private readonly LaravelService $laravelService,
        private readonly LaravelLogParser $logParser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('logs')
            ->setDescription('Tail or summarise Laravel application logs')
            ->addOption('summary', 's', InputOption::VALUE_NONE, 'Show a grouped summary of errors and warnings')
            ->addOption('lines', 'l', InputOption::VALUE_REQUIRED, 'Number of lines to show', '50')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only include entries from this duration ago (e.g. 10m, 2h, 1d)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $primaryService = $config->docker->primaryService;
            $composeFile = $config->docker->composeFile;

            $namespace = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData?->namespace;
            }

            $logPath = $this->laravelService->resolveLogPath($composeFile, $primaryService, $namespace);
            if ($logPath === null) {
                $formatter->error('Could not find a Laravel log file in the container.');
                return Command::FAILURE;
            }

            if ($input->getOption('summary')) {
                return $this->runSummary($input, $output, $formatter, $composeFile, $primaryService, $namespace, $logPath);
            }

            return $this->runTail($input, $formatter, $composeFile, $primaryService, $namespace, $logPath);
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function runTail(
        InputInterface $input,
        OutputFormatter $formatter,
        string $composeFile,
        string $service,
        ?string $namespace,
        string $logPath,
    ): int {
        $lines = (int) $input->getOption('lines');
        $escapedPath = escapeshellarg($logPath);

        $formatter->info("Tailing $logPath (last $lines lines, Ctrl+C to stop)");

        return $this->containerExecutor->execInteractive(
            $composeFile,
            $service,
            "tail -n $lines -f $escapedPath",
            $namespace
        );
    }

    private function runSummary(
        InputInterface $input,
        OutputInterface $output,
        OutputFormatter $formatter,
        string $composeFile,
        string $service,
        ?string $namespace,
        string $logPath,
    ): int {
        $lines = (int) $input->getOption('lines');
        $escapedPath = escapeshellarg($logPath);

        $formatter->section('Log Summary');

        $process = $this->containerExecutor->exec(
            $composeFile,
            $service,
            "tail -n $lines $escapedPath",
            30,
            null,
            $namespace
        );

        if (!$process->isSuccessful()) {
            $formatter->error('Failed to read log file: ' . trim($process->getErrorOutput()));
            return Command::FAILURE;
        }

        $rawLog = $process->getOutput();
        $entries = $this->logParser->parse($rawLog);

        if (empty($entries)) {
            $formatter->info('No log entries found.');
            return Command::SUCCESS;
        }

        // Filter to errors/warnings only for summary
        $entries = $this->logParser->filterErrorsAndWarnings($entries);

        // Apply --since filter
        $sinceOption = $input->getOption('since');
        if ($sinceOption !== null) {
            $seconds = $this->logParser->parseDuration($sinceOption);
            $cutoff = new \DateTimeImmutable("-{$seconds} seconds");
            $entries = $this->logParser->filterSince($entries, $cutoff);
        }

        if (empty($entries)) {
            $formatter->info('No errors or warnings found in the selected range.');
            return Command::SUCCESS;
        }

        $summary = $this->logParser->summarise($entries);

        $output->writeln('');
        foreach ($summary as $entry) {
            $levelColor = match ($entry->level) {
                'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'red',
                'WARNING' => 'yellow',
                default => 'gray',
            };

            $countLabel = $entry->count > 1 ? " <fg=gray>(x{$entry->count})</>" : '';
            $output->writeln(sprintf(
                '  <fg=%s>%-9s</> %s%s',
                $levelColor,
                $entry->level,
                $entry->message,
                $countLabel,
            ));
            $output->writeln(sprintf(
                '           <fg=gray>Last: %s</>',
                $entry->lastOccurrence->format('Y-m-d H:i:s'),
            ));
            $output->writeln('');
        }

        $total = array_sum(array_map(fn ($e) => $e->count, $summary));
        $unique = count($summary);
        $output->writeln(sprintf(
            '<fg=#D2DCE5>  %d total entries, %d unique</>',
            $total,
            $unique,
        ));
        $output->writeln('');

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Cortex\Orchestrator;

use Cortex\Config\Schema\CortexConfig;
use Cortex\Docker\ContainerExecutor;
use Cortex\Executor\ContainerCommandExecutor;
use Cortex\Executor\ParallelContainerExecutor;
use Cortex\Executor\Result\ParallelCommandResult;
use Cortex\Output\OutputFormatter;
use Cortex\Output\ParallelCommandPanel;
use Symfony\Component\Process\Process;

class CommandOrchestrator
{
    public function __construct(
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * Run a custom command from cortex.yml
     *
     * @throws \RuntimeException
     */
    public function run(string $commandName, CortexConfig $config, ?string $projectName = null): float
    {
        if (!isset($config->commands[$commandName])) {
            throw new \RuntimeException("Command '$commandName' not found in cortex.yml");
        }

        $cmd = $config->commands[$commandName];

        if ($cmd->isParallel()) {
            return $this->runParallel($commandName, $cmd->commands, $cmd->timeout, $cmd->description, $config, $projectName);
        }

        return $this->runSingle($commandName, $config, $projectName);
    }

    /**
     * List all available custom commands
     *
     * @return array<string, string> Command name => description
     */
    public function listAvailableCommands(CortexConfig $config): array
    {
        $commands = [];
        foreach ($config->commands as $name => $cmd) {
            $commands[$name] = $cmd->description;
        }
        return $commands;
    }

    /**
     * Derive a short, unique label for each command string.
     *
     * Strategy: take the first whitespace-delimited token, strip any directory
     * prefix (e.g. `vendor/bin/phpstan` → `phpstan`). If two commands produce
     * the same label, append `#2`, `#3`, etc. in declaration order.
     *
     * @param list<string> $commands
     * @return list<string>
     */
    public static function deriveLabels(array $commands): array
    {
        $base = [];
        foreach ($commands as $command) {
            $trimmed = ltrim($command);
            $firstToken = strtok($trimmed, " \t\n");
            if ($firstToken === false) {
                $base[] = 'cmd';
                continue;
            }
            $label = basename($firstToken);
            $base[] = $label !== '' ? $label : 'cmd';
        }

        $counts = array_count_values($base);
        $seen = [];
        $result = [];
        foreach ($base as $label) {
            if (($counts[$label] ?? 0) <= 1) {
                $result[] = $label;
                continue;
            }
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $result[] = $seen[$label] === 1 ? $label : $label . '#' . $seen[$label];
        }

        return $result;
    }

    private function runSingle(string $commandName, CortexConfig $config, ?string $projectName = null): float
    {
        $cmd = $config->commands[$commandName];
        $startTime = microtime(true);

        $this->formatter->section("Running: $commandName");
        $this->formatter->command($cmd);

        $containerExecutor = new ContainerCommandExecutor(
            new ContainerExecutor(),
            $config->docker->composeFile,
            $config->docker->primaryService,
            $projectName
        );

        $outputCallback = function ($type, $buffer): void {
            if ($type === Process::OUT || $type === Process::ERR) {
                $lines = explode("\n", rtrim($buffer));
                foreach ($lines as $line) {
                    if (!empty(trim($line))) {
                        $this->formatter->commandOutput($line);
                    }
                }
            }
        };

        $result = $containerExecutor->execute($cmd, $outputCallback);

        if (!$result->isSuccessful()) {
            if (str_contains($result->errorOutput, 'is not running') || str_contains($result->output, 'is not running')) {
                $this->formatter->error("Services are not running. Start them with 'cortex up' first.");
            } else {
                $this->formatter->error("Command failed with exit code {$result->exitCode}");
            }
            throw new \RuntimeException("Command '$commandName' failed");
        }

        return microtime(true) - $startTime;
    }

    /**
     * @param list<string> $commands
     */
    private function runParallel(
        string $commandName,
        array $commands,
        int $timeout,
        string $description,
        CortexConfig $config,
        ?string $projectName = null,
    ): float {
        $startTime = microtime(true);

        $this->formatter->section("Running: $commandName");
        $this->formatter->info($description);

        $labels = self::deriveLabels($commands);
        /** @var list<array{label: string, command: string, timeout: int}> $items */
        $items = [];
        foreach ($commands as $i => $command) {
            $items[] = [
                'label' => $labels[$i],
                'command' => $command,
                'timeout' => $timeout,
            ];
        }

        $section = $this->formatter->createSection();
        $panel = new ParallelCommandPanel($section, $labels, $this->formatter->getOutput());

        $executor = new ParallelContainerExecutor(
            new ContainerExecutor(),
            $config->docker->composeFile,
            $config->docker->primaryService,
            $projectName,
        );

        $results = $executor->runAll(
            $items,
            onOutput: static function (string $label, string $line) use ($panel): void {
                $panel->updateLine($label, $line);
            },
            onFinish: static function (ParallelCommandResult $result) use ($panel): void {
                $panel->markFinished($result->label);
            },
        );

        $panel->close();

        $failed = array_values(array_filter($results, static fn (ParallelCommandResult $r) => !$r->successful));

        if ($failed !== []) {
            $this->reportFailures($results, $failed);
            throw new \RuntimeException("Command '$commandName' failed: " . count($failed) . ' of ' . count($results) . ' sub-commands failed');
        }

        return microtime(true) - $startTime;
    }

    /**
     * @param list<ParallelCommandResult> $results
     * @param list<ParallelCommandResult> $failed
     */
    private function reportFailures(array $results, array $failed): void
    {
        $out = $this->formatter->getOutput();
        $out->writeln('');

        foreach ($results as $result) {
            $status = $result->successful ? 'ok' : ($result->timedOut ? 'timed out' : 'failed');
            $color = $result->successful ? OutputFormatter::COLOR_PURPLE : 'red';
            $out->writeln(sprintf(
                '  <fg=%s>%s</> <fg=%s>%s</> (%.1fs)',
                OutputFormatter::COLOR_TEAL,
                $result->label,
                $color,
                $status,
                $result->executionTime,
            ));
        }

        foreach ($failed as $result) {
            $out->writeln('');
            $out->writeln(sprintf(
                '<fg=red>%s</> exited with code %d',
                $result->label,
                $result->exitCode,
            ));

            $tail = array_slice($result->outputLines, -20);
            foreach ($tail as $line) {
                $out->writeln('    <fg=gray>' . $line . '</>');
            }
        }

        $out->writeln('');
    }
}

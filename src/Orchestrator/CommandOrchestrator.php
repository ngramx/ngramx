<?php

declare(strict_types=1);

namespace Ngramx\Orchestrator;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Docker\ContainerExecutor;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\HealthChecker;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Executor\ContainerCommandExecutor;
use Ngramx\Executor\ParallelContainerExecutor;
use Ngramx\Executor\Result\ParallelCommandResult;
use Ngramx\Output\OutputFormatter;
use Ngramx\Output\ParallelCommandPanel;
use Symfony\Component\Process\Process;

class CommandOrchestrator
{
    /**
     * Default budget for the pre-command readiness gate when the primary
     * service has no explicit `wait_for` entry. Long enough for a heavy
     * entrypoint (composer/npm install, migrations) re-running on boot.
     */
    private const DEFAULT_READINESS_TIMEOUT = 300;

    private readonly ServiceReadinessWaiter $readinessWaiter;

    public function __construct(
        private readonly OutputFormatter $formatter,
        ?ServiceReadinessWaiter $readinessWaiter = null,
    ) {
        $this->readinessWaiter = $readinessWaiter ?? new ServiceReadinessWaiter(
            new DockerCompose(),
            new HealthChecker(),
            $this->formatter,
            new ContainerExecutor(),
        );
    }

    /**
     * Run a custom command from ngramx.yml
     *
     * @throws \RuntimeException
     */
    public function run(string $commandName, NgramxConfig $config, ?string $projectName = null): float
    {
        if (!isset($config->commands[$commandName])) {
            throw new \RuntimeException("Command '$commandName' not found in ngramx.yml");
        }

        $cmd = $config->commands[$commandName];

        // Gate every container command behind a real readiness probe so we never
        // fire `docker compose exec` at a primary service whose entrypoint is
        // still installing dependencies or running migrations.
        $this->ensurePrimaryServiceReady($config, $projectName);

        if ($cmd->isParallel()) {
            return $this->runParallel($commandName, $cmd->commands, $cmd->timeout, $cmd->description, $config, $projectName);
        }

        if ($cmd->isSequentialList()) {
            return $this->runSequentialList($commandName, $cmd, $config, $projectName);
        }

        return $this->runSingle($commandName, $config, $projectName);
    }

    /**
     * List all available custom commands
     *
     * @return array<string, string> Command name => description
     */
    public function listAvailableCommands(NgramxConfig $config): array
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

    /**
     * Block until the primary service passes its configured readiness probe.
     * Reuses the `wait_for` entry for the primary service when present so the
     * gate honours the project's healthcheck/ready_command/ready_log settings;
     * otherwise falls back to a default-timeout running check.
     *
     * @throws \Ngramx\Docker\Exception\ServiceNotHealthyException
     */
    private function ensurePrimaryServiceReady(NgramxConfig $config, ?string $projectName): void
    {
        $waitConfig = $this->resolvePrimaryWaitConfig($config);

        $this->readinessWaiter->waitForReady(
            $config->docker->composeFile,
            $waitConfig,
            $projectName,
        );
    }

    private function resolvePrimaryWaitConfig(NgramxConfig $config): ServiceWaitConfig
    {
        $primary = $config->docker->primaryService;

        foreach ($config->docker->waitFor as $waitConfig) {
            if ($waitConfig->service === $primary) {
                return $waitConfig;
            }
        }

        return new ServiceWaitConfig(service: $primary, timeout: self::DEFAULT_READINESS_TIMEOUT);
    }

    private function runSingle(string $commandName, NgramxConfig $config, ?string $projectName = null): float
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
                $this->formatter->error("Services are not running. Start them with 'ngramx up' first.");
            } else {
                $this->formatter->error("Command failed with exit code {$result->exitCode}");
            }
            throw new \RuntimeException("Command '$commandName' failed");
        }

        return microtime(true) - $startTime;
    }

    /**
     * Run a multi-command entry one step at a time, in declaration order,
     * stopping at the first failure. This is the right mode for steps that have
     * ordering dependencies (e.g. install deps, then migrate, then clear caches)
     * which would race against each other if run concurrently.
     */
    private function runSequentialList(
        string $commandName,
        CommandDefinition $cmd,
        NgramxConfig $config,
        ?string $projectName = null,
    ): float {
        $startTime = microtime(true);

        $this->formatter->section("Running: $commandName");
        $this->formatter->info($cmd->description);

        $containerExecutor = new ContainerCommandExecutor(
            new ContainerExecutor(),
            $config->docker->composeFile,
            $config->docker->primaryService,
            $projectName,
        );

        $total = count($cmd->commands);

        foreach ($cmd->commands as $index => $command) {
            $step = $index + 1;
            $this->formatter->getOutput()->writeln(sprintf(
                '  <fg=%s>[%d/%d]</> %s',
                OutputFormatter::COLOR_TEAL,
                $step,
                $total,
                $command,
            ));

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

            $stepCmd = new CommandDefinition(
                command: $command,
                description: '',
                timeout: $cmd->timeout,
            );

            $result = $containerExecutor->execute($stepCmd, $outputCallback);

            if (!$result->isSuccessful()) {
                if (str_contains($result->errorOutput, 'is not running') || str_contains($result->output, 'is not running')) {
                    $this->formatter->error("Services are not running. Start them with 'ngramx up' first.");
                } else {
                    $this->formatter->error("Step $step of $total failed with exit code {$result->exitCode}: $command");
                }
                throw new \RuntimeException("Command '$commandName' failed at step $step of $total: $command");
            }
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
        NgramxConfig $config,
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

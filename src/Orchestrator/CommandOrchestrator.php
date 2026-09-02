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
use Ngramx\Executor\Result\ExecutionResult;
use Ngramx\Executor\Result\ParallelCommandResult;
use Ngramx\Executor\Retry\RetryPolicy;
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

    private readonly RetryPolicy $retryPolicy;

    private readonly DockerCompose $dockerCompose;

    /** @var \Closure(NgramxConfig, ?string): ContainerCommandExecutor */
    private readonly \Closure $containerExecutorFactory;

    /** @var \Closure(NgramxConfig, ?string): ParallelContainerExecutor */
    private readonly \Closure $parallelExecutorFactory;

    /**
     * @param callable(NgramxConfig, ?string): ParallelContainerExecutor|null $parallelExecutorFactory Test seam for the parallel executor
     * @param callable(int): void|null $retrySleep Test seam for the inter-retry delay
     * @param callable(NgramxConfig, ?string): ContainerCommandExecutor|null $containerExecutorFactory Test seam for the single/sequential executor
     */
    public function __construct(
        private readonly OutputFormatter $formatter,
        ?ServiceReadinessWaiter $readinessWaiter = null,
        ?callable $parallelExecutorFactory = null,
        ?callable $retrySleep = null,
        ?callable $containerExecutorFactory = null,
        ?DockerCompose $dockerCompose = null,
    ) {
        $this->dockerCompose = $dockerCompose ?? new DockerCompose();
        $this->readinessWaiter = $readinessWaiter ?? new ServiceReadinessWaiter(
            $this->dockerCompose,
            new HealthChecker(),
            $this->formatter,
            new ContainerExecutor(),
        );
        $this->parallelExecutorFactory = $parallelExecutorFactory !== null
            ? $parallelExecutorFactory(...)
            : static fn (NgramxConfig $config, ?string $projectName): ParallelContainerExecutor => new ParallelContainerExecutor(
                new ContainerExecutor(),
                $config->docker->composeFile,
                $config->docker->primaryService,
                $projectName,
            );
        $this->containerExecutorFactory = $containerExecutorFactory !== null
            ? $containerExecutorFactory(...)
            : static fn (NgramxConfig $config, ?string $projectName): ContainerCommandExecutor => new ContainerCommandExecutor(
                new ContainerExecutor(),
                $config->docker->composeFile,
                $config->docker->primaryService,
                $projectName,
            );
        $this->retryPolicy = new RetryPolicy($retrySleep);
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
            return $this->runParallel($commandName, $cmd, $config, $projectName);
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

        $containerExecutor = ($this->containerExecutorFactory)($config, $projectName);

        $result = $this->executeWithRetry($containerExecutor, $cmd, $config, $projectName);

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

        $containerExecutor = ($this->containerExecutorFactory)($config, $projectName);

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

            $stepCmd = new CommandDefinition(
                command: $command,
                description: '',
                timeout: $cmd->timeout,
                // Each step inherits the group's retry setting, so `retry:` on a
                // sequential command applies to whichever step actually fails.
                retry: $cmd->retry,
            );

            $result = $this->executeWithRetry($containerExecutor, $stepCmd, $config, $projectName);

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

    private function runParallel(
        string $commandName,
        CommandDefinition $cmd,
        NgramxConfig $config,
        ?string $projectName = null,
    ): float {
        $startTime = microtime(true);

        $commands = $cmd->commands;
        $timeout = $cmd->timeout;

        $this->formatter->section("Running: $commandName");
        $this->formatter->info($cmd->description);

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

        $executor = ($this->parallelExecutorFactory)($config, $projectName);

        $results = $this->runParallelBatch($executor, $items);

        // First runs of a parallel group can race against each other (e.g. an
        // artisan command firing before composer install has finished), so
        // every failure here is treated as potentially transient regardless of
        // what it printed — a race shows up as whatever error the missing
        // dependency happens to produce. Re-run only the failed sub-commands —
        // the successful ones have already completed — pausing a little longer
        // before each attempt to let a settling dependency finish.
        $maxAttempts = $this->retryPolicy->attemptsFor($cmd);
        $failedIndexes = $this->failedIndexes($results);
        $recreated = false;

        for ($attempt = 2; $failedIndexes !== [] && $attempt <= $maxAttempts; $attempt++) {
            // A container that has gone away, or whose bind mount no longer
            // resolves, fails every sub-command identically forever. Recreate
            // it once — retrying inside it cannot help.
            if (!$recreated && $this->needsRecreate($results, $failedIndexes)) {
                $recreated = true;
                $this->recreatePrimaryService($config, $projectName);
            }

            $this->retryPolicy->pauseBeforeRetry($attempt - 1);

            $retryLabels = array_map(static fn (int $i) => $items[$i]['label'], $failedIndexes);
            $this->formatter->info(sprintf(
                'Retrying %d sub-command%s that failed on the previous run (attempt %d of %d): %s',
                count($failedIndexes),
                count($failedIndexes) === 1 ? '' : 's',
                $attempt,
                $maxAttempts,
                implode(', ', $retryLabels),
            ));

            $retryItems = array_map(static fn (int $i) => $items[$i], $failedIndexes);
            $retryResults = $this->runParallelBatch($executor, $retryItems);

            foreach ($failedIndexes as $position => $index) {
                $results[$index] = $retryResults[$position];
            }

            $failedIndexes = $this->failedIndexes($results);
        }

        if ($failedIndexes !== []) {
            $failed = array_map(static fn (int $i) => $results[$i], $failedIndexes);
            $this->reportFailures($results, $failed);
            throw new \RuntimeException("Command '$commandName' failed: " . count($failed) . ' of ' . count($results) . ' sub-commands failed');
        }

        return microtime(true) - $startTime;
    }

    /**
     * Run one command inside the container, re-running it while the failure
     * looks like the environment rather than the command itself.
     *
     * A step can fail because it genuinely does not work (a failing test, a bad
     * migration) or because it fired at an environment that was not ready yet —
     * an entrypoint still installing dependencies, a database socket not
     * listening, a bind mount replaced under a running container. Only the
     * second kind is worth another attempt, so {@see RetryPolicy} classifies
     * the failure and a real one is reported straight away instead of being
     * run three times.
     */
    private function executeWithRetry(
        ContainerCommandExecutor $executor,
        CommandDefinition $cmd,
        NgramxConfig $config,
        ?string $projectName,
    ): ExecutionResult {
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

        $maxAttempts = $this->retryPolicy->attemptsFor($cmd);
        $recreated = false;
        $dependenciesRestarted = false;

        for ($attempt = 1;; $attempt++) {
            $result = $executor->execute($cmd, $outputCallback);

            if ($result->isSuccessful() || $attempt >= $maxAttempts) {
                return $result;
            }

            if (!$this->retryPolicy->shouldRetry($cmd, $result->exitCode, $result->output, $result->errorOutput)) {
                return $result;
            }

            $this->formatter->info(sprintf(
                'Attempt %d of %d failed with what looks like an environment problem — retrying.',
                $attempt,
                $maxAttempts,
            ));

            if (!$recreated && $this->retryPolicy->needsContainerRecreate($result->output, $result->errorOutput)) {
                $recreated = true;
                $this->recreatePrimaryService($config, $projectName);
            }

            // A dead *dependency* is not something another attempt can fix. The
            // command's own container is fine; the database it needs has gone.
            // Bring it back before spending the next attempt, or all three
            // attempts report the same missing host.
            if (!$dependenciesRestarted
                && $this->retryPolicy->needsDependencyRestart($result->output, $result->errorOutput)
            ) {
                $dependenciesRestarted = true;
                $this->restartStoppedDependencies($config, $projectName);
            }

            $this->retryPolicy->pauseBeforeRetry($attempt);
        }
    }

    /**
     * Start any service in this stack whose container has exited, then wait for
     * the primary service to be ready again.
     *
     * The case this exists for: the host runs out of memory, the kernel's OOM
     * killer picks the biggest process in the stack (mysql, every time), and
     * because the compose file declares no restart policy the container stays
     * dead. The app container survives, so commands keep running — and keep
     * failing with `Unknown MySQL server host 'mysql'`, which sounds like a
     * database or DNS fault and is really a container that is not there.
     *
     * An exit code of 137 is called out by name. It is a SIGKILL, and on a dev
     * host that essentially always means memory: restarting the service will
     * get this run moving again, but the host is over-subscribed and will do it
     * again. Saying so here is the difference between someone reading Doctrine
     * stack traces and someone running `free -m`.
     */
    private function restartStoppedDependencies(NgramxConfig $config, ?string $projectName): void
    {
        $composeFile = $config->docker->composeFile;
        $primary = $config->docker->primaryService;

        $stopped = $this->dockerCompose->stoppedServices($composeFile, $projectName);

        // The primary service has its own repair path above, which recreates
        // rather than restarts it; leave it to that.
        unset($stopped[$primary]);

        if ($stopped === []) {
            return;
        }

        foreach ($stopped as $service => $exitCode) {
            if ($exitCode === 137) {
                $this->formatter->warning(
                    "The '$service' container was killed by the host (exit 137 — SIGKILL, "
                    . 'almost always the out-of-memory killer), which is why its hostname no longer '
                    . 'resolves. Starting it again, but the host is short of memory: check `free -m` '
                    . 'and how many dev environments are running.'
                );
            } else {
                $this->formatter->warning(
                    "The '$service' container has exited"
                    . ($exitCode !== null ? " (exit $exitCode)" : '')
                    . ' — starting it again before retrying.'
                );
            }

            try {
                $this->dockerCompose->startService($composeFile, (string) $service, $projectName);
            } catch (\Throwable $e) {
                $this->formatter->warning("Could not start '$service': " . $e->getMessage());
            }
        }

        // Restarting a database is only half the repair: the next attempt has to
        // wait for it to accept connections, which the project's `wait_for`
        // entries describe.
        try {
            $this->waitForRestartedServices($config, $projectName, array_keys($stopped));
        } catch (\Throwable $e) {
            $this->formatter->warning('Restarted services did not report ready: ' . $e->getMessage());
        }
    }

    /**
     * Wait for each restarted service that the project describes a readiness
     * probe for.
     *
     * Services with no `wait_for` entry are skipped rather than guessed at: a
     * generic "is the container up?" poll would return true while a database
     * was still importing, which is the race `wait_for` exists to close.
     *
     * @param list<array-key> $services
     */
    private function waitForRestartedServices(NgramxConfig $config, ?string $projectName, array $services): void
    {
        foreach ($config->docker->waitFor as $waitConfig) {
            if (!in_array($waitConfig->service, $services, true)) {
                continue;
            }

            $this->readinessWaiter->waitForReady(
                $config->docker->composeFile,
                $waitConfig,
                $projectName,
            );
        }
    }

    /**
     * @param array<int, ParallelCommandResult> $results
     * @param list<int> $failedIndexes
     */
    private function needsRecreate(array $results, array $failedIndexes): bool
    {
        foreach ($failedIndexes as $index) {
            if ($this->retryPolicy->needsContainerRecreate(...$results[$index]->outputLines)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace the primary service and wait for it to come back ready.
     *
     * This is the escape hatch for a container that cannot succeed no matter
     * how often a command is retried inside it: it has exited, or it is holding
     * a bind mount whose source directory was deleted and recreated (a worktree
     * torn down and rebuilt while its stack was left running), which leaves
     * every command with a working directory that resolves to nothing.
     */
    private function recreatePrimaryService(NgramxConfig $config, ?string $projectName): void
    {
        $service = $config->docker->primaryService;

        $this->formatter->warning(
            "The '$service' container is unusable (gone, or holding a mount that no longer resolves) — "
            . 'recreating it before retrying.'
        );

        try {
            $this->dockerCompose->recreateService($config->docker->composeFile, $service, $projectName);
            $this->ensurePrimaryServiceReady($config, $projectName);
        } catch (\Throwable $e) {
            $this->formatter->warning('Could not recreate the container automatically: ' . $e->getMessage());
        }
    }

    /**
     * Run one batch of parallel sub-commands with a live progress panel.
     *
     * @param list<array{label: string, command: string, timeout: int}> $items
     * @return list<ParallelCommandResult> results in the same order as $items
     */
    private function runParallelBatch(ParallelContainerExecutor $executor, array $items): array
    {
        $labels = array_map(static fn (array $item) => $item['label'], $items);

        $section = $this->formatter->createSection();
        $panel = new ParallelCommandPanel($section, $labels, $this->formatter->getOutput());

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

        return $results;
    }

    /**
     * @param array<int, ParallelCommandResult> $results
     * @return list<int> indexes into $results of the failed sub-commands
     */
    private function failedIndexes(array $results): array
    {
        $indexes = [];
        foreach ($results as $index => $result) {
            if (!$result->successful) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param array<int, ParallelCommandResult> $results
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

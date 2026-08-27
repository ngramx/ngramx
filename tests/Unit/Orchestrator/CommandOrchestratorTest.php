<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Orchestrator;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Executor\ContainerCommandExecutor;
use Ngramx\Executor\ParallelContainerExecutor;
use Ngramx\Executor\Result\ExecutionResult;
use Ngramx\Executor\Result\ParallelCommandResult;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandOrchestratorTest extends TestCase
{
    public function test_derive_labels_uses_first_token_basename(): void
    {
        $labels = CommandOrchestrator::deriveLabels([
            'composer validate --strict',
            'vendor/bin/phpstan analyse src',
            'vendor/bin/phpunit',
        ]);

        $this->assertSame(['composer', 'phpstan', 'phpunit'], $labels);
    }

    public function test_derive_labels_disambiguates_duplicates_with_indices(): void
    {
        $labels = CommandOrchestrator::deriveLabels([
            'php artisan queue:work',
            'php artisan schedule:run',
            'php artisan horizon',
        ]);

        $this->assertSame(['php', 'php#2', 'php#3'], $labels);
    }

    public function test_derive_labels_handles_leading_whitespace(): void
    {
        $labels = CommandOrchestrator::deriveLabels([
            '   vendor/bin/phpunit --filter FooTest',
        ]);

        $this->assertSame(['phpunit'], $labels);
    }

    public function test_derive_labels_falls_back_for_empty_commands(): void
    {
        $labels = CommandOrchestrator::deriveLabels(['']);

        $this->assertSame(['cmd'], $labels);
    }

    public function test_run_blocks_on_readiness_before_executing(): void
    {
        $waiter = $this->createMock(ServiceReadinessWaiter::class);
        $waiter->expects($this->once())
            ->method('waitForReady')
            ->willThrowException(new ServiceNotHealthyException('Service \'app\' is crash-looping (exited).'));

        $orchestrator = new CommandOrchestrator(
            new OutputFormatter(new BufferedOutput()),
            $waiter,
        );

        $config = new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost',
                waitFor: [],
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: [
                'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh', description: 'reset'),
            ],
        );

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/crash-looping/');

        // The gate throws before any docker exec is attempted.
        $orchestrator->run('fresh', $config);
    }

    public function test_run_uses_primary_service_wait_config_for_gate(): void
    {
        $primaryWait = new ServiceWaitConfig(
            service: 'app',
            timeout: 120,
            readyCommand: 'php artisan --version',
        );

        $waiter = $this->createMock(ServiceReadinessWaiter::class);
        $waiter->expects($this->once())
            ->method('waitForReady')
            ->with('docker-compose.yml', $primaryWait, 'my-ns')
            ->willThrowException(new ServiceNotHealthyException('stop here'));

        $orchestrator = new CommandOrchestrator(
            new OutputFormatter(new BufferedOutput()),
            $waiter,
        );

        $config = new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost',
                waitFor: [
                    new ServiceWaitConfig(service: 'db', timeout: 60),
                    $primaryWait,
                ],
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: [
                'clear' => new CommandDefinition(command: 'php artisan cache:clear', description: 'clear'),
            ],
        );

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessage('stop here');

        $orchestrator->run('clear', $config, 'my-ns');
    }

    public function test_parallel_run_does_not_retry_when_all_sub_commands_succeed(): void
    {
        $batches = [];
        $executor = $this->fakeParallelExecutor($batches, [
            // Single batch: everything succeeds first time.
            ['migrate' => true, 'cache' => true],
        ]);

        $output = new BufferedOutput();
        $orchestrator = $this->createOrchestratorWithExecutor($executor, $output);

        $orchestrator->run('fresh', $this->parallelConfig(), null);

        $this->assertCount(1, $batches, 'A fully successful run must not trigger a retry batch');
        $this->assertStringNotContainsString('Retrying', $output->fetch());
    }

    public function test_parallel_run_retries_only_the_failed_sub_commands(): void
    {
        $batches = [];
        $executor = $this->fakeParallelExecutor($batches, [
            ['migrate' => true, 'cache' => false],
            ['cache' => true],
        ]);

        $output = new BufferedOutput();
        $orchestrator = $this->createOrchestratorWithExecutor($executor, $output);

        $orchestrator->run('fresh', $this->parallelConfig(), null);

        $this->assertCount(2, $batches);
        $this->assertSame(
            ['cache --clear'],
            array_column($batches[1], 'command'),
            'Only the failed sub-command may be re-run — the successful one already completed'
        );
        $this->assertStringContainsString('Retrying 1 sub-command', $output->fetch());
    }

    public function test_parallel_run_backs_off_further_before_each_retry(): void
    {
        $batches = [];
        $executor = $this->fakeParallelExecutor($batches, [
            ['migrate' => true, 'cache' => false],
            ['cache' => false],
            ['cache' => true],
        ]);

        $sleeps = [];
        $output = new BufferedOutput();
        $orchestrator = $this->createOrchestratorWithExecutor($executor, $output, $sleeps);

        $orchestrator->run('fresh', $this->parallelConfig(), null);

        $this->assertCount(3, $batches, 'Three attempts total: initial run + two retries');
        $this->assertSame(
            [3, 6],
            $sleeps,
            'Every retry waits, and waits longer than the last, so a dependency that is still '
            . 'installing gets more time on the attempt that matters most'
        );
    }

    public function test_parallel_run_fails_after_three_attempts(): void
    {
        $batches = [];
        $executor = $this->fakeParallelExecutor($batches, [
            ['migrate' => true, 'cache' => false],
            ['cache' => false],
            ['cache' => false],
        ]);

        $sleeps = [];
        $output = new BufferedOutput();
        $orchestrator = $this->createOrchestratorWithExecutor($executor, $output, $sleeps);

        try {
            $orchestrator->run('fresh', $this->parallelConfig(), null);
            $this->fail('Expected the run to fail after exhausting all attempts');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 of 2 sub-commands failed', $e->getMessage());
        }

        $this->assertCount(3, $batches, 'A persistently failing sub-command gets exactly three attempts');
        $this->assertSame([3, 6], $sleeps);
    }

    /**
     * The regression behind GIG-2814: `fresh` is declared `parallel: false`,
     * so it ran down a path that had no retries at all and the first
     * environmental hiccup failed the whole dev-environment setup.
     */
    public function test_sequential_run_retries_a_step_that_failed_for_environmental_reasons(): void
    {
        $attempts = [];
        $executor = $this->fakeContainerExecutor($attempts, [
            ['successful' => false, 'errorOutput' => 'SQLSTATE[HY000] [2002] Connection refused'],
            ['successful' => true],
            ['successful' => true],
        ]);

        $sleeps = [];
        $output = new BufferedOutput();
        $orchestrator = $this->createOrchestratorWithContainerExecutor($executor, $output, $sleeps);

        $orchestrator->run('fresh', $this->sequentialConfig(), null);

        $this->assertSame(
            ['migrate --fresh', 'migrate --fresh', 'cache --clear'],
            $attempts,
            'The failed step is re-run, then the list continues from where it was'
        );
        $this->assertSame([3], $sleeps);
    }

    public function test_sequential_run_does_not_retry_a_genuine_command_failure(): void
    {
        $attempts = [];
        $executor = $this->fakeContainerExecutor($attempts, [
            ['successful' => false, 'output' => "FAILURES!\nTests: 12, Failures: 1."],
        ]);

        $sleeps = [];
        $output = new BufferedOutput();
        $orchestrator = $this->createOrchestratorWithContainerExecutor($executor, $output, $sleeps);

        try {
            $orchestrator->run('fresh', $this->sequentialConfig(), null);
            $this->fail('Expected the sequential run to fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed at step 1 of 2', $e->getMessage());
        }

        $this->assertSame(
            ['migrate --fresh'],
            $attempts,
            'A real failure is reported straight away rather than run three times'
        );
        $this->assertSame([], $sleeps, 'and it costs the user no backoff either');
    }

    /**
     * A container holding a bind mount whose source directory was deleted and
     * recreated (a worktree torn down while its stack was left up) reports an
     * empty working directory. Retrying the same command inside it fails
     * identically forever, so the container has to be replaced first.
     */
    public function test_sequential_run_recreates_the_container_when_its_mount_has_gone_stale(): void
    {
        $attempts = [];
        $executor = $this->fakeContainerExecutor($attempts, [
            [
                'successful' => false,
                'output' => "Composer could not find a composer.json file in \nTo initialize a project",
            ],
            ['successful' => true],
            ['successful' => true],
        ]);

        $dockerCompose = $this->createMock(DockerCompose::class);
        $dockerCompose->expects($this->once())
            ->method('recreateService')
            ->with('docker-compose.yml', 'app', null);

        $sleeps = [];
        $output = new BufferedOutput();
        $orchestrator = $this->createOrchestratorWithContainerExecutor(
            $executor,
            $output,
            $sleeps,
            $dockerCompose,
        );

        $orchestrator->run('fresh', $this->sequentialConfig(), null);

        $this->assertStringContainsString('recreating it before retrying', $output->fetch());
    }

    public function test_sequential_run_honours_retry_zero(): void
    {
        $attempts = [];
        $executor = $this->fakeContainerExecutor($attempts, [
            ['successful' => false, 'errorOutput' => 'connection refused'],
        ]);

        $sleeps = [];
        $orchestrator = $this->createOrchestratorWithContainerExecutor(
            $executor,
            new BufferedOutput(),
            $sleeps,
        );

        try {
            $orchestrator->run('fresh', $this->sequentialConfig(retry: 0), null);
            $this->fail('Expected the sequential run to fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed at step 1 of 2', $e->getMessage());
        }

        $this->assertSame(['migrate --fresh'], $attempts, 'retry: 0 opts out of retries entirely');
    }

    /**
     * Build a fake single-command executor that records the command of every
     * attempt and plays back scripted outcomes, one entry per attempt.
     *
     * @param list<string> $attempts Commands seen, in order (by reference)
     * @param list<array{successful: bool, output?: string, errorOutput?: string, exitCode?: int}> $script
     */
    private function fakeContainerExecutor(array &$attempts, array $script): ContainerCommandExecutor
    {
        $executor = $this->createMock(ContainerCommandExecutor::class);
        $executor->method('execute')
            ->willReturnCallback(function (CommandDefinition $cmd) use (&$attempts, &$script): ExecutionResult {
                $attempts[] = $cmd->command;
                $outcome = array_shift($script) ?? ['successful' => true];

                return new ExecutionResult(
                    exitCode: $outcome['exitCode'] ?? ($outcome['successful'] ? 0 : 1),
                    output: $outcome['output'] ?? '',
                    errorOutput: $outcome['errorOutput'] ?? '',
                    successful: $outcome['successful'],
                    executionTime: 0.01,
                );
            });

        return $executor;
    }

    /**
     * @param list<int> $sleeps Captured retry delays (by reference)
     */
    private function createOrchestratorWithContainerExecutor(
        ContainerCommandExecutor $executor,
        BufferedOutput $output,
        array &$sleeps = [],
        ?DockerCompose $dockerCompose = null,
    ): CommandOrchestrator {
        return new CommandOrchestrator(
            new OutputFormatter($output),
            $this->createMock(ServiceReadinessWaiter::class),
            retrySleep: static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            containerExecutorFactory: static fn () => $executor,
            dockerCompose: $dockerCompose ?? $this->createMock(DockerCompose::class),
        );
    }

    private function sequentialConfig(?int $retry = null): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost',
                waitFor: [],
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: [
                'fresh' => new CommandDefinition(
                    command: '',
                    description: 'reset everything, in order',
                    retry: $retry,
                    commands: ['migrate --fresh', 'cache --clear'],
                    parallel: false,
                ),
            ],
        );
    }

    /**
     * Build a fake executor whose runAll() records each batch it receives and
     * plays back scripted per-label outcomes, one script entry per batch.
     *
     * @param list<array<string, mixed>> $batches Captured items per runAll call (by reference)
     * @param list<array<string, bool>> $script label => successful, per batch
     */
    private function fakeParallelExecutor(array &$batches, array $script): ParallelContainerExecutor
    {
        $executor = $this->createMock(ParallelContainerExecutor::class);
        $executor->method('runAll')
            ->willReturnCallback(function (array $items) use (&$batches, &$script): array {
                $batches[] = $items;
                $outcomes = array_shift($script);
                \assert(is_array($outcomes), 'runAll called more times than the test scripted');

                $results = [];
                foreach ($items as $item) {
                    $successful = $outcomes[$item['label']] ?? true;
                    $results[] = new ParallelCommandResult(
                        label: $item['label'],
                        command: $item['command'],
                        exitCode: $successful ? 0 : 1,
                        executionTime: 0.01,
                        successful: $successful,
                        outputLines: [],
                    );
                }

                return $results;
            });

        return $executor;
    }

    /**
     * @param list<int> $sleeps Captured retry delays (by reference)
     */
    private function createOrchestratorWithExecutor(
        ParallelContainerExecutor $executor,
        BufferedOutput $output,
        array &$sleeps = [],
    ): CommandOrchestrator {
        $waiter = $this->createMock(ServiceReadinessWaiter::class);

        return new CommandOrchestrator(
            new OutputFormatter($output),
            $waiter,
            parallelExecutorFactory: static fn () => $executor,
            retrySleep: static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );
    }

    private function parallelConfig(): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost',
                waitFor: [],
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: [
                // Distinct first tokens so the derived labels are 'migrate' and
                // 'cache', matching the outcome scripts in the tests above.
                'fresh' => new CommandDefinition(
                    command: '',
                    description: 'reset everything',
                    commands: ['migrate --fresh', 'cache --clear'],
                ),
            ],
        );
    }
}

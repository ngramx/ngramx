<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Orchestrator;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Executor\ParallelContainerExecutor;
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

    public function test_parallel_run_delays_the_final_retry(): void
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
        $this->assertSame([3], $sleeps, 'Only the final retry is delayed, by 3 seconds');
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
        $this->assertSame([3], $sleeps);
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

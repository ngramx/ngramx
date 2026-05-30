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
}

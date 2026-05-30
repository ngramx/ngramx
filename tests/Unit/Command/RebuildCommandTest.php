<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\RebuildCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\ComposeOverrideGenerator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\HealthChecker;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RebuildCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private HealthChecker $healthChecker;
    private CommandOrchestrator $commandOrchestrator;
    private LockFile $lockFile;
    private ComposeOverrideGenerator $overrideGenerator;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->overrideGenerator = $this->createMock(ComposeOverrideGenerator::class);

        $output = $this->createMock(OutputInterface::class);
        $formatter = new OutputFormatter($output);
        $this->commandOrchestrator = $this->createMock(CommandOrchestrator::class);

        $this->dockerCompose->method('isDockerRunning')->willReturn(true);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('rebuild', $command->getName());
        $this->assertSame('Rebuild Docker images, recreate containers, and run fresh', $command->getDescription());
    }

    public function test_it_fails_when_docker_is_not_running(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->dockerCompose->method('isDockerRunning')->willReturn(false);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('You must start Docker before running ngramx rebuild', $tester->getDisplay());
    }

    public function test_it_tears_down_rebuilds_and_runs_fresh(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, null);

        $this->overrideGenerator->expects($this->once())
            ->method('cleanup');

        $this->dockerCompose->expects($this->once())
            ->method('upWithBuild')
            ->with('docker-compose.yml', null);

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->with('fresh', $config)
            ->willReturn(1.5);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('rebuilt successfully', $tester->getDisplay());
    }

    public function test_it_uses_namespace_from_lock_file(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $lockData = new LockFileData(
            namespace: 'my-project',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00',
        );

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->lockFile->expects($this->once())
            ->method('read')
            ->willReturn($lockData);

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, 'my-project');

        $this->dockerCompose->expects($this->once())
            ->method('upWithBuild')
            ->with('docker-compose.yml', 'my-project');

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->willReturn(1.0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_warns_when_fresh_is_not_defined(): void
    {
        $config = $this->createMockConfig([]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())->method('down');
        $this->dockerCompose->expects($this->once())->method('upWithBuild');

        $this->commandOrchestrator->expects($this->never())->method('run');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('fresh', $tester->getDisplay());
        $this->assertStringContainsString('not defined', $tester->getDisplay());
    }

    public function test_it_skips_fresh_when_command_string_is_empty(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: '',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())->method('down');
        $this->dockerCompose->expects($this->once())->method('upWithBuild');

        $this->commandOrchestrator->expects($this->never())->method('run');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('not defined', $tester->getDisplay());
    }

    public function test_it_waits_for_services_when_configured(): void
    {
        $config = $this->createMockConfig(
            [
                'fresh' => new CommandDefinition(
                    command: 'php artisan migrate:fresh --seed',
                    description: 'Reset database',
                ),
            ],
            [new ServiceWaitConfig(service: 'db', timeout: 60)]
        );

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())->method('down');
        $this->dockerCompose->expects($this->once())->method('upWithBuild');

        // Readiness waiter polls health status (returns healthy immediately here).
        $this->healthChecker->expects($this->atLeastOnce())
            ->method('getHealthStatus')
            ->with('docker-compose.yml', 'db', null)
            ->willReturn('healthy');

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->willReturn(1.0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_handles_down_failure_gracefully(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->willThrowException(new \RuntimeException('No containers running'));

        $this->dockerCompose->expects($this->once())->method('upWithBuild');
        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Could not stop containers', $tester->getDisplay());
    }

    private function createCommand(): RebuildCommand
    {
        return new RebuildCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->healthChecker,
            $this->commandOrchestrator,
            $this->lockFile,
            $this->overrideGenerator,
        );
    }

    private function setupConfigLoader(NgramxConfig $config): void
    {
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);
    }

    /**
     * @param array<string, CommandDefinition> $commands
     * @param ServiceWaitConfig[] $waitFor
     */
    private function createMockConfig(array $commands, array $waitFor = []): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost:80',
                waitFor: $waitFor,
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: $commands,
        );
    }
}

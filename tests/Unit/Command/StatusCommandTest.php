<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\StatusCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\HealthChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatusCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private HealthChecker $healthChecker;
    private LockFile $lockFile;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->lockFile = $this->createMock(LockFile::class);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('status', $command->getName());
        $this->assertSame('Check the health status of services', $command->getDescription());
    }

    public function test_it_shows_no_services_running_message(): void
    {
        $config = $this->createMockConfig();

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())
            ->method('isRunning')
            ->with('docker-compose.yml', null)
            ->willReturn(false);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No services are currently running', $tester->getDisplay());
    }

    public function test_it_displays_instance_information_from_lock_file(): void
    {
        $config = $this->createMockConfig();

        $lockData = new LockFileData(
            namespace: 'ngramx-agent-1-project',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00'
        );

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(true);

        $this->lockFile->expects($this->exactly(2))
            ->method('read')
            ->willReturn($lockData);

        $this->dockerCompose->expects($this->once())
            ->method('isRunning')
            ->with('docker-compose.yml', 'ngramx-agent-1-project')
            ->willReturn(true);

        $this->dockerCompose->expects($this->once())
            ->method('ps')
            ->with('docker-compose.yml', 'ngramx-agent-1-project')
            ->willReturn([
                'app' => ['Service' => 'app', 'State' => 'running'],
            ]);

        $this->healthChecker->expects($this->once())
            ->method('getHealthStatus')
            ->with('docker-compose.yml', 'app', 'ngramx-agent-1-project')
            ->willReturn('healthy');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('ngramx-agent-1-project', $display);
        $this->assertStringContainsString('+1000', $display);
        $this->assertStringContainsString('2025-11-08', $display);
    }

    public function test_it_shows_service_status_table(): void
    {
        $config = $this->createMockConfig();

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())
            ->method('isRunning')
            ->with('docker-compose.yml', null)
            ->willReturn(true);

        $this->dockerCompose->expects($this->once())
            ->method('ps')
            ->with('docker-compose.yml', null)
            ->willReturn([
                'app' => ['Service' => 'app', 'State' => 'running'],
                'db' => ['Service' => 'db', 'State' => 'running'],
            ]);

        $this->healthChecker->expects($this->exactly(2))
            ->method('getHealthStatus')
            ->with(
                'docker-compose.yml',
                $this->logicalOr('app', 'db'),
                null
            )
            ->willReturnOnConsecutiveCalls('healthy', 'healthy');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Service', $display);
        $this->assertStringContainsString('Status', $display);
        $this->assertStringContainsString('Health', $display);
        $this->assertStringContainsString('app', $display);
        $this->assertStringContainsString('db', $display);
    }

    public function test_it_uses_default_mode_when_no_lock_file(): void
    {
        $config = $this->createMockConfig();

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())
            ->method('isRunning')
            ->with('docker-compose.yml', null)
            ->willReturn(false);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    private function createCommand(): StatusCommand
    {
        return new StatusCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->healthChecker,
            $this->lockFile
        );
    }

    private function createMockConfig(): NgramxConfig
    {
        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: 'http://localhost:80',
            waitFor: []
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: []
        );

        $n8nConfig = new N8nConfig(
            workflowsDir: './.n8n'
        );

        return new NgramxConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            n8n: $n8nConfig,
            commands: []
        );
    }
}

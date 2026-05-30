<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\ShellCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\ContainerExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ShellCommandTest extends TestCase
{
    public function testCommandIsConfiguredCorrectly(): void
    {
        $configLoader = $this->createMock(ConfigLoader::class);
        $containerExecutor = $this->createMock(ContainerExecutor::class);
        $lockFile = $this->createMock(LockFile::class);

        $command = new ShellCommand($configLoader, $containerExecutor, $lockFile);

        $this->assertSame('shell', $command->getName());
        $this->assertSame('Open an interactive bash shell in the primary service container', $command->getDescription());
    }

    public function testExecuteOpensShellInPrimaryService(): void
    {
        $configLoader = $this->createMock(ConfigLoader::class);
        $containerExecutor = $this->createMock(ContainerExecutor::class);
        $lockFile = $this->createMock(LockFile::class);

        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: 'http://localhost:8080',
            waitFor: []
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: []
        );

        $n8nConfig = new N8nConfig(
            workflowsDir: './.n8n'
        );

        $config = new NgramxConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            n8n: $n8nConfig,
            commands: []
        );

        $configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $configLoader->expects($this->once())
            ->method('load')
            ->with('/path/to/ngramx.yml')
            ->willReturn($config);

        $lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $purple = '\[\033[38;2;125;85;199m\]';
        $teal = '\[\033[38;2;46;217;195m\]';
        $reset = '\[\033[0m\]';
        $expectedPrompt = $purple . 'app' . $reset . ':' . $teal . '\w' . $reset . '\$ ';

        $containerExecutor->expects($this->once())
            ->method('execInteractiveWithEnv')
            ->with('docker-compose.yml', 'app', '/bin/bash', ['PS1' => $expectedPrompt], null)
            ->willReturn(0);

        $command = new ShellCommand($configLoader, $containerExecutor, $lockFile);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function testExecuteHandlesConfigException(): void
    {
        $configLoader = $this->createMock(ConfigLoader::class);
        $containerExecutor = $this->createMock(ContainerExecutor::class);
        $lockFile = $this->createMock(LockFile::class);

        $configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willThrowException(new \Ngramx\Config\Exception\ConfigException('Config not found'));

        $command = new ShellCommand($configLoader, $containerExecutor, $lockFile);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Configuration error: Config not found', $tester->getDisplay());
    }
}

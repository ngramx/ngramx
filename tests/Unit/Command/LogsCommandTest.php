<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\LogsCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\ContainerExecutor;
use Ngramx\Laravel\LaravelLogParser;
use Ngramx\Laravel\LaravelService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class LogsCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private ContainerExecutor $containerExecutor;
    private LockFile $lockFile;
    private LaravelService $laravelService;
    private LaravelLogParser $logParser;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->containerExecutor = $this->createMock(ContainerExecutor::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->laravelService = $this->createMock(LaravelService::class);
        $this->logParser = new LaravelLogParser();
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('logs', $command->getName());
        $this->assertSame('Tail or summarise Laravel application logs', $command->getDescription());

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('summary'));
        $this->assertTrue($definition->hasOption('lines'));
        $this->assertTrue($definition->hasOption('since'));
    }

    public function test_it_fails_when_log_file_not_found(): void
    {
        $config = $this->createMockConfig();
        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->laravelService->expects($this->once())
            ->method('resolveLogPath')
            ->willReturn(null);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not find a Laravel log file', $tester->getDisplay());
    }

    public function test_tail_mode_calls_exec_interactive(): void
    {
        $config = $this->createMockConfig();
        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->laravelService->expects($this->once())
            ->method('resolveLogPath')
            ->willReturn('/var/www/html/storage/logs/laravel.log');

        $this->containerExecutor->expects($this->once())
            ->method('execInteractive')
            ->with(
                'docker-compose.yml',
                'app',
                $this->stringContains('tail -n 50 -f'),
                null
            )
            ->willReturn(0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_tail_mode_respects_lines_option(): void
    {
        $config = $this->createMockConfig();
        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->laravelService->expects($this->once())
            ->method('resolveLogPath')
            ->willReturn('/var/www/html/storage/logs/laravel.log');

        $this->containerExecutor->expects($this->once())
            ->method('execInteractive')
            ->with(
                'docker-compose.yml',
                'app',
                $this->stringContains('tail -n 200 -f'),
                null
            )
            ->willReturn(0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--lines' => '200']);

        $this->assertSame(0, $exitCode);
    }

    public function test_summary_mode_shows_grouped_output(): void
    {
        $config = $this->createMockConfig();
        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->laravelService->expects($this->once())
            ->method('resolveLogPath')
            ->willReturn('/var/www/html/storage/logs/laravel.log');

        $logOutput = <<<'LOG'
[2024-01-15 10:30:00] production.ERROR: Connection refused
[2024-01-15 10:31:00] production.ERROR: Connection refused
[2024-01-15 10:32:00] production.WARNING: Slow query detected
[2024-01-15 10:33:00] production.INFO: User logged in
LOG;

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($logOutput);

        $this->containerExecutor->expects($this->once())
            ->method('exec')
            ->with(
                'docker-compose.yml',
                'app',
                $this->stringContains('tail -n 50'),
                30,
                null,
                null
            )
            ->willReturn($process);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--summary' => true]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Connection refused', $display);
        $this->assertStringContainsString('(x2)', $display);
        $this->assertStringContainsString('Slow query', $display);
        // INFO should be filtered out in summary mode
        $this->assertStringNotContainsString('User logged in', $display);
        $this->assertStringContainsString('3 total entries', $display);
        $this->assertStringContainsString('2 unique', $display);
    }

    public function test_summary_mode_with_no_errors_shows_message(): void
    {
        $config = $this->createMockConfig();
        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->laravelService->expects($this->once())
            ->method('resolveLogPath')
            ->willReturn('/var/www/html/storage/logs/laravel.log');

        $logOutput = '[2024-01-15 10:33:00] production.INFO: User logged in';

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($logOutput);

        $this->containerExecutor->expects($this->once())
            ->method('exec')
            ->willReturn($process);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--summary' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No errors or warnings found', $tester->getDisplay());
    }

    public function test_it_uses_namespace_from_lock_file(): void
    {
        $config = $this->createMockConfig();
        $this->setupConfigLoader($config);

        $lockData = new LockFileData(
            namespace: 'my-namespace',
            portOffset: null,
            startedAt: '2024-01-15T10:00:00+00:00'
        );

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->lockFile->expects($this->once())
            ->method('read')
            ->willReturn($lockData);

        $this->laravelService->expects($this->once())
            ->method('resolveLogPath')
            ->with('docker-compose.yml', 'app', 'my-namespace')
            ->willReturn('/var/www/html/storage/logs/laravel.log');

        $this->containerExecutor->expects($this->once())
            ->method('execInteractive')
            ->with(
                'docker-compose.yml',
                'app',
                $this->anything(),
                'my-namespace'
            )
            ->willReturn(0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_handles_config_exception(): void
    {
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willThrowException(new \Ngramx\Config\Exception\ConfigException('Config not found'));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Configuration error', $tester->getDisplay());
    }

    public function test_summary_mode_handles_failed_log_read(): void
    {
        $config = $this->createMockConfig();
        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->laravelService->expects($this->once())
            ->method('resolveLogPath')
            ->willReturn('/var/www/html/storage/logs/laravel.log');

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('Permission denied');

        $this->containerExecutor->expects($this->once())
            ->method('exec')
            ->willReturn($process);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--summary' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to read log file', $tester->getDisplay());
    }

    private function createCommand(): LogsCommand
    {
        return new LogsCommand(
            $this->configLoader,
            $this->containerExecutor,
            $this->lockFile,
            $this->laravelService,
            $this->logParser,
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

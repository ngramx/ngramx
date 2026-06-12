<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\DynamicCommand;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Orchestrator\CommandOrchestrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DynamicCommandTest extends TestCase
{
    private CommandOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(CommandOrchestrator::class);
    }

    public function test_empty_command_string_shows_error(): void
    {
        $commandDef = new CommandDefinition(
            command: '',
            description: 'Sync environment after switching branches',
        );

        $config = $this->createMockConfig(['clear' => $commandDef]);

        $command = new DynamicCommand('clear', $commandDef, $config, $this->orchestrator);
        $tester = new CommandTester($command);

        $this->orchestrator->expects($this->never())->method('run');

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("'clear' is not yet configured", $tester->getDisplay());
        $this->assertStringContainsString('ngramx.yml', $tester->getDisplay());
    }

    public function test_empty_command_shows_recommended_example(): void
    {
        $commandDef = new CommandDefinition(
            command: '',
            description: 'Sync environment after switching branches',
        );

        $config = $this->createMockConfig(['clear' => $commandDef]);

        $command = new DynamicCommand('clear', $commandDef, $config, $this->orchestrator);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('composer install', $display);
    }

    public function test_whitespace_only_command_string_shows_error(): void
    {
        $commandDef = new CommandDefinition(
            command: '   ',
            description: 'Sync environment',
        );

        $config = $this->createMockConfig(['clear' => $commandDef]);

        $command = new DynamicCommand('clear', $commandDef, $config, $this->orchestrator);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("'clear' is not yet configured", $tester->getDisplay());
    }

    public function test_non_recommended_empty_command_shows_generic_example(): void
    {
        $commandDef = new CommandDefinition(
            command: '',
            description: 'Run custom task',
        );

        $config = $this->createMockConfig(['custom' => $commandDef]);

        $command = new DynamicCommand('custom', $commandDef, $config, $this->orchestrator);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString("'custom' is not yet configured", $display);
        $this->assertStringContainsString('your-command-here', $display);
    }

    public function test_valid_command_executes_normally(): void
    {
        $commandDef = new CommandDefinition(
            command: 'php artisan test',
            description: 'Run tests',
        );

        $config = $this->createMockConfig(['test' => $commandDef]);

        $this->orchestrator->expects($this->once())
            ->method('run')
            ->with('test', $config, null)
            ->willReturn(2.5);

        $command = new DynamicCommand('test', $commandDef, $config, $this->orchestrator, $this->lockFileIn($this->emptyDir()));
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('completed successfully', $tester->getDisplay());
    }

    public function test_passes_worktree_namespace_from_lock_file_to_orchestrator(): void
    {
        $commandDef = new CommandDefinition(
            command: 'php artisan migrate:fresh',
            description: 'Reset the database',
        );

        $config = $this->createMockConfig(['clear' => $commandDef]);

        $dir = $this->emptyDir();
        $lockFile = $this->lockFileIn($dir);
        $lockFile->write(new LockFileData(
            namespace: 'ngramx-737-virginland',
            portOffset: null,
            startedAt: date('c'),
        ));

        $this->orchestrator->expects($this->once())
            ->method('run')
            ->with('clear', $config, 'ngramx-737-virginland')
            ->willReturn(1.0);

        $command = new DynamicCommand('clear', $commandDef, $config, $this->orchestrator, $lockFile);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    private function emptyDir(): string
    {
        $dir = sys_get_temp_dir() . '/ngramx-dyn-' . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function lockFileIn(string $dir): LockFile
    {
        return new LockFile($dir);
    }

    /**
     * @param array<string, CommandDefinition> $commands
     */
    private function createMockConfig(array $commands): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost:80',
                waitFor: [],
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: $commands,
        );
    }
}

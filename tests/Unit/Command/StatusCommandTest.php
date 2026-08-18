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
use Ngramx\Git\GitRepositoryService;
use Ngramx\Worktree\WorktreeInventory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatusCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private HealthChecker $healthChecker;
    private LockFile $lockFile;
    private GitRepositoryService $gitRepositoryService;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);

        $this->tmpDir = sys_get_temp_dir() . '/ngramx-status-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('status', $command->getName());
        $this->assertStringContainsString('project overview', $command->getDescription());
        $this->assertTrue($command->getDefinition()->hasOption('services'));
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
        $exitCode = $tester->execute(['--services' => true]);

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
        $exitCode = $tester->execute(['--services' => true]);

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
        $exitCode = $tester->execute(['--services' => true]);

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
        $exitCode = $tester->execute(['--services' => true]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_shows_the_project_overview_with_every_worktree(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/gig-1-foo', 0755, true);
        mkdir($worktreesDir . '/gig-2-bar', 0755, true);

        // gig-2 has a lock and live containers; gig-1 was never started.
        file_put_contents($worktreesDir . '/gig-2-bar/.ngramx.lock', json_encode([
            'namespace' => 'ngramx-gig-2-bar',
            'port_offset' => 1000,
            'started_at' => '2026-01-01T00:00:00+00:00',
        ]));

        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn($this->tmpDir . '/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($this->createMockConfig());

        $this->gitRepositoryService->expects($this->any())
            ->method('getCurrentBranch')
            ->willReturn('main');
        $this->gitRepositoryService->expects($this->any())
            ->method('listWorktreeBranches')
            ->willReturn([
                $worktreesDir . '/gig-1-foo' => 'gig-1-fix-thing',
                $worktreesDir . '/gig-2-bar' => 'gig-2-another-thing',
            ]);

        $this->dockerCompose->expects($this->any())
            ->method('isServiceRunning')
            ->willReturnCallback(
                static fn (string $composeFile, string $service, ?string $project): bool
                    => $project === 'ngramx-gig-2-bar'
            );

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString($this->tmpDir, $display);
        $this->assertStringContainsString('main', $display);
        $this->assertStringContainsString('Worktrees (2)', $display);
        $this->assertStringContainsString('gig-1-foo', $display);
        $this->assertStringContainsString('gig-1-fix-thing', $display);
        $this->assertStringContainsString('gig-2-bar', $display);
        $this->assertStringContainsString('running', $display);
        $this->assertStringContainsString('stopped', $display);
        // The running worktree's URL carries its port offset.
        $this->assertStringContainsString(':1080', $display);
    }

    public function test_it_reports_on_the_whole_repository_when_run_inside_a_worktree(): void
    {
        $worktreePath = $this->tmpDir . '/.ngramx/worktrees/gig-1-foo';
        mkdir($worktreePath, 0755, true);
        file_put_contents($this->tmpDir . '/ngramx.yml', "version: '1.0'\n");

        // findConfigFile() resolves to the worktree's own config when the
        // command is run from inside it.
        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn($worktreePath . '/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($this->createMockConfig());

        $this->gitRepositoryService->expects($this->any())->method('getCurrentBranch')->willReturn('main');
        $this->gitRepositoryService->expects($this->any())
            ->method('listWorktreeBranches')
            ->willReturn([$worktreePath => 'gig-1-fix-thing']);
        $this->dockerCompose->expects($this->any())->method('isServiceRunning')->willReturn(false);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);
        // The repository root, not the worktree, is reported as the project.
        $this->assertStringContainsString('path', $display);
        $this->assertStringContainsString($this->tmpDir, $display);
        $this->assertStringNotContainsString($worktreePath, $display);
        $this->assertStringContainsString('Worktrees (1)', $display);
        $this->assertStringContainsString('gig-1-foo', $display);
        // The row for the worktree you are standing in is marked.
        $this->assertStringContainsString('❯', $display);
    }

    public function test_it_says_when_there_are_no_worktrees(): void
    {
        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn($this->tmpDir . '/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($this->createMockConfig());
        $this->gitRepositoryService->expects($this->any())->method('getCurrentBranch')->willReturn('main');
        $this->dockerCompose->expects($this->any())->method('isServiceRunning')->willReturn(false);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString('None yet', $display);
        $this->assertStringContainsString('ngramx worktree <ticket>', $display);
    }

    private function createCommand(): StatusCommand
    {
        return new StatusCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->healthChecker,
            $this->lockFile,
            new WorktreeInventory($this->dockerCompose, $this->gitRepositoryService)
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

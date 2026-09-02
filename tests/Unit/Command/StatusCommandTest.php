<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use GuzzleHttp\Psr7\Response;
use Ngramx\Codabyte\CloudRunsClient;
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

        // Read once and passed down to the header, rather than re-read for it.
        $this->lockFile->expects($this->once())
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

    public function test_it_emits_the_overview_as_json(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/cor-301-ngramx', 0755, true);

        file_put_contents($worktreesDir . '/cor-301-ngramx/.ngramx.lock', json_encode([
            'namespace' => 'ngramx-cor-301-ngramx',
            'port_offset' => 1000,
            'started_at' => '2026-01-01T00:00:00+00:00',
        ]));

        file_put_contents($worktreesDir . '/cor-301-ngramx/.ngramx-agent.json', json_encode([
            'source' => 'codabyte',
            'runId' => 'run-abc',
            'issue' => 'COR-301',
            'startedAt' => '2026-01-01T00:05:00+00:00',
        ]));

        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn($this->tmpDir . '/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($this->createMockConfig());
        $this->gitRepositoryService->expects($this->any())->method('getCurrentBranch')->willReturn('main');
        $this->gitRepositoryService->expects($this->any())
            ->method('listWorktreeBranches')
            ->willReturn([$worktreesDir . '/cor-301-ngramx' => 'cor-301']);
        $this->dockerCompose->expects($this->any())->method('isServiceRunning')->willReturn(false);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--json' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);

        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['schema']);
        $this->assertSame($this->tmpDir, $payload['repository']['path']);
        $this->assertSame('main', $payload['project']['branch']);
        $this->assertCount(1, $payload['worktrees']);

        $worktree = $payload['worktrees'][0];
        $this->assertSame('cor-301-ngramx', $worktree['name']);
        $this->assertSame('cor-301', $worktree['branch']);
        $this->assertFalse($worktree['running']);
        $this->assertSame('ngramx-cor-301-ngramx', $worktree['namespace']);
        $this->assertSame(1000, $worktree['portOffset']);
        $this->assertSame('codabyte', $worktree['agent']['source']);
        $this->assertSame('COR-301', $worktree['agent']['issue']);
    }

    /**
     * The overview must not claim an agent is alive on the strength of a file.
     * A marker with no recorded ending is reported as "started" — the last
     * thing actually witnessed — never "running".
     */
    public function test_json_reports_an_unfinished_agent_run_as_started_not_running(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/cor-301-ngramx', 0755, true);
        file_put_contents(
            $worktreesDir . '/cor-301-ngramx/.ngramx-agent.json',
            json_encode(['source' => 'codabyte', 'startedAt' => '2026-01-01T00:05:00+00:00'])
        );

        $this->stubOverviewCollaborators();

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('started', $payload['worktrees'][0]['agent']['state']);
        $this->assertNull($payload['worktrees'][0]['agent']['endedAt']);
        $this->assertNull($payload['worktrees'][0]['agent']['outcome']);
    }

    public function test_json_reports_a_finished_agent_run_with_its_outcome(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/cor-301-ngramx', 0755, true);
        file_put_contents($worktreesDir . '/cor-301-ngramx/.ngramx-agent.json', json_encode([
            'source' => 'codabyte',
            'startedAt' => '2026-01-01T00:05:00+00:00',
            'endedAt' => '2026-01-01T00:25:00+00:00',
            'outcome' => 'succeeded',
            'prUrl' => 'https://github.com/ngramx/ngramx/pull/12',
        ]));

        $this->stubOverviewCollaborators();

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('succeeded', $payload['worktrees'][0]['agent']['state']);
        $this->assertSame('https://github.com/ngramx/ngramx/pull/12', $payload['worktrees'][0]['agent']['prUrl']);
    }

    public function test_json_omits_the_agent_when_no_marker_file_exists(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-ngramx', 0755, true);

        $this->stubOverviewCollaborators();

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($payload['worktrees'][0]['agent']);
    }

    /**
     * A half-written or corrupt marker file must cost us the agent column, not
     * the whole command.
     */
    public function test_a_corrupt_marker_file_does_not_break_the_overview(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/cor-301-ngramx', 0755, true);
        file_put_contents($worktreesDir . '/cor-301-ngramx/.ngramx-agent.json', '{"source": "coda');

        $this->stubOverviewCollaborators();

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--json' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);

        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($payload['worktrees'][0]['agent']);
    }

    public function test_json_errors_are_emitted_as_json(): void
    {
        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willThrowException(new \RuntimeException('no ngramx.yml found'));

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('no ngramx.yml found', $payload['error']);
    }

    public function test_it_emits_service_health_as_json(): void
    {
        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($this->createMockConfig());

        $this->lockFile->expects($this->any())->method('exists')->willReturn(true);
        $this->lockFile->expects($this->any())->method('read')->willReturn(new LockFileData(
            namespace: 'ngramx-cor-301-ngramx',
            portOffset: 1000,
            startedAt: '2026-01-01T00:00:00+00:00'
        ));

        $this->dockerCompose->expects($this->any())->method('isRunning')->willReturn(true);
        $this->dockerCompose->expects($this->any())->method('ps')->willReturn([
            'app' => ['Service' => 'app', 'State' => 'running'],
        ]);
        $this->healthChecker->expects($this->any())->method('getHealthStatus')->willReturn('healthy');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--services' => true, '--json' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);

        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['running']);
        $this->assertSame('ngramx-cor-301-ngramx', $payload['namespace']);
        $this->assertSame(1000, $payload['portOffset']);
        $this->assertSame(
            [['name' => 'app', 'state' => 'running', 'health' => 'healthy']],
            $payload['services']
        );
    }

    public function test_service_health_json_is_valid_when_nothing_runs(): void
    {
        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($this->createMockConfig());
        $this->lockFile->expects($this->any())->method('exists')->willReturn(false);
        $this->dockerCompose->expects($this->any())->method('isRunning')->willReturn(false);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--services' => true, '--json' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);

        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['running']);
        $this->assertSame([], $payload['services']);
    }

    public function test_the_human_overview_shows_an_agent_column_only_when_a_run_exists(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/cor-301-ngramx', 0755, true);

        $this->stubOverviewCollaborators();

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);
        $this->assertStringNotContainsString('agent', $tester->getDisplay());

        file_put_contents(
            $worktreesDir . '/cor-301-ngramx/.ngramx-agent.json',
            json_encode(['source' => 'codabyte', 'outcome' => 'failed'])
        );

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('agent', $display);
        $this->assertStringContainsString('failed', $display);
    }

    public function test_it_shows_cloud_runs_from_the_codabyte_server(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();
        $this->gitRepositoryService->expects($this->any())
            ->method('getRemoteUrl')
            ->willReturn('git@github.com:gigabyte-software/cortex.git');

        $tester = new CommandTester($this->createCommand($this->cloudClientReturning([
            [
                'name' => 'cor-999-cortex',
                'branch' => 'cor-999',
                'running' => true,
                'url' => 'https://cor-999.localhost:8443',
                'agentState' => 'running',
                'agent' => ['issue' => 'COR-999'],
            ],
        ])));
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString('Cloud (Codabyte)', $display);
        $this->assertStringContainsString('cor-999-cortex', $display);
        $this->assertStringContainsString('cor-999', $display);
        $this->assertStringContainsString('running', $display);
    }

    /**
     * Most people are not running a cloud agent. They should see no trace of
     * one — not an empty section, and not a delay while we ask.
     */
    public function test_it_says_nothing_about_the_cloud_when_not_configured(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();

        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);

        $this->assertStringNotContainsString('Cloud', $tester->getDisplay());
    }

    public function test_no_cloud_skips_the_lookup(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();

        $called = false;
        $client = new CloudRunsClient(
            function () use (&$called): Response {
                $called = true;
                return new Response(200, [], '{"repositories":[]}');
            },
            [CloudRunsClient::ENV_API_KEY => 'test-key']
        );

        $tester = new CommandTester($this->createCommand($client));
        $tester->execute(['--no-cloud' => true]);

        $this->assertFalse($called);
        $this->assertStringNotContainsString('Cloud', $tester->getDisplay());
    }

    /**
     * An unreachable server must not read as "nothing running there", which is
     * exactly what we failed to establish.
     */
    public function test_an_unreachable_server_is_reported_without_failing_status(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();
        $this->gitRepositoryService->expects($this->any())
            ->method('getRemoteUrl')
            ->willReturn('git@github.com:gigabyte-software/cortex.git');

        $client = new CloudRunsClient(
            fn (): Response => new Response(500),
            [CloudRunsClient::ENV_API_KEY => 'test-key']
        );

        $tester = new CommandTester($this->createCommand($client));
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        // The local overview is the point of the command; a remote failure must
        // not take it down with it.
        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString('cor-301-cortex', $display);
        $this->assertStringContainsString('unavailable', $display);
        $this->assertStringContainsString('HTTP 500', $display);
    }

    public function test_a_repository_the_server_does_not_have_shows_as_none(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();
        $this->gitRepositoryService->expects($this->any())
            ->method('getRemoteUrl')
            ->willReturn('git@github.com:gigabyte-software/cortex.git');

        $tester = new CommandTester($this->createCommand($this->cloudClientReturning([])));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Cloud (Codabyte)', $display);
        $this->assertStringContainsString('No environments running on the server', $display);
    }

    /**
     * Without an origin remote there is no name the server would know this
     * repository by, so there is nothing to ask about.
     */
    public function test_a_repository_with_no_remote_skips_the_lookup(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();
        $this->gitRepositoryService->expects($this->any())->method('getRemoteUrl')->willReturn(null);

        $called = false;
        $client = new CloudRunsClient(
            function () use (&$called): Response {
                $called = true;
                return new Response(200, [], '{"repositories":[]}');
            },
            [CloudRunsClient::ENV_API_KEY => 'test-key']
        );

        $tester = new CommandTester($this->createCommand($client));
        $tester->execute([]);

        $this->assertFalse($called);
        $this->assertStringNotContainsString('Cloud', $tester->getDisplay());
    }

    public function test_json_includes_cloud_runs(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();
        $this->gitRepositoryService->expects($this->any())
            ->method('getRemoteUrl')
            ->willReturn('git@github.com:gigabyte-software/cortex.git');

        $tester = new CommandTester($this->createCommand($this->cloudClientReturning([
            ['name' => 'cor-999-cortex', 'branch' => 'cor-999', 'running' => true, 'agentState' => 'running'],
        ])));
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($payload['cloud']['error']);
        $this->assertCount(1, $payload['cloud']['runs']);
        $this->assertSame('cor-999-cortex', $payload['cloud']['runs'][0]['name']);
        $this->assertSame('running', $payload['cloud']['runs'][0]['agentState']);
    }

    /**
     * The key is absent rather than empty, so a consumer can tell "nothing
     * running there" from "we never asked".
     */
    public function test_json_omits_the_cloud_key_when_not_configured(): void
    {
        mkdir($this->tmpDir . '/.ngramx/worktrees/cor-301-cortex', 0755, true);
        $this->stubOverviewCollaborators();

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('cloud', $payload);
    }

    /**
     * The permissive collaborator stubs the overview tests share: one worktree
     * on a branch, nothing running.
     */
    private function stubOverviewCollaborators(): void
    {
        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn($this->tmpDir . '/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($this->createMockConfig());
        $this->gitRepositoryService->expects($this->any())->method('getCurrentBranch')->willReturn('main');
        $this->gitRepositoryService->expects($this->any())
            ->method('listWorktreeBranches')
            ->willReturn([$this->tmpDir . '/.ngramx/worktrees/cor-301-ngramx' => 'cor-301']);
        $this->dockerCompose->expects($this->any())->method('isServiceRunning')->willReturn(false);
    }

    /**
     * @param ?CloudRunsClient $cloudRunsClient Defaults to one that is not
     *        configured, so these tests never depend on whether the machine
     *        running them happens to have Codabyte credentials in its
     *        environment — and never make a real network call.
     */
    private function createCommand(?CloudRunsClient $cloudRunsClient = null): StatusCommand
    {
        return new StatusCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->healthChecker,
            $this->lockFile,
            new WorktreeInventory($this->dockerCompose, $this->gitRepositoryService),
            null,
            $cloudRunsClient ?? new CloudRunsClient(null, []),
            $this->gitRepositoryService
        );
    }

    /**
     * A configured client whose transport returns a canned response.
     *
     * @param array<mixed> $worktrees
     */
    private function cloudClientReturning(array $worktrees, ?string $repositoryError = null): CloudRunsClient
    {
        return new CloudRunsClient(
            fn (): Response => new Response(200, [], (string) json_encode([
                'schema' => 1,
                'repositories' => [[
                    'name' => 'cortex',
                    'error' => $repositoryError,
                    'worktrees' => $worktrees,
                ]],
            ])),
            [CloudRunsClient::ENV_API_KEY => 'test-key']
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

<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\WorktreeCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\DockerCompose;
use Ngramx\Git\GitRepositoryService;
use Ngramx\Laravel\LaravelService;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Worktree\OwnershipReconcileResult;
use Ngramx\Worktree\WorktreeDependencyPrimer;
use Ngramx\Worktree\WorktreeIdentity;
use Ngramx\Worktree\WorktreeOwnershipReconciler;
use Ngramx\Worktree\WorktreeUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class WorktreeCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private LockFile $lockFile;
    private GitRepositoryService $gitRepositoryService;
    private LaravelService $laravelService;
    private CommandOrchestrator $commandOrchestrator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->laravelService = $this->createMock(LaravelService::class);
        $this->commandOrchestrator = $this->createMock(CommandOrchestrator::class);

        $this->tmpDir = sys_get_temp_dir() . '/ngramx-worktree-cmd-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // A worktree environment that is "already running" skips the in-process
        // `up`, which a bare CommandTester cannot execute anyway.
        $this->dockerCompose->expects($this->any())->method('isRunning')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('fetchFromOrigin')->willReturn(true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();
        $definition = $command->getDefinition();

        $this->assertSame('worktree', $command->getName());
        $this->assertNotEmpty($command->getDescription());
        $this->assertTrue($definition->hasArgument('ticket'));
        $this->assertFalse($definition->getArgument('ticket')->isRequired());
        $this->assertTrue($definition->hasOption('quick'));
        $this->assertTrue($definition->hasOption('cursor'));
        $this->assertSame('c', $definition->getOption('cursor')->getShortcut());
        $this->assertFalse($definition->hasOption('worktree'));
        $this->assertTrue($definition->hasOption('cleanup'));
    }

    public function test_it_warns_when_no_ticket_on_integration_branch(): void
    {
        $this->setupConfigLoader($this->createMockConfig());

        $this->gitRepositoryService->expects($this->once())
            ->method('getCurrentBranch')
            ->with($this->tmpDir)
            ->willReturn('main');
        $this->gitRepositoryService->expects($this->once())
            ->method('isIntegrationBranch')
            ->with('main')
            ->willReturn(true);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("You're on the main branch", $tester->getDisplay());
        $this->assertStringContainsString('switch to a feature branch first', $tester->getDisplay());
    }

    public function test_it_moves_the_current_feature_branch_into_a_worktree(): void
    {
        $this->setupConfigLoader($this->createMockConfig());
        $this->preCreateWorktreeDirectory('gig-123');

        $this->gitRepositoryService->expects($this->once())
            ->method('getCurrentBranch')
            ->with($this->tmpDir)
            ->willReturn('gig-123-fix-thing');
        $this->gitRepositoryService->expects($this->once())
            ->method('isIntegrationBranch')
            ->with('gig-123-fix-thing')
            ->willReturn(false);
        $this->gitRepositoryService->expects($this->once())
            ->method('hasUncommittedChanges')
            ->with($this->tmpDir)
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->once())
            ->method('stashPush')
            ->with($this->tmpDir, 'ngramx worktree: gig-123-fix-thing')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->once())
            ->method('resolveDefaultIntegrationBranch')
            ->with($this->tmpDir)
            ->willReturn('main');
        $this->gitRepositoryService->expects($this->once())
            ->method('checkoutLocalBranch')
            ->with($this->tmpDir, 'main')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(false);
        $this->gitRepositoryService->expects($this->once())
            ->method('addWorktree')
            ->with($this->tmpDir, $this->anything(), 'gig-123-fix-thing')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->once())
            ->method('stashPop')
            ->willReturn(true);

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('Preparing worktree for current branch: gig-123-fix-thing', $tester->getDisplay());
        $this->assertStringContainsString('Stashing uncommitted changes', $tester->getDisplay());
        $this->assertStringContainsString('Switching main checkout to main', $tester->getDisplay());
        $this->assertStringContainsString('Restoring stashed changes into the worktree', $tester->getDisplay());
    }

    public function test_it_skips_stash_when_worktree_from_current_branch_is_clean(): void
    {
        $this->setupConfigLoader($this->createMockConfig());
        $this->preCreateWorktreeDirectory('gig-456');

        $this->gitRepositoryService->expects($this->once())
            ->method('getCurrentBranch')
            ->willReturn('gig-456-feature');
        $this->gitRepositoryService->expects($this->once())
            ->method('isIntegrationBranch')
            ->willReturn(false);
        $this->gitRepositoryService->expects($this->once())
            ->method('hasUncommittedChanges')
            ->willReturn(false);
        $this->gitRepositoryService->expects($this->never())->method('stashPush');
        $this->gitRepositoryService->expects($this->once())
            ->method('resolveDefaultIntegrationBranch')
            ->willReturn('main');
        $this->gitRepositoryService->expects($this->once())
            ->method('checkoutLocalBranch')
            ->with($this->tmpDir, 'main')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(false);
        $this->gitRepositoryService->expects($this->once())
            ->method('addWorktree')
            ->with($this->anything(), $this->anything(), 'gig-456-feature')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->never())->method('stashPop');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
    }

    public function test_cleanup_without_ticket_removes_all_worktrees(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/gig-1-foo', 0755, true);
        mkdir($worktreesDir . '/gig-2-bar', 0755, true);

        $this->setupConfigLoader($this->createMockConfig());

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--cleanup' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Cleaning up all worktrees (2)', $display);
        $this->assertStringContainsString('Removed all worktrees', $display);
        $this->assertDirectoryDoesNotExist($worktreesDir . '/gig-1-foo');
        $this->assertDirectoryDoesNotExist($worktreesDir . '/gig-2-bar');
    }

    public function test_cleanup_without_ticket_when_no_worktrees(): void
    {
        $this->setupConfigLoader($this->createMockConfig());

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--cleanup' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('nothing to clean up', $tester->getDisplay());
    }

    public function test_cleanup_with_ticket_removes_only_matching_worktree(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/gig-1-foo', 0755, true);
        mkdir($worktreesDir . '/gig-2-bar', 0755, true);

        $this->setupConfigLoader($this->createMockConfig());

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'gig-1', '--cleanup' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Removed worktree for ticket gig-1', $display);
        $this->assertDirectoryDoesNotExist($worktreesDir . '/gig-1-foo');
        $this->assertDirectoryExists($worktreesDir . '/gig-2-bar');
    }

    public function test_cleanup_with_bare_number_normalizes_ticket_before_matching(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/cor-55-foo', 0755, true);
        mkdir($worktreesDir . '/gig-2-bar', 0755, true);

        $this->setupConfigLoader($this->createMockConfig(defaultTeam: 'cor'));

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => '55', '--cleanup' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString('Removed worktree for ticket cor-55', $display);
        $this->assertDirectoryDoesNotExist($worktreesDir . '/cor-55-foo');
        $this->assertDirectoryExists($worktreesDir . '/gig-2-bar');
    }

    public function test_it_creates_a_new_branch_when_nothing_matches(): void
    {
        $this->setupConfigLoader($this->createMockConfig());
        $this->preCreateWorktreeDirectory('gig-123');

        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn([]);
        $this->gitRepositoryService->expects($this->any())->method('localBranchExists')->willReturn(false);
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(false);

        $this->gitRepositoryService->expects($this->once())
            ->method('addWorktreeWithNewBranch')
            ->with($this->tmpDir, $this->anything(), 'gig-123')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->never())->method('addWorktree');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'gig-123']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString("new branch 'gig-123' will be created", $tester->getDisplay());
    }

    public function test_it_prefixes_bare_numbers_with_the_configured_default_team(): void
    {
        $this->setupConfigLoader($this->createMockConfig(defaultTeam: 'cor'));
        $this->preCreateWorktreeDirectory('cor-55');

        $searched = [];
        $this->gitRepositoryService->expects($this->any())
            ->method('findBranchesContaining')
            ->willReturnCallback(function (string $path, string $needle) use (&$searched): array {
                $searched[] = $needle;
                return [];
            });
        $this->gitRepositoryService->expects($this->any())->method('localBranchExists')->willReturn(false);
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(false);

        $this->gitRepositoryService->expects($this->once())
            ->method('addWorktreeWithNewBranch')
            ->with($this->anything(), $this->anything(), 'cor-55')
            ->willReturn(true);

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => '55']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        // The canonical slug is searched first, the bare number as a fallback.
        $this->assertContains('cor-55', $searched);
        $this->assertContains('55', $searched);
    }

    public function test_it_uses_an_existing_remote_branch_instead_of_creating_one(): void
    {
        $this->setupConfigLoader($this->createMockConfig());
        $this->preCreateWorktreeDirectory('gig-123');

        $this->gitRepositoryService->expects($this->any())
            ->method('findBranchesContaining')
            ->willReturn(['gig-123-fix-thing']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('gig-123-fix-thing');
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(false);

        $this->gitRepositoryService->expects($this->once())
            ->method('addWorktree')
            ->with($this->tmpDir, $this->anything(), 'gig-123-fix-thing')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->never())->method('addWorktreeWithNewBranch');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
    }

    public function test_it_falls_back_to_the_hyphenless_spelling_when_searching(): void
    {
        $this->setupConfigLoader($this->createMockConfig());
        $this->preCreateWorktreeDirectory('gig-123');

        $this->gitRepositoryService->expects($this->any())
            ->method('findBranchesContaining')
            ->willReturnCallback(fn (string $path, string $needle): array => $needle === 'gig123' ? ['gig123-old-style'] : []);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('gig123-old-style');
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(false);

        $this->gitRepositoryService->expects($this->once())
            ->method('addWorktree')
            ->with($this->anything(), $this->anything(), 'gig123-old-style')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->never())->method('addWorktreeWithNewBranch');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'gig-123']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
    }

    public function test_it_reuses_a_local_branch_when_no_remote_matches(): void
    {
        $this->setupConfigLoader($this->createMockConfig());
        $this->preCreateWorktreeDirectory('gig-123');

        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn([]);
        $this->gitRepositoryService->expects($this->any())->method('localBranchExists')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(false);

        $this->gitRepositoryService->expects($this->once())
            ->method('addWorktree')
            ->with($this->anything(), $this->anything(), 'gig-123')
            ->willReturn(true);
        $this->gitRepositoryService->expects($this->never())->method('addWorktreeWithNewBranch');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'gig-123']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString("reusing the local branch 'gig-123'", $tester->getDisplay());
    }

    public function test_it_reuses_an_existing_worktree(): void
    {
        $this->setupConfigLoader($this->createMockConfig());
        $this->preCreateWorktreeDirectory('gig-123');

        $this->gitRepositoryService->expects($this->any())
            ->method('findBranchesContaining')
            ->willReturn(['gig-123-fix-thing']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('gig-123-fix-thing');
        $this->gitRepositoryService->expects($this->any())->method('worktreeExists')->willReturn(true);

        $this->gitRepositoryService->expects($this->never())->method('addWorktree');
        $this->gitRepositoryService->expects($this->never())->method('addWorktreeWithNewBranch');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'gig-123']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('Reusing existing worktree', $tester->getDisplay());
    }

    public function test_it_fails_when_fetch_fails(): void
    {
        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->gitRepositoryService->expects($this->once())->method('fetchFromOrigin')->willReturn(false);

        $this->setupConfigLoader($this->createMockConfig());

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'gig-123']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to fetch from origin', $tester->getDisplay());
    }

    /**
     * Pre-create the worktree directory the command will derive, so the chdir
     * into it succeeds while addWorktree* stays mocked.
     */
    private function preCreateWorktreeDirectory(string $ticketSlug): void
    {
        $repoName = WorktreeIdentity::sanitizeSegment(basename($this->tmpDir));
        $folderName = WorktreeIdentity::folderName($ticketSlug, $repoName);
        mkdir($this->tmpDir . '/.ngramx/worktrees/' . $folderName, 0755, true);
    }

    private function createCommand(): WorktreeCommand
    {
        $urlResolver = $this->createMock(WorktreeUrlResolver::class);
        $urlResolver->expects($this->any())->method('resolve')->willReturn('http://localhost:80');

        $reconciler = $this->createMock(WorktreeOwnershipReconciler::class);
        $reconciler->expects($this->any())
            ->method('reconcile')
            ->willReturn(OwnershipReconcileResult::skipped('unit test'));

        $primer = $this->createMock(WorktreeDependencyPrimer::class);

        return new WorktreeCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->lockFile,
            $this->gitRepositoryService,
            $this->laravelService,
            $this->commandOrchestrator,
            ownershipReconciler: $reconciler,
            worktreeUrlResolver: $urlResolver,
            dependencyPrimer: $primer,
        );
    }

    private function setupConfigLoader(NgramxConfig $config): void
    {
        $this->configLoader->expects($this->any())
            ->method('findConfigFile')
            ->willReturn($this->tmpDir . '/ngramx.yml');

        $this->configLoader->expects($this->any())
            ->method('load')
            ->willReturn($config);
    }

    private function createMockConfig(string $defaultTeam = 'gig'): NgramxConfig
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
            commands: [
                'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
            ],
            defaultTeam: $defaultTeam,
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

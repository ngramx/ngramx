<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Git\GitExcludeManager;
use Ngramx\Git\GitRepositoryService;
use Ngramx\Worktree\ClaudeWorktreeHookHandler;
use Ngramx\Worktree\WorktreeIdentity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClaudeWorktreeHookHandlerTest extends TestCase
{
    private string $repoPath;

    /** @var GitRepositoryService&MockObject */
    private GitRepositoryService $git;

    /** @var GitExcludeManager&MockObject */
    private GitExcludeManager $exclude;

    private ClaudeWorktreeHookHandler $handler;

    protected function setUp(): void
    {
        $this->repoPath = sys_get_temp_dir() . '/ngramx-claude-hook-' . uniqid('', true);
        mkdir($this->repoPath);
        mkdir($this->repoPath . '/.git');

        $this->git = $this->createMock(GitRepositoryService::class);
        $this->exclude = $this->createMock(GitExcludeManager::class);
        $this->handler = $this->handlerForRepo($this->repoPath);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->repoPath);
    }

    public function test_create_rejects_missing_name(): void
    {
        $result = $this->handler->create(json_encode(['cwd' => $this->repoPath], JSON_THROW_ON_ERROR));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('.name', $result['error']);
    }

    public function test_create_rejects_invalid_json(): void
    {
        $result = $this->handler->create('{not-json');

        $this->assertFalse($result['ok']);
        $this->assertSame('Invalid JSON hook input', $result['error']);
    }

    public function test_create_ticket_slug_uses_ngramx_folder_and_new_branch(): void
    {
        $folder = 'gig-4500-' . WorktreeIdentity::sanitizeSegment(basename($this->repoPath));
        $expected = $this->repoPath . '/.ngramx/worktrees/' . $folder;

        $this->git->expects($this->once())->method('localBranchExists')->with($this->repoPath, 'gig-4500')->willReturn(false);
        $this->git->expects($this->once())
            ->method('addWorktreeWithNewBranch')
            ->with($this->repoPath, $expected, 'gig-4500')
            ->willReturn(true);
        $this->exclude->expects($this->once())
            ->method('ensureExcluded')
            ->with($this->repoPath, '/.ngramx/worktrees/');

        $result = $this->handler->create(json_encode([
            'name' => 'GIG-4500-hook-demo',
            'cwd' => $this->repoPath,
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame($expected, $result['path']);
    }

    public function test_create_non_ticket_name_uses_sanitized_folder(): void
    {
        $expected = $this->repoPath . '/.ngramx/worktrees/bold-oak';

        $this->git->expects($this->once())->method('localBranchExists')->with($this->repoPath, 'worktree-bold-oak')->willReturn(false);
        $this->git->expects($this->once())
            ->method('addWorktreeWithNewBranch')
            ->with($this->repoPath, $expected, 'worktree-bold-oak')
            ->willReturn(true);

        $result = $this->handler->create(json_encode([
            'name' => 'Bold Oak',
            'cwd' => $this->repoPath,
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame($expected, $result['path']);
    }

    public function test_create_reuses_existing_local_branch(): void
    {
        $folder = 'gig-9-' . WorktreeIdentity::sanitizeSegment(basename($this->repoPath));
        $expected = $this->repoPath . '/.ngramx/worktrees/' . $folder;

        $this->git->expects($this->once())->method('localBranchExists')->with($this->repoPath, 'gig-9')->willReturn(true);
        $this->git->expects($this->once())
            ->method('addWorktree')
            ->with($this->repoPath, $expected, 'gig-9')
            ->willReturn(true);
        $this->git->expects($this->never())->method('addWorktreeWithNewBranch');

        $result = $this->handler->create(json_encode([
            'name' => 'gig-9',
            'cwd' => $this->repoPath,
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($result['ok']);
        $this->assertSame($expected, $result['path']);
    }

    public function test_create_returns_existing_path_without_recreating(): void
    {
        $folder = $this->repoPath . '/.ngramx/worktrees/gig-1-'
            . WorktreeIdentity::sanitizeSegment(basename($this->repoPath));
        mkdir($folder, 0755, true);

        $this->git->expects($this->never())->method('addWorktree');
        $this->git->expects($this->never())->method('addWorktreeWithNewBranch');

        $result = $this->handler->create(json_encode([
            'name' => 'gig-1',
            'cwd' => $this->repoPath,
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($result['ok']);
        $this->assertSame($folder, $result['path']);
    }

    public function test_remove_rejects_non_managed_path(): void
    {
        $result = $this->handler->remove(json_encode([
            'worktree_path' => $this->repoPath . '/.claude/worktrees/x',
        ], JSON_THROW_ON_ERROR));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('.ngramx/worktrees/', $result['error']);
    }

    public function test_remove_prunes_when_directory_already_gone(): void
    {
        $path = $this->repoPath . '/.ngramx/worktrees/gone';

        $this->git->expects($this->once())->method('pruneWorktrees')->with($this->repoPath);
        $this->git->expects($this->never())->method('removeWorktree');

        $result = $this->handler->remove(json_encode([
            'worktree_path' => $path,
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($result['ok']);
        $this->assertSame($path, $result['path']);
    }

    public function test_remove_calls_git_remove_worktree(): void
    {
        $path = $this->repoPath . '/.ngramx/worktrees/live';
        mkdir($path, 0755, true);

        $this->git->expects($this->once())
            ->method('removeWorktree')
            ->with($this->repoPath, $path)
            ->willReturn(true);

        $result = $this->handler->remove(json_encode([
            'worktree_path' => $path,
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($result['ok']);
    }

    private function handlerForRepo(string $repoPath): ClaudeWorktreeHookHandler
    {
        $git = $this->git;
        $exclude = $this->exclude;

        return new class ($git, $exclude, $repoPath) extends ClaudeWorktreeHookHandler {
            public function __construct(
                GitRepositoryService $gitRepositoryService,
                GitExcludeManager $gitExcludeManager,
                private readonly string $fixedRoot,
            ) {
                parent::__construct($gitRepositoryService, $gitExcludeManager);
            }

            protected function resolveRepositoryRoot(string $cwd): ?string
            {
                return $this->fixedRoot;
            }
        };
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}

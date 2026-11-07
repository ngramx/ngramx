<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Git\GitRepositoryService;
use Ngramx\Worktree\WorktreeAdopter;
use PHPUnit\Framework\TestCase;

class WorktreeAdopterTest extends TestCase
{
    private GitRepositoryService $gitService;

    private WorktreeAdopter $adopter;

    protected function setUp(): void
    {
        $this->gitService = $this->createMock(GitRepositoryService::class);
        $this->adopter = new WorktreeAdopter($this->gitService);
    }

    public function test_it_derives_target_folder_for_ticket_branch(): void
    {
        $folder = $this->adopter->deriveTargetFolder(
            '/path/to/repo',
            '/path/to/repo/.claude/worktrees/gig-1234',
            'gig-1234-feature'
        );

        $this->assertSame('gig-1234-repo', $folder);
    }

    public function test_it_derives_target_folder_for_non_ticket_branch(): void
    {
        $folder = $this->adopter->deriveTargetFolder(
            '/path/to/my-project',
            '/path/to/my-project/.claude/worktrees/bright-fox',
            'worktree-bright-fox'
        );

        $this->assertSame('bright-fox-my-project', $folder);
    }

    public function test_adopt_moves_claude_worktree_when_registered(): void
    {
        $repo = '/var/repo';
        $claudePath = $repo . '/.claude/worktrees/gig-99';
        $targetPath = $repo . '/.ngramx/worktrees/gig-99-repo';

        $this->gitService->expects($this->once())->method('worktreeExists')->with($repo, $claudePath)->willReturn(true);
        $this->gitService->expects($this->once())->method('moveWorktree')->with($repo, $claudePath, $targetPath)->willReturn(true);

        $this->assertSame($targetPath, $this->adopter->adopt($repo, $claudePath, 'gig-99-fix'));
    }
}

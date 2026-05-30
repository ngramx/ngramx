<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\WorktreeGitMount;
use PHPUnit\Framework\TestCase;

class WorktreeGitMountTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ngramx-git-mount-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_returns_null_when_no_git_present(): void
    {
        $project = $this->tempDir . '/project';
        mkdir($project, 0755, true);

        $this->assertNull((new WorktreeGitMount())->resolve($project));
    }

    public function test_returns_null_for_a_normal_checkout_with_git_directory(): void
    {
        $project = $this->tempDir . '/project';
        mkdir($project . '/.git', 0755, true);

        // A normal checkout's .git is a directory inside the project bind mount,
        // so no extra mount is required.
        $this->assertNull((new WorktreeGitMount())->resolve($project));
    }

    public function test_resolves_parent_common_git_dir_for_a_worktree(): void
    {
        // Simulate the on-disk layout git produces for a linked worktree:
        //   <repo>/.git/                         (parent shared git dir)
        //   <repo>/.git/worktrees/<name>/        (per-worktree admin dir)
        //   <repo>/.git/worktrees/<name>/commondir -> "../.."
        //   <repo>/wt/.git                       (file: "gitdir: <abs>/worktrees/<name>")
        $repoGit = $this->tempDir . '/repo/.git';
        $adminDir = $repoGit . '/worktrees/feature';
        mkdir($adminDir, 0755, true);
        file_put_contents($adminDir . '/commondir', "../..\n");

        $worktree = $this->tempDir . '/repo/wt';
        mkdir($worktree, 0755, true);
        file_put_contents($worktree . '/.git', 'gitdir: ' . $adminDir . "\n");

        $resolved = (new WorktreeGitMount())->resolve($worktree);

        $this->assertSame(realpath($repoGit), $resolved);
    }

    public function test_falls_back_to_default_layout_when_commondir_missing(): void
    {
        $repoGit = $this->tempDir . '/repo/.git';
        $adminDir = $repoGit . '/worktrees/feature';
        mkdir($adminDir, 0755, true);
        // No commondir file written — exercise the default-layout fallback.

        $worktree = $this->tempDir . '/repo/wt';
        mkdir($worktree, 0755, true);
        file_put_contents($worktree . '/.git', 'gitdir: ' . $adminDir . "\n");

        $resolved = (new WorktreeGitMount())->resolve($worktree);

        $this->assertSame(realpath($repoGit), $resolved);
    }

    public function test_resolves_relative_gitdir_pointer(): void
    {
        $repoGit = $this->tempDir . '/repo/.git';
        $adminDir = $repoGit . '/worktrees/feature';
        mkdir($adminDir, 0755, true);
        file_put_contents($adminDir . '/commondir', "../..\n");

        $worktree = $this->tempDir . '/repo/wt';
        mkdir($worktree, 0755, true);
        // Relative pointer from the worktree dir back to the admin dir.
        file_put_contents($worktree . '/.git', "gitdir: ../.git/worktrees/feature\n");

        $resolved = (new WorktreeGitMount())->resolve($worktree);

        $this->assertSame(realpath($repoGit), $resolved);
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
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

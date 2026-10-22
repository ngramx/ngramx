<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\WorktreeComposerAuthSeeder;
use PHPUnit\Framework\TestCase;

class WorktreeComposerAuthSeederTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-composer-auth-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_seed_copies_parent_auth_when_the_worktree_has_none(): void
    {
        $repo = $this->tmpDir . '/repo';
        $worktree = $this->tmpDir . '/worktree';
        mkdir($repo);
        mkdir($worktree);
        file_put_contents($repo . '/auth.json', '{"http-basic":{"nova.laravel.com":{"username":"rob"}}}');

        $this->assertTrue((new WorktreeComposerAuthSeeder())->seed($repo, $worktree));
        $this->assertSame(
            '{"http-basic":{"nova.laravel.com":{"username":"rob"}}}',
            file_get_contents($worktree . '/auth.json')
        );
    }

    public function test_seed_leaves_an_existing_worktree_file_unless_overwriting(): void
    {
        $repo = $this->tmpDir . '/repo';
        $worktree = $this->tmpDir . '/worktree';
        mkdir($repo);
        mkdir($worktree);
        file_put_contents($repo . '/auth.json', '{"username":"rob"}');
        file_put_contents($worktree . '/auth.json', '{"username":"dan"}');

        $this->assertFalse((new WorktreeComposerAuthSeeder())->seed($repo, $worktree));
        $this->assertSame('{"username":"dan"}', file_get_contents($worktree . '/auth.json'));

        $this->assertTrue((new WorktreeComposerAuthSeeder())->seed($repo, $worktree, overwrite: true));
        $this->assertSame('{"username":"rob"}', file_get_contents($worktree . '/auth.json'));
    }

    public function test_restore_from_cwd_overwrites_worktree_auth_from_the_parent(): void
    {
        $repo = $this->tmpDir . '/repo';
        $worktree = $repo . '/.ngramx/worktrees/gig-1-repo';
        mkdir($worktree, 0755, true);
        file_put_contents($repo . '/auth.json', '{"username":"rob"}');
        file_put_contents($worktree . '/auth.json', '{"username":"dan"}');

        $originalCwd = getcwd();
        $this->assertNotFalse($originalCwd);
        chdir($worktree);
        try {
            $this->assertTrue((new WorktreeComposerAuthSeeder())->restoreFromCwd());
        } finally {
            chdir($originalCwd);
        }

        $this->assertSame('{"username":"rob"}', file_get_contents($worktree . '/auth.json'));
    }

    public function test_restore_from_cwd_is_a_noop_outside_a_worktree(): void
    {
        $originalCwd = getcwd();
        $this->assertNotFalse($originalCwd);
        chdir($this->tmpDir);
        try {
            $this->assertFalse((new WorktreeComposerAuthSeeder())->restoreFromCwd());
        } finally {
            chdir($originalCwd);
        }
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

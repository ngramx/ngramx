<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Git;

use Ngramx\Git\GitExcludeManager;
use PHPUnit\Framework\TestCase;

class GitExcludeManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-exclude-test-' . uniqid();
        mkdir($this->tmpDir . '/.git', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_adds_entry_to_git_info_exclude(): void
    {
        $manager = new GitExcludeManager();
        $manager->ensureExcluded($this->tmpDir, '/.ngramx/worktrees/');

        $exclude = $this->tmpDir . '/.git/info/exclude';
        $this->assertFileExists($exclude);
        $this->assertStringContainsString('/.ngramx/worktrees/', (string) file_get_contents($exclude));
    }

    public function test_it_does_not_duplicate_entries(): void
    {
        $manager = new GitExcludeManager();
        $manager->ensureExcluded($this->tmpDir, '/.ngramx/worktrees/');
        $manager->ensureExcluded($this->tmpDir, '/.ngramx/worktrees/');

        $contents = (string) file_get_contents($this->tmpDir . '/.git/info/exclude');
        $this->assertSame(1, substr_count($contents, '/.ngramx/worktrees/'));
    }

    public function test_it_preserves_existing_exclude_contents(): void
    {
        mkdir($this->tmpDir . '/.git/info', 0755, true);
        file_put_contents($this->tmpDir . '/.git/info/exclude', "# existing\n*.log\n");

        $manager = new GitExcludeManager();
        $manager->ensureExcluded($this->tmpDir, '/.ngramx/worktrees/');

        $contents = (string) file_get_contents($this->tmpDir . '/.git/info/exclude');
        $this->assertStringContainsString('*.log', $contents);
        $this->assertStringContainsString('/.ngramx/worktrees/', $contents);
    }

    public function test_it_does_nothing_when_git_dir_missing(): void
    {
        $manager = new GitExcludeManager();
        $manager->ensureExcluded($this->tmpDir . '/nonexistent', '/.ngramx/worktrees/');

        $this->assertFileDoesNotExist($this->tmpDir . '/nonexistent/.git/info/exclude');
    }

    public function test_it_adds_entry_to_cursorignore(): void
    {
        $manager = new GitExcludeManager();
        $manager->ensureCursorIgnored($this->tmpDir, '/.ngramx/worktrees/');

        $cursorignore = $this->tmpDir . '/.cursorignore';
        $this->assertFileExists($cursorignore);
        $this->assertStringContainsString('/.ngramx/worktrees/', (string) file_get_contents($cursorignore));
    }

    public function test_it_does_not_duplicate_cursorignore_entries(): void
    {
        $manager = new GitExcludeManager();
        $manager->ensureCursorIgnored($this->tmpDir, '/.ngramx/worktrees/');
        $manager->ensureCursorIgnored($this->tmpDir, '/.ngramx/worktrees/');

        $contents = (string) file_get_contents($this->tmpDir . '/.cursorignore');
        $this->assertSame(1, substr_count($contents, '/.ngramx/worktrees/'));
    }

    public function test_it_excludes_generated_cursorignore_from_git(): void
    {
        $manager = new GitExcludeManager();
        $manager->ensureCursorIgnored($this->tmpDir, '/.ngramx/worktrees/');

        $exclude = $this->tmpDir . '/.git/info/exclude';
        $this->assertFileExists($exclude);
        $this->assertStringContainsString('/.cursorignore', (string) file_get_contents($exclude));
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

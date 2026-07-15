<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Output\OutputFormatter;
use Ngramx\Worktree\WorktreeDependencyPrimer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class WorktreeDependencyPrimerTest extends TestCase
{
    private string $tempDir;
    private string $repoPath;
    private string $worktreePath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ngramx-primer-test-' . uniqid();
        $this->repoPath = $this->tempDir . '/repo';
        $this->worktreePath = $this->tempDir . '/worktree';
        mkdir($this->repoPath, 0755, true);
        mkdir($this->worktreePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_it_copies_dependency_directories_into_the_worktree(): void
    {
        mkdir($this->repoPath . '/vendor/acme', 0755, true);
        file_put_contents($this->repoPath . '/vendor/acme/lib.php', '<?php // lib');
        mkdir($this->repoPath . '/node_modules/pkg', 0755, true);
        file_put_contents($this->repoPath . '/node_modules/pkg/index.js', 'module.exports = {};');

        $primer = new WorktreeDependencyPrimer();
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $primer->start($this->repoPath, $this->worktreePath, $formatter);
        $primer->await($formatter);

        $this->assertFileExists($this->worktreePath . '/vendor/acme/lib.php');
        $this->assertFileExists($this->worktreePath . '/node_modules/pkg/index.js');
        $this->assertStringContainsString('Priming vendor', $output->fetch());
    }

    public function test_it_skips_directories_the_worktree_already_has(): void
    {
        mkdir($this->repoPath . '/vendor', 0755, true);
        file_put_contents($this->repoPath . '/vendor/fresh.txt', 'from parent');

        // Reused worktree: vendor already present with its own content.
        mkdir($this->worktreePath . '/vendor', 0755, true);
        file_put_contents($this->worktreePath . '/vendor/existing.txt', 'already here');

        $primer = new WorktreeDependencyPrimer();
        $formatter = new OutputFormatter(new BufferedOutput());

        $primer->start($this->repoPath, $this->worktreePath, $formatter);
        $primer->await($formatter);

        $this->assertFileExists($this->worktreePath . '/vendor/existing.txt');
        $this->assertFileDoesNotExist($this->worktreePath . '/vendor/fresh.txt');
    }

    public function test_await_is_idempotent(): void
    {
        mkdir($this->repoPath . '/vendor', 0755, true);
        file_put_contents($this->repoPath . '/vendor/lib.txt', 'lib');

        $primer = new WorktreeDependencyPrimer();
        $formatter = new OutputFormatter(new BufferedOutput());

        $primer->start($this->repoPath, $this->worktreePath, $formatter);
        $primer->await($formatter);
        // A second await (e.g. from a finally block) must be a harmless no-op.
        $primer->await($formatter);

        $this->assertFileExists($this->worktreePath . '/vendor/lib.txt');
    }

    public function test_a_failed_copy_warns_but_does_not_throw(): void
    {
        mkdir($this->repoPath . '/vendor', 0755, true);
        file_put_contents($this->repoPath . '/vendor/lib.txt', 'lib');

        // Make the copy fail by pointing the worktree at a non-writable target.
        $unwritable = $this->tempDir . '/no-write';
        mkdir($unwritable, 0555, true);

        $primer = new WorktreeDependencyPrimer();
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $primer->start($this->repoPath, $unwritable, $formatter);
        $primer->await($formatter);

        $this->assertStringContainsString('Could not copy vendor', $output->fetch());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Restore write permission so cleanup can proceed.
        chmod($dir, 0755);

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

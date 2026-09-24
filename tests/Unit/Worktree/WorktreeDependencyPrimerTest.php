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

    public function test_it_does_not_copy_dependency_directories(): void
    {
        mkdir($this->repoPath . '/vendor/acme', 0755, true);
        file_put_contents($this->repoPath . '/vendor/acme/lib.php', '<?php // lib');
        mkdir($this->repoPath . '/node_modules/pkg', 0755, true);
        file_put_contents($this->repoPath . '/node_modules/pkg/index.js', 'module.exports = {};');
        file_put_contents($this->repoPath . '/composer.lock', '{}');
        file_put_contents($this->worktreePath . '/composer.lock', '{}');
        file_put_contents($this->repoPath . '/package-lock.json', '{}');
        file_put_contents($this->worktreePath . '/package-lock.json', '{}');

        $primer = new WorktreeDependencyPrimer();
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $primer->start($this->repoPath, $this->worktreePath, $formatter);
        $primer->await($formatter);
        $primer->await($formatter);

        $this->assertFileDoesNotExist($this->worktreePath . '/vendor/acme/lib.php');
        $this->assertFileDoesNotExist($this->worktreePath . '/node_modules/pkg/index.js');
        $this->assertStringContainsString('installed from the lockfile', $output->fetch());
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

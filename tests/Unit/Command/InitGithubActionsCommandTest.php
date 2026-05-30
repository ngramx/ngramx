<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Ngramx\Command\InitGithubActionsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InitGithubActionsCommandTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/ngramx_init_gha_' . uniqid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
        parent::tearDown();
    }

    public function test_writes_all_workflow_files(): void
    {
        $originalDir = getcwd();
        chdir($this->testDir);

        try {
            $app = new Application();
            $app->add(new InitGithubActionsCommand());
            $command = $app->find('init-github-actions');
            $tester = new CommandTester($command);
            $tester->execute([
                '--repo' => 'acme/shared-workflows',
                '--ref' => 'v1',
                '--ci-workflow-name' => 'CI',
                '--base-branch' => 'develop',
                '--primary-check-name' => 'PHP 8.3',
            ]);

            $this->assertSame(0, $tester->getStatusCode());
            $this->assertFileExists($this->testDir . '/.github/workflows/claude-auto-fix.yml');
            $this->assertFileExists($this->testDir . '/.github/workflows/claude-auto-rebase.yml');
            $this->assertFileExists($this->testDir . '/.github/workflows/claude-fix-review-comments.yml');
            $this->assertFileExists($this->testDir . '/.github/workflows/auto-merge.yml');
            $this->assertFileExists($this->testDir . '/.github/workflows/linear-status-sync.yml');

            $fix = file_get_contents($this->testDir . '/.github/workflows/claude-auto-fix.yml');
            $this->assertIsString($fix);
            $this->assertStringContainsString('acme/shared-workflows/.github/workflows/claude-auto-fix.yml@v1', $fix);
            $this->assertStringContainsString('workflows: ["CI"]', $fix);

            $linear = file_get_contents($this->testDir . '/.github/workflows/linear-status-sync.yml');
            $this->assertIsString($linear);
            $this->assertStringContainsString('acme/shared-workflows/.github/workflows/linear-status-sync.yml@v1', $linear);
            $this->assertStringContainsString("primary-check-name: 'PHP 8.3'", $linear);
            $this->assertStringContainsString("in-progress-state-name: 'In Progress'", $linear);
            $this->assertStringContainsString("in-review-state-name: 'In Review'", $linear);
            $this->assertStringContainsString('secrets: inherit', $linear);

            $rebase = file_get_contents($this->testDir . '/.github/workflows/claude-auto-rebase.yml');
            $this->assertIsString($rebase);
            $this->assertStringContainsString("branches: ['develop']", $rebase);
            $this->assertStringContainsString("base-branch: 'develop'", $rebase);
        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }
        }
    }

    public function test_auto_merge_uses_default_protected_branches_and_label(): void
    {
        $originalDir = getcwd();
        chdir($this->testDir);

        try {
            $app = new Application();
            $app->add(new InitGithubActionsCommand());
            $command = $app->find('init-github-actions');
            $tester = new CommandTester($command);
            $tester->execute([
                '--repo' => 'acme/shared-workflows',
                '--ref' => 'v2',
            ]);

            $this->assertSame(0, $tester->getStatusCode());
            $autoMerge = file_get_contents($this->testDir . '/.github/workflows/auto-merge.yml');
            $this->assertIsString($autoMerge);

            $this->assertStringContainsString(
                'acme/shared-workflows/.github/workflows/auto-merge.yml@v2',
                $autoMerge
            );
            $this->assertStringContainsString("risk-low-label: 'risk:low'", $autoMerge);
            $this->assertStringContainsString("size-small-label: 'size:small'", $autoMerge);
            $this->assertStringContainsString("auto-merge-label: 'auto-merge'", $autoMerge);
            $this->assertStringContainsString(
                "protected-branches: 'prod,production,stage,staging,test,testing'",
                $autoMerge
            );
            $this->assertStringContainsString("merge-method: 'squash'", $autoMerge);
        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }
        }
    }

    public function test_auto_merge_honors_custom_options_and_normalizes_branches(): void
    {
        $originalDir = getcwd();
        chdir($this->testDir);

        try {
            $app = new Application();
            $app->add(new InitGithubActionsCommand());
            $command = $app->find('init-github-actions');
            $tester = new CommandTester($command);
            $tester->execute([
                '--repo' => 'acme/shared-workflows',
                '--risk-low-label' => 'risk-low',
                '--size-small-label' => 'size-xs',
                '--auto-merge-label' => 'ship-it',
                '--protected-branches' => ' main , release ,, main, qa ',
                '--merge-method' => 'rebase',
            ]);

            $this->assertSame(0, $tester->getStatusCode());
            $autoMerge = file_get_contents($this->testDir . '/.github/workflows/auto-merge.yml');
            $this->assertIsString($autoMerge);
            $this->assertStringContainsString("risk-low-label: 'risk-low'", $autoMerge);
            $this->assertStringContainsString("size-small-label: 'size-xs'", $autoMerge);
            $this->assertStringContainsString("auto-merge-label: 'ship-it'", $autoMerge);
            $this->assertStringContainsString("protected-branches: 'main,release,qa'", $autoMerge);
            $this->assertStringContainsString("merge-method: 'rebase'", $autoMerge);
        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }
        }
    }

    public function test_auto_merge_rejects_invalid_merge_method(): void
    {
        $originalDir = getcwd();
        chdir($this->testDir);

        try {
            $app = new Application();
            $app->add(new InitGithubActionsCommand());
            $command = $app->find('init-github-actions');
            $tester = new CommandTester($command);
            $tester->execute([
                '--repo' => 'acme/shared-workflows',
                '--merge-method' => 'force-push',
            ]);

            $this->assertNotSame(0, $tester->getStatusCode());
            $this->assertFileDoesNotExist($this->testDir . '/.github/workflows/auto-merge.yml');
        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

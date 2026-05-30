<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\WorktreeOwnershipReconciler;
use PHPUnit\Framework\TestCase;

class WorktreeOwnershipReconcilerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ngramx-ownership-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_target_owner_is_the_directory_that_contains_the_worktree(): void
    {
        // The worktree lives at <container>/<folder>; ownership should be derived
        // from <container>, which is part of the developer's checkout.
        $container = $this->tempDir . '/.ngramx/worktrees';
        $worktree = $container . '/gig-123-app';
        mkdir($worktree, 0755, true);

        $owner = (new WorktreeOwnershipReconciler())->resolveTargetOwner($worktree);

        $this->assertNotNull($owner);
        $this->assertSame(fileowner($container), $owner[0]);
        $this->assertSame(filegroup($container), $owner[1]);
    }

    public function test_target_owner_ignores_a_trailing_slash(): void
    {
        $container = $this->tempDir . '/.ngramx/worktrees';
        $worktree = $container . '/gig-123-app';
        mkdir($worktree, 0755, true);

        $owner = (new WorktreeOwnershipReconciler())->resolveTargetOwner($worktree . '/');

        $this->assertNotNull($owner);
        $this->assertSame(fileowner($container), $owner[0]);
    }

    public function test_target_owner_is_null_when_reference_directory_is_missing(): void
    {
        $missing = $this->tempDir . '/does-not-exist/child';

        $this->assertNull((new WorktreeOwnershipReconciler())->resolveTargetOwner($missing));
    }

    public function test_reconcile_skips_a_normal_checkout(): void
    {
        // A plain directory with no worktree .git pointer is not a linked worktree,
        // so reconcile must skip without invoking Docker.
        $project = $this->tempDir . '/project';
        mkdir($project, 0755, true);

        $result = (new WorktreeOwnershipReconciler())->reconcile($project);

        $this->assertTrue($result->isSkipped());
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

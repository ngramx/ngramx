<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Composer credentials live in gitignored `auth.json`. Worktrees do not
 * inherit that file, and `pre_start` often recreates it from `$NOVA_*`
 * in `.env` — a different account than the parent's working store.
 *
 * Copy the parent file into the worktree, and do it again after `pre_start`
 * so `composer install` inside the container sees the parent credentials.
 */
class WorktreeComposerAuthSeeder
{
    public function seed(string $repositoryPath, string $worktreePath, bool $overwrite = false): bool
    {
        $worktreeAuth = $worktreePath . '/auth.json';
        if (!$overwrite && is_file($worktreeAuth)) {
            return false;
        }

        $parentAuth = $repositoryPath . '/auth.json';
        if (!is_file($parentAuth)) {
            return false;
        }

        if (!@copy($parentAuth, $worktreeAuth)) {
            return false;
        }

        @chmod($worktreeAuth, 0600);

        return true;
    }

    /**
     * When cwd is a worktree, overwrite its `auth.json` from the parent
     * checkout. No-op in a normal checkout.
     */
    public function restoreFromCwd(): bool
    {
        $cwd = getcwd();
        if (!is_string($cwd) || !preg_match('#/\.ngramx/worktrees/[^/]+$#', $cwd)) {
            return false;
        }

        $repositoryPath = preg_replace('#/\.ngramx/worktrees/[^/]+$#', '', $cwd);
        if (!is_string($repositoryPath) || $repositoryPath === '') {
            return false;
        }

        return $this->seed($repositoryPath, $cwd, overwrite: true);
    }
}

<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Resolves the host path that must be bind-mounted into a worktree's containers
 * so that git works (and never crashes the entrypoint) inside them.
 *
 * A linked git worktree keeps its `.git` as a *file* containing a pointer:
 *
 *     gitdir: /abs/host/path/.git/worktrees/<name>
 *
 * That per-worktree admin directory in turn has a `commondir` file pointing at
 * the parent repository's real, shared git directory (`/abs/host/path/.git`).
 * Both of those paths live OUTSIDE the worktree directory, so when a container
 * bind-mounts only the worktree (the project root) git follows the pointer to
 * a path that does not exist inside the container and aborts with:
 *
 *     fatal: not a git repository: /abs/host/path/.git/worktrees/<name>
 *
 * If the project's entrypoint runs any git command under `set -e`, that crash
 * makes the container restart forever (Restarting 128).
 *
 * The most robust fix is to bind-mount the parent repository's shared git
 * directory into the container at the SAME absolute path the gitdir pointer
 * already references. Because the pointer and `commondir` are both rooted at
 * that shared directory, mounting it unchanged makes the whole chain resolve
 * inside the container without rewriting any git metadata.
 *
 * This class is intentionally side-effect free (it only reads git metadata)
 * so the resolution rules can be unit tested without Docker.
 */
class WorktreeGitMount
{
    /**
     * Resolve the absolute host path of the parent repository's shared git dir
     * for the worktree rooted at $projectDir, or null when $projectDir is not a
     * linked worktree (a normal checkout keeps `.git` inside the project, which
     * the project bind mount already covers, so no extra mount is needed).
     */
    public function resolve(string $projectDir): ?string
    {
        $gitPath = rtrim($projectDir, '/') . '/.git';

        // A normal (non-worktree) checkout keeps .git as a directory inside the
        // project tree, which is already part of the project bind mount.
        if (!is_file($gitPath)) {
            return null;
        }

        $contents = @file_get_contents($gitPath);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/^gitdir:\s*(.+?)\s*$/m', $contents, $matches) !== 1) {
            return null;
        }

        $gitdir = trim($matches[1]);
        if (!$this->isAbsolute($gitdir)) {
            $gitdir = rtrim($projectDir, '/') . '/' . $gitdir;
        }

        $perWorktreeGitDir = realpath($gitdir);
        if ($perWorktreeGitDir === false) {
            return null;
        }

        return $this->resolveCommonDir($perWorktreeGitDir);
    }

    /**
     * Resolve the shared (common) git directory from a per-worktree admin dir,
     * using its `commondir` pointer when present and otherwise falling back to
     * git's default `<commonDir>/worktrees/<name>` layout.
     */
    private function resolveCommonDir(string $perWorktreeGitDir): ?string
    {
        $commonFile = $perWorktreeGitDir . '/commondir';
        if (is_file($commonFile)) {
            $relative = trim((string) @file_get_contents($commonFile));
            if ($relative !== '') {
                $candidate = $this->isAbsolute($relative)
                    ? $relative
                    : $perWorktreeGitDir . '/' . $relative;

                $resolved = realpath($candidate);
                if ($resolved !== false) {
                    return $resolved;
                }
            }
        }

        // Default git layout: the per-worktree dir is <commonDir>/worktrees/<name>.
        $fallback = realpath($perWorktreeGitDir . '/../..');

        return $fallback !== false ? $fallback : null;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}

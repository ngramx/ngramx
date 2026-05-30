<?php

declare(strict_types=1);

namespace Ngramx\Git;

/**
 * Keeps nested worktrees from polluting the parent checkout.
 *
 * Worktrees created under .ngramx/worktrees/ live inside the main working tree,
 * so without help git would report them as untracked and Cursor would index
 * them. We use .git/info/exclude (local-only, never committed) to hide them from
 * git, and a .cursorignore entry so the parent Cursor window stops indexing them.
 */
class GitExcludeManager
{
    /**
     * Ensure an entry exists in the repository's .git/info/exclude file.
     */
    public function ensureExcluded(string $repositoryPath, string $entry): void
    {
        $gitDir = $repositoryPath . '/.git';

        // Only the main checkout (a real .git directory) is handled; if .git is a
        // file (e.g. inside a worktree) we skip rather than guess at the gitdir.
        if (!is_dir($gitDir)) {
            return;
        }

        $infoDir = $gitDir . '/info';
        if (!is_dir($infoDir)) {
            @mkdir($infoDir, 0755, true);
        }

        $this->ensureLineInFile($infoDir . '/exclude', $entry);
    }

    /**
     * Ensure an entry exists in the repository root's .cursorignore file.
     */
    public function ensureCursorIgnored(string $repositoryPath, string $entry): void
    {
        $this->ensureLineInFile($repositoryPath . '/.cursorignore', $entry);
    }

    /**
     * Append a line to a file if it is not already present (idempotent).
     */
    private function ensureLineInFile(string $file, string $entry): void
    {
        $entry = trim($entry);
        if ($entry === '') {
            return;
        }

        $existing = is_file($file) ? (file_get_contents($file) ?: '') : '';

        $lines = preg_split('/\r\n|\r|\n/', $existing) ?: [];
        foreach ($lines as $line) {
            if (trim($line) === $entry) {
                return;
            }
        }

        $prefix = ($existing === '' || str_ends_with($existing, "\n")) ? '' : "\n";
        file_put_contents($file, $prefix . $entry . "\n", FILE_APPEND);
    }
}

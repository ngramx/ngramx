<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Shared path markers for ngramx-managed and Claude Code default worktrees.
 */
final class WorktreePaths
{
    public const NGRAMX_WORKTREE_DIR = '.ngramx/worktrees';

    public const CLAUDE_WORKTREE_DIR = '.claude/worktrees';

    private const NGRAMX_MARKER = '/.ngramx/worktrees/';

    private const CLAUDE_MARKER = '/.claude/worktrees/';

    public static function isNgramxManaged(string $path): bool
    {
        return str_contains(self::normalize($path), self::NGRAMX_MARKER);
    }

    public static function isClaudeDefault(string $path): bool
    {
        return str_contains(self::normalize($path), self::CLAUDE_MARKER);
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}

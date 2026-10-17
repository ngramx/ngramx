<?php

declare(strict_types=1);

namespace Ngramx\Review;

use Ngramx\Config\Schema\HookDefinition;

/**
 * Decides which command runs after `ngramx review`.
 *
 * `hooks.onReview` is a generic command hook: any shell command, not only an
 * editor. Bare editor names on `--editor` are shortcuts. Precedence:
 * 1. `--editor` replaces any configured hook for this run.
 * 2. `hooks.onReview` from user or project config.
 * 3. `--cursor` with nothing configured opens Cursor.
 */
final class PostReviewEditor
{
    /**
     * Bare editor names accepted by `--editor`. `cursor` uses the built-in
     * launcher so the IPC retry behaviour of `-c` is preserved.
     *
     * @var array<string, string>
     */
    public const PRESETS = [
        'cursor' => 'cursor --new-window {path}',
        'code' => 'code --new-window {path}',
        'vscode' => 'code --new-window {path}',
        'zed' => 'zed {path}',
    ];

    /**
     * @param list<HookDefinition> $configuredHooks
     */
    public static function resolve(?string $editorOption, bool $cursorFlag, array $configuredHooks): PostReviewEditorPlan
    {
        $editor = trim((string) $editorOption);
        if ($editor !== '') {
            return self::fromCli($editor);
        }

        if ($configuredHooks !== []) {
            return new PostReviewEditorPlan(hooks: $configuredHooks);
        }

        if ($cursorFlag) {
            return new PostReviewEditorPlan(openCursor: true);
        }

        return new PostReviewEditorPlan();
    }

    private static function fromCli(string $editor): PostReviewEditorPlan
    {
        $preset = self::PRESETS[strtolower($editor)] ?? null;
        if ($preset !== null && strtolower($editor) === 'cursor') {
            return new PostReviewEditorPlan(openCursor: true);
        }

        return new PostReviewEditorPlan(hooks: [
            new HookDefinition(
                command: $preset ?? self::commandWithPath($editor),
                description: 'Open editor',
            ),
        ]);
    }

    private static function commandWithPath(string $editor): string
    {
        if (
            str_contains($editor, '{path}')
            || str_contains($editor, '{worktree_path}')
            || self::isShellCommand($editor)
        ) {
            return $editor;
        }

        return $editor . ' {path}';
    }

    /**
     * A pipeline, redirect, or variable is already a complete command. A bare
     * editor invocation still needs the worktree path appended.
     */
    private static function isShellCommand(string $editor): bool
    {
        return preg_match('/&&|\|\||[|&;<>`$]|\n/', $editor) === 1;
    }
}

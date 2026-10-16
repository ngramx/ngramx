<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Review;

use Ngramx\Config\Schema\HookDefinition;
use Ngramx\Review\PostReviewEditor;
use PHPUnit\Framework\TestCase;

class PostReviewEditorTest extends TestCase
{
    public function test_nothing_configured_and_no_flags_launches_nothing(): void
    {
        $plan = PostReviewEditor::resolve(null, false, []);

        $this->assertFalse($plan->launchesAnything());
    }

    public function test_cursor_flag_opens_cursor_when_nothing_is_configured(): void
    {
        $plan = PostReviewEditor::resolve(null, true, []);

        $this->assertTrue($plan->openCursor);
        $this->assertSame([], $plan->hooks);
    }

    public function test_configured_hook_replaces_the_cursor_launcher(): void
    {
        $hook = new HookDefinition(command: 'zed {path}', description: 'Open Zed');

        $plan = PostReviewEditor::resolve(null, true, [$hook]);

        $this->assertFalse($plan->openCursor);
        $this->assertSame([$hook], $plan->hooks);
    }

    public function test_editor_flag_overrides_the_configured_hook(): void
    {
        $hook = new HookDefinition(command: 'zed {path}');

        $plan = PostReviewEditor::resolve('code', true, [$hook]);

        $this->assertFalse($plan->openCursor);
        $this->assertSame('code --new-window {path}', $plan->hooks[0]->command);
    }

    public function test_editor_cursor_uses_the_built_in_launcher(): void
    {
        $plan = PostReviewEditor::resolve('Cursor', false, [
            new HookDefinition(command: 'zed {path}'),
        ]);

        $this->assertTrue($plan->openCursor);
        $this->assertSame([], $plan->hooks);
    }

    public function test_known_editor_names_expand_to_presets(): void
    {
        $this->assertSame('zed {path}', PostReviewEditor::resolve('zed', false, [])->hooks[0]->command);
        $this->assertSame('code --new-window {path}', PostReviewEditor::resolve('vscode', false, [])->hooks[0]->command);
    }

    public function test_unknown_command_receives_the_path_placeholder(): void
    {
        $plan = PostReviewEditor::resolve('emacs -nw', false, []);

        $this->assertSame('emacs -nw {path}', $plan->hooks[0]->command);
    }

    public function test_command_template_is_kept_as_written(): void
    {
        $plan = PostReviewEditor::resolve('zed {worktree_path}', false, []);

        $this->assertSame('zed {worktree_path}', $plan->hooks[0]->command);
    }
}

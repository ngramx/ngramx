<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\ClaudeWorktree\CreateCommand;
use Ngramx\Command\ClaudeWorktree\RemoveCommand;
use Ngramx\Worktree\ClaudeWorktreeHookHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ClaudeWorktreeCommandTest extends TestCase
{
    public function test_create_command_prints_path_on_success(): void
    {
        $handler = new class extends ClaudeWorktreeHookHandler {
            public function create(string $hookJson): array
            {
                return ['ok' => true, 'path' => '/repo/.ngramx/worktrees/gig-1-repo'];
            }
        };

        $tester = new CommandTester(new CreateCommand($handler, '{"name":"gig-1"}'));
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertSame("/repo/.ngramx/worktrees/gig-1-repo\n", $tester->getDisplay());
    }

    public function test_create_command_fails_on_handler_error(): void
    {
        $handler = new class extends ClaudeWorktreeHookHandler {
            public function create(string $hookJson): array
            {
                return ['ok' => false, 'error' => 'boom'];
            }
        };

        $tester = new CommandTester(new CreateCommand($handler, '{}'));
        $this->assertSame(1, $tester->execute([]));
    }

    public function test_remove_command_succeeds_quietly(): void
    {
        $handler = new class extends ClaudeWorktreeHookHandler {
            public function remove(string $hookJson): array
            {
                return ['ok' => true, 'path' => '/repo/.ngramx/worktrees/x'];
            }
        };

        $tester = new CommandTester(new RemoveCommand($handler, '{"worktree_path":"/repo/.ngramx/worktrees/x"}'));
        $this->assertSame(0, $tester->execute([]));
        $this->assertSame('', $tester->getDisplay());
    }
}

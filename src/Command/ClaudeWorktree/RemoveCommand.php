<?php

declare(strict_types=1);

namespace Ngramx\Command\ClaudeWorktree;

use Ngramx\Worktree\ClaudeWorktreeHookHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Claude Code WorktreeRemove hook entrypoint.
 *
 * Reads Claude's JSON from stdin. Does not stop Docker — use
 * `ngramx worktree --cleanup` for environment teardown.
 */
class RemoveCommand extends Command
{
    public function __construct(
        private readonly ClaudeWorktreeHookHandler $handler = new ClaudeWorktreeHookHandler(),
        private readonly ?string $stdinOverride = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('claude-worktree:remove')
            ->setDescription('Claude Code WorktreeRemove hook: remove under .ngramx/worktrees/')
            ->setHelp(<<<'HELP'
Implements the Claude Code <info>WorktreeRemove</info> hook. Reads JSON from
stdin (field <info>worktree_path</info>) and removes a managed worktree under
<code>.ngramx/worktrees/</code>.

Does <comment>not</comment> stop Docker environments — use
<info>ngramx worktree --cleanup</info> for that.

Configure once in <info>~/.claude/settings.json</info>:

  {
    "hooks": {
      "WorktreeRemove": [{
        "hooks": [{ "type": "command", "command": "ngramx claude-worktree remove" }]
      }]
    }
  }
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hookJson = $this->stdinOverride ?? (string) stream_get_contents(STDIN);
        $result = $this->handler->remove($hookJson);

        if (!$result['ok']) {
            fwrite(STDERR, 'Error: ' . $result['error'] . PHP_EOL);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

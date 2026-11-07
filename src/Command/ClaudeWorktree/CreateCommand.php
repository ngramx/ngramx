<?php

declare(strict_types=1);

namespace Ngramx\Command\ClaudeWorktree;

use Ngramx\Worktree\ClaudeWorktreeHookHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Claude Code WorktreeCreate hook entrypoint.
 *
 * Reads Claude's JSON from stdin and prints only the absolute worktree path
 * on stdout. Configure once in ~/.claude/settings.json:
 *
 *   "command": "ngramx claude-worktree create"
 */
class CreateCommand extends Command
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
            ->setName('claude-worktree:create')
            ->setDescription('Claude Code WorktreeCreate hook: create under .ngramx/worktrees/')
            ->setHelp(<<<'HELP'
Implements the Claude Code <info>WorktreeCreate</info> hook. Reads JSON from
stdin (fields <info>name</info>, <info>cwd</info>), creates a git worktree under
<code>.ngramx/worktrees/</code>, and prints the absolute path on stdout.

Does <comment>not</comment> run <info>ngramx up</info>.

Configure once in <info>~/.claude/settings.json</info>:

  {
    "hooks": {
      "WorktreeCreate": [{
        "hooks": [{ "type": "command", "command": "ngramx claude-worktree create" }]
      }]
    }
  }
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hookJson = $this->stdinOverride ?? (string) stream_get_contents(STDIN);
        $result = $this->handler->create($hookJson);

        if (!$result['ok']) {
            fwrite(STDERR, 'Error: ' . $result['error'] . PHP_EOL);

            return Command::FAILURE;
        }

        // Claude reads stdout as the worktree path — no chrome.
        $output->writeln($result['path']);

        return Command::SUCCESS;
    }
}

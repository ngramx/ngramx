<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Output\OutputFormatter;

/**
 * Previously copied vendor and node_modules from the parent checkout into a
 * fresh worktree. That copy is gone.
 *
 * A matching lockfile does not mean the installed tree is complete: a failed
 * install leaves a package directory that npm treats as present and will not
 * repair. The project's own startup installs from the lockfile into an empty
 * tree, which is the only check that the dependencies are actually there.
 *
 * start() and await() remain so the worktree flow still has a single place to
 * wait before the reset step. They do not copy or install anything.
 */
class WorktreeDependencyPrimer
{
    public function start(string $repositoryPath, string $worktreePath, OutputFormatter $formatter): void
    {
        $formatter->info('Dependencies will be installed from the lockfile on startup.');
    }

    public function await(OutputFormatter $formatter): void
    {
    }
}

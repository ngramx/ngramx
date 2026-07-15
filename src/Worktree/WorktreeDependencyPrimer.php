<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Output\OutputFormatter;
use Symfony\Component\Process\Process;

/**
 * Primes a fresh worktree's dependency directories (vendor, node_modules) by
 * copying them from the parent checkout, so the install step is a near-instant
 * no-op instead of a cold download.
 *
 * The copies are started concurrently and awaited separately so callers can
 * overlap them with work that does not read the dependency directories —
 * typically Docker image reuse and `up`. Only the reset/install step actually
 * touches vendor/node_modules, so awaiting just before it hides the copy
 * latency behind the (usually slower) Docker startup.
 *
 * The copy processes use absolute source/target paths, so they are safe to
 * start before the caller chdir()s into the worktree.
 */
class WorktreeDependencyPrimer
{
    /**
     * Dependency directories copied from the parent checkout into a fresh
     * worktree. Git ignores these, so the copy is safe.
     *
     * @var list<string>
     */
    private const DEPENDENCY_DIRS = ['vendor', 'node_modules'];

    /** @var array<string, Process> */
    private array $processes = [];

    /**
     * Start the dependency copies concurrently without waiting for them.
     * Skipped per-directory when the parent lacks it or the worktree already
     * has it (e.g. a reused worktree).
     */
    public function start(string $repositoryPath, string $worktreePath, OutputFormatter $formatter): void
    {
        foreach (self::DEPENDENCY_DIRS as $dir) {
            $source = $repositoryPath . '/' . $dir;
            $target = $worktreePath . '/' . $dir;

            if (!is_dir($source) || file_exists($target)) {
                continue;
            }

            $formatter->info("Priming $dir from parent checkout...");

            // -a preserves symlinks/permissions; reflink=auto is a fast copy-on-write
            // clone on supporting filesystems and falls back to a normal copy otherwise.
            $process = new Process(['cp', '-a', '--reflink=auto', $source, $target]);
            $process->setTimeout(300);
            $process->start();

            $this->processes[$dir] = $process;
        }
    }

    /**
     * Block until every started copy has finished. Idempotent: a second call
     * (e.g. from a finally block covering early-return paths) is a no-op, so
     * copies are never leaked or awaited twice.
     *
     * Failures are non-fatal: the install step will simply repopulate the
     * directory, exactly as if priming had been skipped.
     */
    public function await(OutputFormatter $formatter): void
    {
        $processes = $this->processes;
        $this->processes = [];

        foreach ($processes as $dir => $process) {
            $process->wait();

            if (!$process->isSuccessful()) {
                $formatter->warning("Could not copy $dir into the worktree — the install step will fetch it instead.");
            }
        }
    }
}

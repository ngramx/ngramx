# COR-257: Overlap worktree dependency priming with environment startup in `review`

## Summary

The vendor/node_modules priming copies for a fresh worktree now run concurrently with Docker image reuse and `up`, instead of blocking before them. The copies are awaited just before the reset step — the first thing that actually reads the dependency directories — hiding the copy latency behind the (typically slower) Docker startup.

## Requirements

- Start the priming copies without waiting; kick off image reuse + `up`; await the copies before the reset step.
- Keep priming non-fatal (warn and let the install step repopulate).
- Copies use absolute paths, safe to start before `chdir()`.
- Copies are always awaited, including on early-return/failure paths, so no detached `cp` processes leak.
- Reused worktrees skip priming (target already exists).
- Unit coverage locking the ordering guarantee.

## Changes

- `src/Worktree/WorktreeDependencyPrimer.php` — new class extracted from `ReviewCommand::primeWorktreeDependencies()` with separate `start()` (concurrent `cp -a --reflink=auto`, per-directory skip rules unchanged) and idempotent `await()` (non-fatal warnings on failure).
- `src/Command/ReviewCommand.php` — `runWorktreeReview()` starts priming before `chdir`, awaits it right before the reset step, and re-awaits in the `finally` (idempotent) so `up` failures and the chdir failure path never leak a running copy. The primer is injectable for tests.
- `tests/Unit/Worktree/WorktreeDependencyPrimerTest.php` — copies land, reused worktrees skipped, idempotent await, failed copy warns without throwing.
- `tests/Unit/Command/ReviewCommandTest.php` — ordering guarantee (start → await → reset) and the early-return path (chdir failure still awaits).

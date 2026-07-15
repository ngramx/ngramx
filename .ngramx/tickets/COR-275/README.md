# COR-275: Fix `ngramx review` false worktree-creation failure caused by post-checkout hook

## Summary

`git worktree add` fires the repo's post-checkout hook; in a brand-new worktree the hook's dependencies (e.g. `vendor/bin/captainhook`) are not primed yet, so the hook fails and git propagates the non-zero exit — making `addWorktree()` report failure for a perfectly good worktree.

## Requirements

- `ngramx review --worktree <ticket>` succeeds on a fresh worktree even when the repo has a `post-checkout` hook that depends on `vendor/`.
- A failing/absent post-checkout hook no longer causes a false "Failed to create git worktree" error.
- Creation success is determined by the worktree actually being registered, not solely by the `git worktree add` exit code.
- Genuine partial failures are cleaned up so retries start clean.
- Existing worktrees for other branches are unaffected.

## Changes

- `src/Git/GitRepositoryService.php` — `addWorktree()` now runs `git -c core.hooksPath=/dev/null worktree add ...` so checkout hooks cannot fail the creation; the same applies to the best-effort fast-forward. On a non-zero exit, `worktreeExists()` is consulted as the authoritative success signal. Genuine failures now clean up the half-created directory and prune the stale admin entry via the new `cleanUpFailedWorktree()`.
- `tests/Unit/Git/GitRepositoryServiceTest.php` — added coverage: creation succeeds despite a failing post-checkout hook; unknown branch still returns false; a retry after a genuine failure succeeds (cleanup verified).

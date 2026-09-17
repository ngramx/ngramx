# GIG-3192: Fix ngramx worktree creation: ensure main branch is updated before spinning up dev environment

## Summary

Codabyte / Cortex coder was forking ticket worktrees from a stale parent HEAD. Cam's fix fast-forwards the integration branch before `git worktree add -b`. This ticket verifies that fix and makes it robust across repos whose parent checkout is dirty, diverged, or left on a feature branch.

## Requirements

- Verify Cam's fix that forces a git pull / branch update on main before worktree creation
- Test that the Codabyte bot reliably picks up the latest main branch changes
- Ensure this works across all project repos that use the ngramx + Cortex coder workflow

## Changes

- Ticket folder created.
- Verified Cam's `1dd0147` fix: `prepareIntegrationBranchForNewWorktree()` plus a call from `ReviewCommand` before `git worktree add -b`. That path is shared by `ngramx worktree` and Codabyte (`cd /workspace/repos/<repo> && ngramx worktree <ticket> --no-host-mapping --no-interaction`).
- The remaining hole: Cam's method checked out and fast-forwarded **local** main in the parent working tree, then created the new branch from **HEAD**. On a shared host that parent checkout is often dirty, diverged, or left on a leftover feature branch — so the update failed, or the new worktree still started from stale HEAD.
- New ticket branches now fork from `origin/<integration>` (`origin/main` or `origin/master` via `origin/HEAD`, then those remotes). The parent checkout is not switched. This is repo-agnostic for every ngramx + Cortex coder project; Codabyte picks it up on the next ngramx release.
- completion.json recorded for PR open.

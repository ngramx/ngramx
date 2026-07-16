# COR-278: Auto-worktree current feature branch when running `ngramx worktree` with no arguments

## Summary

Allow `ngramx worktree` with no ticket when checked out on a feature branch — stash changes, move the main checkout back to the integration branch, create a worktree for the current branch, and restore the stash before startup.

## Requirements

- On `main`, `staging`, or `production`: show a warning and do nothing
- On a feature branch: stash uncommitted changes, switch main checkout to integration branch, create worktree, pop stash before environment startup
- Preserve existing ticket-based `ngramx worktree <ticket>` behaviour

## Changes

- `src/Command/WorktreeCommand.php`: `runWorktreeFromCurrentBranch()` for no-ticket invocation
- `src/Command/ReviewCommand.php`: optional `popStash` flag in `runWorktreeReview()`
- `src/Git/GitRepositoryService.php`: current branch, integration branch, stash, and local checkout helpers
- Tests in `WorktreeCommandTest` and `GitRepositoryServiceTest`

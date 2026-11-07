# GIG-4500: Integrate Claude Code worktrees with ngramx lifecycle

## Summary

Implements Claude hook scripts, worktree adoption from `.claude/worktrees/`, and an interactive `ngramx worktree` picker. Based on CodaByte's local commit (branch never pushed — see GIG-4502).

## Requirements

See Linear GIG-4500 acceptance criteria (hooks, picker, adoption, no auto `up`).

## Changes

- `scripts/claude-worktree-create.sh` / `claude-worktree-remove.sh` — Claude hooks targeting `.ngramx/worktrees/`
- `WorktreeAdopter`, `WorktreePaths` — adopt Claude worktrees via `git worktree move`
- `WorktreeCommand` — adoption prompt and integration-branch picker
- `WorktreeInventory::listClaudeWorktreeDirectories`
- `GitRepositoryService::findWorktreePathForBranch`, `moveWorktree`
- `docs/claude-code-integration.md`, unit tests

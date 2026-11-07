# GIG-4500: Integrate Claude Code worktrees with ngramx lifecycle

## Summary

Implements Claude worktree hooks as ngramx CLI commands, adoption from `.claude/worktrees/`, and an interactive `ngramx worktree` picker. Based on CodaByte's local commit (branch never pushed — see GIG-4502).

## Requirements

See Linear GIG-4500 acceptance criteria (hooks, picker, adoption, no auto `up`).

## Changes

- `ngramx claude-worktree create|remove` — Claude hooks targeting `.ngramx/worktrees/` (configure once in `~/.claude/settings.json`)
- `ClaudeWorktreeHookHandler` — shared create/remove logic (cwd → repo root, no `ngramx up`)
- `scripts/claude-worktree-*.sh` — thin wrappers around the CLI for older project-level settings
- `WorktreeAdopter`, `WorktreePaths` — adopt Claude worktrees via `git worktree move`
- `WorktreeCommand` — adoption prompt and integration-branch picker
- `WorktreeInventory::listClaudeWorktreeDirectories`
- `GitRepositoryService::findWorktreePathForBranch`, `moveWorktree`
- `docs/claude-code-integration.md`, unit tests

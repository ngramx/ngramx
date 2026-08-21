# COR-328: Add user-level hooks config with project-level overrides

## Summary

Add event-based hooks for ngramx actions (e.g. worktree creation), loaded from a user-level `~/.ngramx.yaml` with project-level overrides via deep merge.

## Requirements

- Support user-level config at `~/.ngramx.yaml` defining event hooks (e.g. `onWorktreeCreate`, `onEnvironmentUp`).
- Support project-level overrides (e.g. `.ngramx/config.yaml` or similar) that deep-merge over user defaults.
- Hooks are event-driven and tool-agnostic (not tied to TMUX); they run configured commands when the event fires.
- Goal: eliminate repetitive manual steps when creating/switching worktrees (open editor, cd, etc.).

## Changes

- Ticket folder created; branch `cor-328-add-user-level-hooks-config-with-project-level-overrides` started from `origin/main`.
- Added `HooksConfigLoader` with deep-merge across `~/.ngramx.yaml`, `.ngramx/config.yaml`, and `hooks:` in `ngramx.yml`.
- Added `HookRunner` and wired `onWorktreeCreate` (worktree/review) and `onEnvironmentUp` (`up`).
- Documented in README + `ngramx.example.yml`; unit tests for merge, loader, and runner.

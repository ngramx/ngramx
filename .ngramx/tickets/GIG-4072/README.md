# GIG-4072: Restore parent auth.json after pre_start before compose up

## Summary

Restore parent `auth.json` after `pre_start` hooks finish and before Docker starts, so container `composer install` uses the parent Nova credentials instead of a `.env` account.

## Requirements

- A leftover worktree `auth.json` is replaced by the parent file before `up`.
- After `pre_start` hooks that may overwrite `auth.json`, restore the parent file before Docker starts.
- Container `composer install` uses the parent Nova account.

## Changes

- `ReviewCommand` overwrite-copies parent `auth.json` before `up` (line 466).
- `SetupOrchestrator` now accepts `worktreeParentRepo` parameter and restores parent `auth.json` between Phase 1 (pre_start) and Phase 2 (Docker start).
- `UpCommand` auto-detects worktrees and passes parent repo path to `SetupOrchestrator`.
- `ReviewCommand` finally block still restores `auth.json` for cleanup after `up` completes.

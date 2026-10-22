# GIG-4072: Restore parent auth.json after pre_start before compose up

## Summary

Copy the parent `auth.json` over the worktree file before `up`. That is the whole fix.

## Requirements

- A leftover worktree `auth.json` is replaced by the parent file before `up`.
- Container `composer install` uses the parent Nova account.

## Changes

- `ReviewCommand` overwrite-copies parent `auth.json` before `up`.
- Removed the `WorktreeComposerAuthSeeder` / restore-from-cwd layer (Bugbot: cwd sniffing was the wrong place).

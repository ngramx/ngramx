# GIG-4072: Restore parent auth.json after pre_start before compose up

## Summary

GIG-4063 restored parent `auth.json` after the whole worktree `up`. Apollo's entrypoint runs `composer install` during that `up`, so `.env` Nova credentials were what Composer used. Restore the parent file after `pre_start` and before Docker starts, and overwrite leftover worktree `auth.json` before `up`.

## Requirements

- A leftover worktree `auth.json` from a previous `.env` SET is replaced by the parent file before `up`.
- After host `pre_start` rewrites `auth.json`, the parent file is restored before `docker compose up`.
- Unit tests cover the seeder and the setup ordering.

## Changes

- Added `WorktreeComposerAuthSeeder` and restore it from cwd after `pre_start`.
- `ReviewCommand` overwrite-seeds parent `auth.json` before `up`.

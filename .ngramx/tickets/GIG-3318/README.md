# GIG-3318: Raise MySQL postmaclone restore timeout past one hour

## Summary

Hydra restore now gets past DEFINER (v2.47.2) and dies at the hardcoded 3600s mysql timeout.

## Requirements

Raise `MysqlRestorer` timeout to at least 3 hours. `fix:` commit for a release.

## Changes

- Ticket folder created.
- `MysqlRestorer::RESTORE_TIMEOUT_SECONDS` is 10800 (3 hours).
- PR: https://github.com/ngramx/ngramx/pull/33

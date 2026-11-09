# GIG-4525: Remove 600s timeout from MySQL postmaclone wipe

## Summary

Hydra produce died wiping the idle ping-pong slot because `DatabaseWiper` capped MySQL `DROP TABLE` at 600s. Restore already has no process timeout; wipe must match.

## Requirements

- MySQL/MariaDB wipe must not die at 600s while dropping idle Hydra tables.
- GitHub job timeout remains the outer limit.
- Consumers on the live slot stay untouched.

## Changes

- Ticket folder created.
- `DatabaseWiper::MYSQL_WIPE_TIMEOUT_SECONDS` is null, same as `MysqlRestorer::RESTORE_TIMEOUT_SECONDS`.
- PR: https://github.com/ngramx/ngramx/pull/66

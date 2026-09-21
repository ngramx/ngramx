# GIG-3323: Resolve Hydra dump from older stamp folders

## Summary

S3KeyResolver only looked in the newest `database-backups/all/<stamp>/` folder. Hydra is not in every nightly Forge stamp, so produce failed immediately.

## Requirements

Walk dated folders newest-first and use the newest folder that contains the named dump file.

## Changes

- Ticket folder created.
- `S3KeyResolver` walks older stamps when the newest folder lacks the file.
- PR: https://github.com/ngramx/ngramx/pull/34

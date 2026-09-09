# GIG-3079: Implement backup monitoring alert for database backups

## Summary

Nightly check that Forge/Spaces database backups are still landing. If the newest object in the raw backup prefix is older than 24 hours, produce fails so GitHub Actions goes red (email via GHA notifications). The last anonymized artifact and shared hosted DB stay as they were.

## Requirements

- Run nightly (integrate into the postmaclone factory produce job).
- Inspect the backup bucket for the most recently modified file.
- If nothing has been modified in the last 24 hours, fail produce with that message (GHA failure + email; no Sentry).
- Message: `Database backups do not appear to be running — last backup file modified X days ago`.
- Context: Forge backup config was deleted (not merely disabled) from 6 August; no alert fired.

## Changes

- `BackupFreshnessChecker` lists dated folders under the raw backup prefix and fails produce when the newest object is older than 24 hours.

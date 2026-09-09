# GIG-3079: Implement backup monitoring alert for database backups

## Summary

Nightly check that Forge/Spaces database backups are still landing. If the newest object in the backup bucket is older than 24 hours, raise an urgent Sentry error so a silent config deletion cannot go unnoticed again.

## Requirements

- Run nightly (integrate into the postmaclone factory produce job).
- Inspect the backup bucket for the most recently modified file.
- If nothing has been modified in the last 24 hours, raise a Sentry error flagged as urgent.
- Message: `Database backups do not appear to be running — last backup file modified X days ago`.
- Context: Forge backup config was deleted (not merely disabled) from 6 August; no alert fired.

## Changes

- Ticket folder created; implementation not started.

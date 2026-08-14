# COR-297: Schedule Nightly Postmaclone Factory Produce For Anonymized Artifacts

## Summary

Run `ngramx postmaclone produce` on a schedule in lon1 so Hydra (59GB) and then Earl Kendrick get anonymized prebuilts in a separate Spaces bucket. Consumers restore those artifacts instead of downloading raw Forge dumps.

## Requirements

- In-region scheduled job (same DO region as Spaces) with scratch Postgres.
- Factory `postmaclone.yml` (not an app checkout).
- Separate read (raw backups) vs write (anonymized bucket) credentials via 1Password.
- First dataset: Hydra 59GB. Then Earl Kendrick.
- Publish `latest.json` next to the artifact.
- Do not write into `database-backups/all/`.
- Job failure must be visible so stale prebuilts are not served past `max_age_hours`.

## Changes

- Ticket folder created.

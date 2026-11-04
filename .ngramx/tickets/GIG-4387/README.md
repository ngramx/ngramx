# GIG-4387: Ping-pong Hydra postmaclone slots instead of reloading shared

## Summary

Stop wiping and reloading the live shared database after produce. Build in the idle slot, flip `latest.json` `shared_database`, and leave the previous copy until the next run.

## Requirements

- `shared.ping_pong: true` on factory datasets that can hold two copies.
- Produce wipes only the idle slot, then flips the pointer after the artifact is uploaded.
- Connect honours `latest.json` `shared_database`.
- Never wipe the live slot. No second restore.

## Changes

- Ticket folder created.
- Added `shared.ping_pong`, SharedSlotPicker, produce idle-slot build + latest.json flip, connect reads `shared_database`.
- Bugbot: do not write latest.json with size 0 before dump; flip after upload. `--from-artifact` keeps an existing `shared_database` instead of restoring the other slot. Idle refresh copies host/port/url from `target.remote`. PHPStan nullsafe on slot names.
- Devin: fence `postmaclone_anon` off idle before raw restore; GRANT only after anonymize. Scratch needs GRANT OPTION; do not permanently cross-GRANT anon.

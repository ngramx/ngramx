# GIG-3363: Update ngramx to automate connection to anon databases

## Summary

Adds `postmaclone connect` / `disconnect`, SSH tunnel or direct egress to the shared nightly anonymized DB, and wiring through `ngramx up --anon`, review/worktree, and doctor.

## Requirements

Automate developer connection to shared anonymized hosted databases (COR-274 / postmaclone.shared).

## Changes

- Postmaclone connect service, SSH tunnel, compose override, lock file, Op session ensurer.
- `ngramx up --anon`, auto-connect on Codabyte trusted egress, `down` disconnect when started with `--anon`.
- Review fixes: Codabyte auto-connect bug, `--anon`/`--postmaclone` guard, docs and tests.

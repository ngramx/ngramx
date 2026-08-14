# COR-281: Ship Postmaclone CLI For Ephemeral Anonymized Prod Clones

## Summary

Land Cam's Postmaclone work from `origin/postmaclone` as the COR-281 solution: `ngramx postmaclone` creates short-lived anonymized database clones from Spaces backups, local dumps, or dump-only connection strings. We are not building a shared remote prod copy.

## Requirements

- Merge Postmaclone onto a `cor-281-*` branch against current `main`.
- Ship `ngramx postmaclone` (create / down / status / doctor / produce) and `ngramx up --postmaclone`.
- Keep source dumps read-only; credentials via 1Password `op://` refs.
- Opt-in column anonymisation; skip only when restoring an already-anonymized factory prebuilt.
- Document the security model in the README.
- Unit tests for config, doctor, dump flags, and restore sanitisation.
- Out of scope: factory schedule (COR-297), per-app / agent wiring (COR-274), Hydra connection-string dump unless cheap.

## Changes

- Ticket folder created.
- Branch created from `origin/main` and Cam's `origin/postmaclone` merged in.
- Merge conflicts in `UpCommand`, `EtcHostsHint`, and hosts-hint tests resolved in favour of main's lock-write-on-ready and `*.localhost` probe behaviour.
- Regenerated `composer.lock` so it only adds `fakerphp/faker` instead of rewriting the whole lockfile.
- Left the ngramx CLI's own `ngramx.yml` without a dogfood `postmaclone:` block (examples stay in `ngramx.example.yml`).
- Fixed Windows absolute-path handling (`C:\...` was treated as relative) in config load, compose paths, dump resolve, and factory config.
- Doctor no longer fails when host `psql` is missing and the target is Docker/auto; dump-client detection uses `ExecutableFinder` instead of `sh -c command -v`.
- Adjusted Postmaclone tests so Windows paths can be embedded in YAML.

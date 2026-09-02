# COR-297: Nightly Produce And Refresh Shared Hosted Anonymized Databases

## Summary

Nightly factory produce publishes anonymized artifacts to `weathered-brook-anonymized-backups` and refreshes a shared hosted database on the DO Managed Database cluster. GitHub Actions replaces the lon1 droplet for scheduling; scratch restore/anonymize runs on the cluster (not the ~14GB GHA runner disk).

## Requirements

- Scheduled job publishes fresh anonymized artifact + `latest.json` for Earl Kendrick (pioneer).
- Artifact restored into shared hosted EK Postgres developers can connect to without local download.
- Artifacts in `weathered-brook-anonymized-backups`, separate read/write credentials via 1Password.
- Local `ngramx postmaclone` prebuilt escape hatch unchanged.
- Job failure visible in GitHub Actions.

## Changes

- Added factory `shared` config — produce refreshes long-lived hosted DB after publishing artifact.
- Split connection config: host + database in factory YAML; shared `postmaclone-scratch` / `postmaclone-anon` credentials in 1Password (one item pair reused across projects).
- Added consumer `postmaclone.shared` config schema (hosted DB + max_age_hours for COR-274).
- `target.provider: remote` scratch DB on DO cluster for GHA (Hydra-scale restores never touch runner disk).
- `RemoteDbTarget.destroy` wipes scratch DB after produce.
- New `ngramx init-postmaclone-workflow` — distributes scheduled GHA workflow template.
- Updated `postmaclone.example.yml`, README, `ngramx.example.yml`.
- Earl Kendrick `ngramx.yml`: prebuilt + shared hosted DB refs.
- Shared DB password rotation every 7 days (default): `ALTER ROLE` + `op item edit` on `shared.credentials.password`; tracked in `{anonymized-bucket}/_postmaclone/credential-rotations.json` keyed by op ref (credential-scoped, not per dataset).
- Per-dataset `latest.json` still echoes `shared_password_rotated_at`; legacy value used once if central state is empty.
- Legacy full `url` op:// refs still supported for backward compatibility.
- Scratch/anon wipe drops only `public` objects owned by the connected role (not `DROP SCHEMA public`). Dump sanitizer strips CREATE/DROP/ALTER SCHEMA so restore does not require schema ownership.
- Local EK produce succeeded (artifact + shared anon refresh). Factory workflow and config pushed to factory `main`.

## Ops still required (human)

- Create 1Password items: `postmaclone-scratch`, `postmaclone-anon` (`username` + `password` fields only).
- Grant the 1Password service account **write** on those items (rotation updates op:// password field).
- Replace placeholder cluster `host` in postmaclone-factory `postmaclone.yml` with DO console hostname.
- Provision scratch + shared databases on existing DO Postgres cluster.
  - Scratch: `earl_kendrick_core_prod_scratch` ✓
  - Shared: `earl_kendrick_core_prod_anon` ✓
- Updated postmaclone-factory `postmaclone.yml` (remote scratch + shared) and GHA workflow.
- Run `ngramx init-postmaclone-workflow` in postmaclone-factory repo; set `OP_SERVICE_ACCOUNT_TOKEN` secret.
- Merge/release ngramx COR-297 before GHA can use new produce features.

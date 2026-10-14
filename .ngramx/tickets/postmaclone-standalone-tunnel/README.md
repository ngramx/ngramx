# postmaclone-standalone-tunnel: Standalone SSH tunnel to shared anonymized DBs

## Summary

Adds `ngramx postmaclone tunnel` so developers can reach the shared MySQL/Postgres anonymized clusters via Codabyte SSH without a project `ngramx.yml` or `.env` changes.

## Requirements

- `--mysql` / `--psql` to pick cluster
- Factory `engines.*.anon` credentials (home config or factory repo)
- Optional `--local-port`, `--database`, `--foreground`, `--tunnel-disconnect`

## Changes

- `PostmacloneTunnelService`, home-dir tunnel locks, factory `tunnel_profile` validation
- `--local-port` for explicit local bind when defaults are busy

# GIG-4063: Export declared secrets into host pre_start commands

## Summary

Ngramx validates secrets from `.env` but does not export them into host `pre_start` commands, so Nova Composer auth fails in worktrees. Export the secrets and seed parent `auth.json`.

## Requirements

- A `pre_start` command that references `$NOVA_LICENSE_KEY` succeeds when that value lives only in `.env`.
- A new worktree receives the parent `auth.json` when present.
- After `up`, the worktree `auth.json` matches the parent if the parent file exists.
- Unit tests cover secret resolution, host-env merge, and orchestrator wiring.

## Changes

- `SecretsValidator::environment()` resolves declared secrets (shell first, then `.env`).
- Host `pre_start` commands receive those values in their process environment.
- Worktrees copy parent `auth.json` before `up` and restore it afterwards so a `composer config` SET cannot replace a working login.
- Opened https://github.com/ngramx/ngramx/pull/57

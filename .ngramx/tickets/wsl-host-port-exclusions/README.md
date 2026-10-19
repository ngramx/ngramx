# wsl-host-port-exclusions: Remap host ports Docker Desktop cannot publish on WSL

## Summary

`ngramx worktree` / `nwt` fails on Docker Desktop + WSL when a compose host
port (or that port plus a worktree offset) lands in a Windows Hyper-V excluded
range. Compose then dies with `/forwards/expose returned unexpected status: 500`.
Ngramx now treats those ports as unpublishable, remaps them even when a global
offset is in play, and retries compose-up once after a publish failure.

## Requirements

Reported from `nwt gig-3816` on Hydra. The app service publishes `56306:3306`;
the worktree offset of `8200` produced `64506`, which sits in Windows' reserved
range `64480–64579`. Nothing was listening — the WSL port proxy just returns 500.

This is common on Hydra because every worktree offset in `8000–9000` pushes
`56306` into `64306–65306`, which Hyper-V has almost entirely reserved.

Requirements:

- Treat Windows excluded port ranges (and the WSL ephemeral band 49152–65535)
  as unpublishable when choosing host ports.
- Apply a per-port remap on top of a global `--port-offset` (worktrees always
  use one; the remap was previously skipped in that mode).
- If compose still fails with `/forwards/expose` 500 / ports-not-available,
  remap the named port, rewrite the override, and retry once.

## Changes

- PR: https://github.com/ngramx/ngramx/pull/53
- `src/Docker/WindowsExcludedPortRanges.php` — parse `netsh` excluded ranges.
- `src/Docker/PortPublishFailure.php` — extract refused host ports from compose
  / Docker Desktop errors.
- `src/Docker/PortOffsetManager.php` — excluded/ephemeral ports are taken;
  replacements fold out of the 64k band instead of stepping further into it.
- `src/Docker/ComposeOverrideGenerator.php` — apply `port_map` after the
  global offset.
- `src/Command/UpCommand.php` — resolve conflicts on post-offset ports; recover
  from a publish 500 by remapping and rewriting the override.
- `src/Command/ReviewCommand.php` — worktrees seed the same remap so a skipped
  `up` still writes a publishable override.
- `src/Orchestrator/SetupOrchestrator.php` — retry compose-up once after a
  publish remapping.
- Unit tests for the parser, excluded ranges, offset+map, worktree remap, and
  compose-up retry.
- Stub `resolvePortConflicts` via `expects()` so PHPStan 8.2/8.3 CI accepts the
  UpCommand mock (direct `->method()` is not on the concrete type).

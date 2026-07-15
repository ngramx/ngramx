# Bonus: localise completion.json test URLs onto the live review environment

## Summary

`ngramx review [ticket]` (and `ngramx worktree`) already rewrote the scheme/host of `completion.json` test URLs onto the environment being spun up, but the rewrite missed the per-port conflict remap introduced by COR-267: when `up` moved an individual host port (recorded as `port_map` in `.ngramx.lock`), the printed application URL and localised deep-links still pointed at the original port.

## Requirements

When `ngramx review [xxx]` outputs the test URLs from `completion.json`, the host, scheme and port must all be replaced with the correct ones for the worktree/environment which is spun up — including when the environment's ports were individually remapped rather than offset.

## Changes

- `src/Command/ReviewCommand.php`:
  - In-place review: the environment URL now applies the lock file's `port_map` (via `UrlPortOffset::applyMap`) on top of the port offset before test URLs are localised onto it.
  - Worktree review: the resolved worktree URL now applies the worktree lock's `port_map` too, so the advertised URL, the seeded `APP_URL`, and the localised completion deep-links all follow the web port wherever conflict resolution moved it.
- Tests: `ReviewCommandTest` gains coverage for both modes with a `port_map` in the lock file.

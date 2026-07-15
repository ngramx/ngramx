# COR-267: Detect and resolve port conflicts during `ngramx up`

## Summary

`ngramx up` now detects host port conflicts on the exact ports the compose file wants to bind and remaps only the conflicted ports to nearby free ones. Non-conflicted ports and container names are never touched, and the output announces every remap.

## Requirements

- Conflicts resolved automatically during a normal `ngramx up`, without manual intervention.
- Only the conflicted ports move (a redis conflict must not push web off port 80).
- Parent container names remain unchanged.
- Output makes it clear when conflicts were detected and resolved.

## Changes

- `src/Docker/PortOffsetManager.php` — new `resolvePortConflicts()` returning a per-port map (conflicted base port → free replacement, stepping +100 and skipping used/wanted/claimed ports). Used-port lookup is now instance-cached and injectable for tests.
- `src/Docker/PortMapping.php` — new `replaceHostPort()` (handles interpolated defaults like `${VAR:-5432}`).
- `src/Docker/ComposeOverrideGenerator.php` — `generate()` accepts a `portMap` and rewrites only mapped host ports via `applyPortMap()`; container names untouched.
- `src/Config/LockFileData.php` / `LockFile.php` — lock records the `port_map` (int keys restored on read) so later commands stay accurate.
- `src/Http/UrlPortOffset.php` — new `applyMap()` swaps a URL's effective port when it appears in the remap.
- `src/Orchestrator/SetupOrchestrator.php` — post-start app URL probe follows a remapped web port.
- `src/Command/UpCommand.php` — default runs (offset 0, host mapping on) scan for conflicts, announce the remap, pass it to the override generator/setup/lock, and print the remapped URL.
- `src/Command/ShowUrlCommand.php` — applies the lock's port map to the primary service port.
- Tests across `PortOffsetManagerTest`, `ComposeOverrideGeneratorTest`, `UrlPortOffsetTest`, `LockFileTest`, `UpCommandTest`, `ShowUrlCommandTest`.

# stale-bind-mounts: Self-heal stale Docker Desktop bind mounts

## Summary

`ngramx up` / `review` / `rebuild` could fail with an unreadable OCI runtime
error when Docker Desktop's WSL bind-mount staging area held a mount pointing at
a file that no longer existed. ngramx now clears those staged corpses before it
starts containers, retries once when the engine reports one anyway, and removes
them when a worktree is torn down.

## Requirements

Reported from a real failure: after `ngramx review 3084` recreated containers to
apply port offsets, the `app` container refused to start with

```
error mounting "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/46efeb10…"
to rootfs at "/usr/local/etc/php/conf.d/local.ini": no such file or directory
```

Root cause, confirmed on the host: Docker Desktop stages each WSL bind mount at
`/mnt/wsl/docker-desktop-bind-mounts/<distro>/<sha256 of the host path>`. A
single-file mount pins one inode, so a commit that rewrote
`docker/php/local.ini` replaced the inode and left the staged mount pointing at
a deleted one — `/proc/self/mountinfo` reported its root as `…//deleted`, and the
staged file still held the old 415-byte content while the working tree had the
current 566-byte version. The failure only surfaces when containers are
recreated, which is why it looked like the CLI update caused it.

Thirteen such mounts were present on that host, most of them left behind by
removed ngramx worktrees. Because the staging path is a hash of the host path, a
worktree recreated at the same path inherits the dead mount.

Requirements:

- Clear the dead staged mounts for a project before starting its containers.
- Recover from the failure when it happens anyway, using the paths the engine
  named, and retry the start once.
- Clear a worktree's staged mounts when the worktree is removed.
- Never hang a headless run on a sudo password prompt — print the command
  instead, so Codabyte and CI degrade gracefully.

## Changes

- `src/Docker/StaleBindMount.php`, `StaleBindMountReason.php` — value objects
  describing one dead staged mount and why it is considered dead.
- `src/Docker/StaleBindMountSweeper.php` — parses `/proc/self/mountinfo`, finds
  staged mounts that are dead (deleted inode, or missing host path), scopes them
  to a project/worktree root, matches the ones an engine error names, and
  unmounts them (direct as root → `sudo -n` → interactive `sudo` with a TTY →
  print the command).
- `src/Orchestrator/SetupOrchestrator.php` — sweep before `docker compose up`;
  on failure, clear what the engine named and retry the start once.
- `src/Command/RebuildCommand.php` — same sweep + retry around
  `up --build`.
- `src/Command/ReviewCommand.php` — sweep a worktree's staged mounts once its
  directory has been removed.
- `QUICK_REFERENCE.md` — troubleshooting entry for the error, including the
  manual `umount` and the advice to mount directories rather than single files.
- Tests: `tests/Unit/Docker/StaleBindMountSweeperTest.php` plus three
  orchestrator tests covering the sweep, the retry, and the rethrow.

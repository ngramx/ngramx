# GIG-3795: Add configurable post-review editor hook to ngramx review command

## Summary

`ngramx review -c` always opens Cursor. Developers should be able to launch any editor after a review (Zed, VS Code, Cursor) from config, and override that choice for a single run.

## Requirements

- Allow users to configure which command runs after `ngramx review` completes (e.g. `zed`, `cursor`, `code`).
- Support both a persistent config option and a CLI flag override.
- Default to the current Cursor behaviour if nothing is configured.

## Changes

- Added an `onReview` hook event, loaded from `~/.ngramx.yaml`, `.ngramx/config.yaml`, or `ngramx.yml` (same precedence as the other hooks).
- Added `ngramx review --editor <command>`. It implies `--worktree` and overrides `onReview` for that run. Bare names `zed`, `code`, `vscode`, and `cursor` expand to a window command; `cursor` still uses the built-in launcher.
- `--cursor` / `-c` still opens Cursor when `onReview` is unset. When `onReview` is set, `-c` runs that hook instead of Cursor.
- `onReview` runs any shell command. `{path}` and `$NGRAMX_PATH` are the worktree path, so a custom launcher (for example Zed on Windows from WSL) can be stored in `~/.ngramx.yaml`. A full shell command passed to `--editor` is left as written.

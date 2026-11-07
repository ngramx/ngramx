#!/usr/bin/env bash
#
# Thin wrapper for backwards-compatible project .claude/settings.json entries.
# Prefer configuring once in ~/.claude/settings.json:
#   "command": "ngramx claude-worktree create"
#
set -euo pipefail
exec ngramx claude-worktree create

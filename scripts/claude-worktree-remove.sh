#!/usr/bin/env bash
#
# Claude Code WorktreeRemove hook — remove worktrees under .ngramx/worktrees/.
# Does not stop Docker environments; use ngramx worktree --cleanup for that.
#
set -euo pipefail

HOOK_INPUT="$(cat)"
WORKTREE_PATH="$(printf '%s' "$HOOK_INPUT" | jq -r '.worktree_path // empty')"

if [ -z "$WORKTREE_PATH" ]; then
    echo "Error: hook input missing .worktree_path" >&2
    exit 1
fi

if ! printf '%s' "$WORKTREE_PATH" | grep -q '/.ngramx/worktrees/'; then
    echo "Error: worktree '$WORKTREE_PATH' is not under .ngramx/worktrees/" >&2
    exit 1
fi

REPO_ROOT="$(printf '%s' "$WORKTREE_PATH" | sed 's|/.ngramx/worktrees/.*||')"

if [ ! -d "$REPO_ROOT/.git" ]; then
    echo "Error: could not resolve repository root from '$WORKTREE_PATH'" >&2
    exit 1
fi

if [ ! -d "$WORKTREE_PATH" ]; then
    git -C "$REPO_ROOT" worktree prune >/dev/null 2>&1 || true
    exit 0
fi

git -C "$REPO_ROOT" worktree remove --force "$WORKTREE_PATH" >/dev/null 2>&1 || {
    rm -rf "$WORKTREE_PATH"
    git -C "$REPO_ROOT" worktree prune >/dev/null 2>&1 || true
}

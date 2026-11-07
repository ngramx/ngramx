#!/usr/bin/env bash
#
# Claude Code WorktreeCreate hook — create worktrees under .ngramx/worktrees/.
# Configured in .claude/settings.json (see docs/claude-code-integration.md).
# Prints the absolute worktree path on stdout. Does not run ngramx up.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

HOOK_INPUT="$(cat)"
NAME="$(printf '%s' "$HOOK_INPUT" | jq -r '.name // empty')"

if [ -z "$NAME" ]; then
    echo "Error: hook input missing .name" >&2
    exit 1
fi

SANITIZED_NAME="$(printf '%s' "$NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9-]/-/g' | sed 's/--*/-/g' | sed 's/^-\+\|-\+$//g')"

if printf '%s' "$SANITIZED_NAME" | grep -qE '^[a-z]+-[0-9]+'; then
    TICKET_SLUG="$(printf '%s' "$SANITIZED_NAME" | sed -E 's/^([a-z]+-[0-9]+).*/\1/')"
    REPO_NAME="$(basename "$REPO_ROOT" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9-]/-/g' | sed 's/--*/-/g' | sed 's/^-\+\|-\+$//g')"
    FOLDER_NAME="${TICKET_SLUG}-${REPO_NAME}"
    NEW_BRANCH="$TICKET_SLUG"
else
    FOLDER_NAME="$SANITIZED_NAME"
    NEW_BRANCH="worktree-${SANITIZED_NAME}"
fi

WORKTREE_PATH="$REPO_ROOT/.ngramx/worktrees/$FOLDER_NAME"
mkdir -p "$REPO_ROOT/.ngramx/worktrees"

if [ -d "$WORKTREE_PATH" ]; then
    echo "$WORKTREE_PATH"
    exit 0
fi

if git -C "$REPO_ROOT" rev-parse --verify "refs/heads/$NEW_BRANCH" >/dev/null 2>&1; then
    git -C "$REPO_ROOT" -c core.hooksPath=/dev/null worktree add "$WORKTREE_PATH" "$NEW_BRANCH" >/dev/null 2>&1
else
    DEFAULT_BRANCH="main"
    if ! git -C "$REPO_ROOT" rev-parse --verify "origin/$DEFAULT_BRANCH" >/dev/null 2>&1; then
        DEFAULT_BRANCH="master"
    fi
    git -C "$REPO_ROOT" -c core.hooksPath=/dev/null worktree add -b "$NEW_BRANCH" "$WORKTREE_PATH" "origin/$DEFAULT_BRANCH" >/dev/null 2>&1
fi

if ! grep -qxF '/.ngramx/worktrees/' "$REPO_ROOT/.git/info/exclude" 2>/dev/null; then
    mkdir -p "$REPO_ROOT/.git/info"
    echo '/.ngramx/worktrees/' >>"$REPO_ROOT/.git/info/exclude"
fi

echo "$WORKTREE_PATH"

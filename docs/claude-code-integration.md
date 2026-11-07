# Claude Code integration

Optional hooks redirect Claude Code worktree creation into `.ngramx/worktrees/` instead of `.claude/worktrees/`. Environment startup stays explicit: run `ngramx worktree` — hooks never call `ngramx up`.

See `.ngramx/tickets/GIG-4475/README.md` for background.

## Hook configuration

Add to `.claude/settings.json` in the repository root:

```json
{
  "hooks": {
    "WorktreeCreate": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "./scripts/claude-worktree-create.sh"
          }
        ]
      }
    ],
    "WorktreeRemove": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "./scripts/claude-worktree-remove.sh"
          }
        ]
      }
    ]
  }
}
```

Do not symlink `.claude/worktrees` to `.ngramx/worktrees` — Claude Code refuses symlinked worktree paths.

## Adoption

When a branch is already checked out under `.claude/worktrees/`, `ngramx worktree <ticket>` offers to adopt it (move into `.ngramx/worktrees/` with `git worktree move`).

With no ticket argument on an integration branch, `ngramx worktree` shows a picker of managed worktrees, Claude worktrees, and `.ngramx/tickets/*` entries.

## Cleanup

Hook removal does not stop Docker. Use `ngramx worktree --cleanup` to tear down environments and remove managed worktrees.

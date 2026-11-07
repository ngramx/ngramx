# Claude Code integration

Optional hooks redirect Claude Code worktree creation into `.ngramx/worktrees/` instead of `.claude/worktrees/`. Environment startup stays explicit: run `ngramx worktree` — hooks never call `ngramx up`.

See `.ngramx/tickets/GIG-4475/README.md` for background.

## Hook configuration (recommended)

Configure once on the machine in `~/.claude/settings.json`. The hook commands ship with the ngramx CLI — no per-project scripts required:

```json
{
  "hooks": {
    "WorktreeCreate": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "ngramx claude-worktree create"
          }
        ]
      }
    ],
    "WorktreeRemove": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "ngramx claude-worktree remove"
          }
        ]
      }
    ]
  }
}
```

Restart Claude Code / start a new session so the hooks load.

`ngramx claude-worktree create` reads Claude’s JSON from stdin (`name`, `cwd`), creates a git worktree under the repo’s `.ngramx/worktrees/`, and prints the absolute path on stdout. Ticket-like names (`gig-4500-…`) use ngramx folder naming (`gig-4500-<repo>`).

Do not symlink `.claude/worktrees` to `.ngramx/worktrees` — Claude Code refuses symlinked worktree paths.

### Sanity check

```bash
echo '{"name":"gig-4500-hook-demo","cwd":"'"$(pwd)"'"}' | ngramx claude-worktree create
```

### Project-level scripts (optional)

`scripts/claude-worktree-create.sh` and `scripts/claude-worktree-remove.sh` are thin wrappers around the same CLI commands for repos that already wire project `.claude/settings.json`. Prefer the user-level config above so every ngramx project picks this up after an upgrade.

## Adoption

When a branch is already checked out under `.claude/worktrees/`, `ngramx worktree <ticket>` offers to adopt it (move into `.ngramx/worktrees/` with `git worktree move`).

With no ticket argument on an integration branch, `ngramx worktree` shows a picker of managed worktrees, Claude worktrees, and `.ngramx/tickets/*` entries.

## Cleanup

Hook removal does not stop Docker. Use `ngramx worktree --cleanup` to tear down environments and remove managed worktrees.

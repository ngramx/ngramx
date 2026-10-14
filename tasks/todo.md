# GIG-3191: Hide ngramx-generated agent files from git

## Plan

- [x] Fetch Linear ticket, move to In Progress, branch from origin/main
- [x] Confirm approach: ngramx exclude + skip-worktree (not per-project .gitignore PRs)
- [x] GitExcludeManager.hideFromGit()
- [x] AgentsSyncOrchestrator hides configured targets and managed skills
- [x] Tests for untracked exclude, tracked skip-worktree, orchestrator
- [x] README note
- [x] Run unit tests

## Review

- `hideFromGit` uses `.git/info/exclude` for untracked copies and `skip-worktree` for already-tracked ones. Committed `.gitignore` would not help — git never ignores a tracked file, and adding one would dirty every project.
- Sync runs at the start of every ngramx command, so the first `ngramx worktree` after upgrade hides the files before git worktree add.
- Custom notes above the managed markers stay in the last committed copy. To commit a prefix edit: `git update-index --no-skip-worktree AGENTS.md`.

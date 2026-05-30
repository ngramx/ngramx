# Agents templates — sub-ticket / parent-branch variant (archived)

Snapshot of `templates/agents/` as of **2026-05-10**, before the parent-ticket /
sub-issue / pre-created-parent-branch workflow was removed from the active
agent instructions.

This directory is **not** read by any Ngramx CLI command. It is intentionally
located outside `templates/` so it is excluded from the Phar build (`box.json`
ships only `src/` and `templates/`). It exists purely as an archive in case the
parent-branch / sub-issue workflow is revived later (e.g. once the auto-merge
race conditions and branch-targeting are smoothed out further).

## What it contains

The `05-ticket-workflow.md` and `06-linear-ticket-creation.md` files in this
folder describe the previous workflow:

- **Parent ticket + sub-issue decomposition.** Substantial work was broken
  into a parent ticket (`human-review`) and 3–7 sub-issues (`auto-merge`).
- **Pre-created parent branch on `origin`.** Before any sub-issue PRs were
  opened, the agent pushed an empty branch from `origin/main` named after
  Linear's `gitBranchName` for the parent. Sub-issue PRs targeted that branch.
- **Routing labels (`auto-merge` / `human-review`).** Every PR was required to
  carry exactly one. `auto-merge` PRs merged into the parent branch when CI
  was green; `human-review` PRs went to a human.
- **Cross-parent and inter-sub-issue dependency graphs** encoded via Linear's
  `blocks` / `blockedBy` relations to feed an automation that delegated newly
  unblocked tickets to Cursor.

The active templates in `templates/agents/` no longer require any of this —
agents create a single branch per ticket and target the project's default
integration branch directly.

## Why it was archived

The pre-created parent-branch dance and the strict routing-label discipline
introduced more failure modes than they solved at the team's current scale
(missed parent branches, sub-issue PRs silently retargeted to `main`, merge
ordering surprises). The sub-issue auto-merge into a parent branch can be
reintroduced cleanly once the surrounding tooling makes the foot-guns harder
to hit.

## Restoring

To revive this workflow, copy the relevant files back over `templates/agents/`,
re-add any `auto-merge` / `human-review` label conventions to the consumer
projects, and ensure the parent-branch creation step is genuinely automated
rather than relying on the agent to remember it.

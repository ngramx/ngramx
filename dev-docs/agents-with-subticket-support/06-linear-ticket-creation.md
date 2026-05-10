# Linear Ticket Conventions

Guidance for creating Linear tickets from this project (via Cursor, Claude Code, or any tool using the Linear MCP). Follow these conventions so tickets created here stay consistent with the Gigabyte Planning Agent.

## Teams

- **Gigabyte (GIG)** — client work
- **Cortex (COR)** — internal Gigabyte tooling
- **Verafind (VF)** — managed on behalf of Verafind staff
- **GiftACo (GIFT)** — Rob's side project

Pick the team that matches the work.

## Using the codebase

If you have access to the codebase when creating tickets, do whatever analysis you think is useful — read relevant files, check existing patterns, understand dependencies — so the tickets you draft reflect the real shape of the work.

## Conventions

**Assignees.** Always assign to the user who requested the work, unless they specify otherwise.

**Priorities.** `1` Urgent, `2` High, `3` Medium, `4` Low. Default to `3` if unclear.

**Estimates.** Use only `0, 1, 2, 4, 8, 16`. Most tickets are `0–4`. Use `8` rarely; `16` almost never — if it feels like a 16, decompose it.

**Status.** Default to `Scheduled` unless the user specifies otherwise (e.g. `Backlog`, `In Progress`, or `Today`).

**Labels.** Apply the `Client → [Client Name]` label whenever the client is identifiable. Use only labels that already exist.

Every ticket should also carry one of these two routing labels:

- **`auto-merge`** — sub-issues. The PR will auto-merge into the parent branch when CI is green; no human reviews it.
- **`human-review`** — parent tickets and standalone tickets. The PR goes to a human for review.

These labels exist in both Linear and GitHub. The Linear label flows to the linked PR via the GitHub integration; if for any reason it doesn't, apply the matching GitHub label manually when opening the PR. **Never open a PR without one of these two labels** — it's what determines whether a human ever sees it.

## Ticket description format

Every ticket description has two parts:

1. **Detailed description** — what needs to be built, technical context, edge cases, acceptance criteria.
2. **`## Goal`** — a short paragraph explaining *why* this matters: the business or strategic purpose, not the implementation.

## Milestones

Every ticket goes in a milestone. Every milestone belongs to a project.

**A milestone should always represent something deliverable to the client.** If you can't describe what the client receives at the end of it, it's not a milestone — it's an internal phase, and the work probably belongs in an existing milestone instead.

If the work doesn't fit any existing milestone on the relevant project, **stop and confirm with the user before creating anything.** Ask which milestone they meant. If it's a genuinely new milestone, work with them to define:

- Name
- Target date that makes sense for the scope
- A real description (more than a sentence) of what the milestone covers and why it exists

Don't silently invent milestones.

## Decomposing work into a parent + sub-issues

When the user asks for something substantial, plan the breakdown before creating any tickets.

**The pattern:**

- **Parent ticket** — the thing the human reviews. One deliverable from their perspective. Labelled `human-review`.
- **Sub-issues** — focused, narrow units of work, each completable in a single PR. Labelled `auto-merge`. When a sub-issue's PR is green, it auto-merges into the parent's branch. The human only reviews the consolidated parent PR.
- **Pre-create the parent branch on `origin/main`** before creating any sub-issues, so auto-merge sub-issue PRs have a target. See "Parent branch creation" above.

**Guidance:**

- Decompose along natural seams — typically design, backend, frontend, tests, integration — not arbitrary chunks.
- Aim for 3–7 sub-issues per parent. Past that, the parent is probably too big; split it into multiple parents.
- Don't decompose small work. A 1- or 2-point ticket stays a single ticket.
- Estimate each sub-issue. The parent doesn't need its own estimate — the sub-issues tell that story.
- **Show the proposed breakdown to the user before creating anything.** If the decomposition is wrong, every sub-PR will be wrong. The breakdown is the first review checkpoint. **After approval, follow this order:** create the parent ticket → push the parent branch to `origin` from `origin/main` (see "Parent branch creation" above) → then create the sub-issues. The parent branch must exist on `origin` *before* any sub-issue is created, otherwise the first auto-merge sub-issue PR will have no target branch.

## Dependencies and ordering

Before creating any tickets — whether a parent + sub-issue breakdown or a batch of standalone tickets — explicitly analyse the order in which the work needs to happen and whether any tickets depend on others.

**Why this matters.** We have an automation that watches for merged PRs: when a ticket is closed, it checks whether that ticket was blocking any others, and if so it automatically delegates the now-unblocked ticket to Cursor. If you don't record the dependencies in Linear, that automation can't see them — work either stalls or starts in the wrong order, often producing the wrong code on top of the wrong base branch.

**What to do:**

1. **Plan the order first.** As part of the breakdown you show the user, state the order tickets should be done in and which depend on which. Don't just present a flat list when the work has a real dependency graph.
2. **Encode every real dependency in Linear** using the **blocks / blocked by** issue relation. Use this relation type — not "related to" — whenever one ticket genuinely cannot start (or cannot meaningfully start) until another is merged.
3. **Avoid spurious dependencies.** Only mark a ticket as blocked when it really is blocked. Tickets that *can* be done in parallel should be left independent so the automation can fan work out.

**How to encode them with the Linear MCP.** The `save_issue` tool accepts `blocks` and `blockedBy` arrays of issue IDs/identifiers (e.g. `GIG-1601`). These are append-only — existing relations are never removed by a save, so it's safe to add relations after creation. Use `removeBlocks` / `removeBlockedBy` to undo a relation.

Patterns to be aware of:

- **Parent ↔ sub-issue is not a blocking relation.** That's the `parentId` field. Don't add a `blocks`/`blockedBy` relation between a parent and its own sub-issues.
- **Between sub-issues of the same parent.** Use `blocks` / `blockedBy` for real ordering (e.g. the backend schema sub-issue blocks the frontend sub-issue that consumes it). Sub-issues with no inter-dependencies should not block each other so they can proceed in parallel.
- **Between standalone tickets.** If ticket B requires ticket A's change to be merged before it can be started, mark A as blocking B (or equivalently, B as blocked by A). The automation will pick B up the moment A's PR merges.
- **Between parent tickets in a multi-parent plan.** When you're creating several parents at once and one parent's deliverable depends on another's, encode that dependency at the parent level — `Parent A blocks Parent B`. The automation then unblocks Parent B's work as soon as Parent A is merged. Do this in addition to whatever inter-sub-issue dependencies exist *inside* each parent.
- **Cross-parent sub-issue dependencies.** Occasionally a sub-issue under Parent B genuinely needs a specific sub-issue under Parent A to land first (not the whole of Parent A). In that case, mark the dependency directly between those two sub-issues so Parent B's work can start the moment that one piece is in, rather than waiting for all of Parent A. Use this sparingly — if most of Parent B depends on most of Parent A, a single parent-level dependency is clearer.

**Show the proposed dependency graph to the user alongside the breakdown.** Same review checkpoint as the breakdown itself: if the dependencies are wrong, the automation will hand work to Cursor in the wrong order.
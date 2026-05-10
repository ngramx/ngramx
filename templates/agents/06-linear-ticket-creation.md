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

**Estimates.** Use only `0, 1, 2, 4, 8, 16`. Most tickets are `0–4`. Use `8` rarely; `16` almost never — if it feels like a 16, decompose it into multiple separate tickets.

**Status.** Default to `Scheduled` unless the user specifies otherwise (e.g. `Backlog`, `In Progress`, or `Today`).

**Labels.** Apply the `Client → [Client Name]` label whenever the client is identifiable. Use only labels that already exist.

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

## Sizing tickets

Each ticket should be a focused, narrow unit of work that fits comfortably in a single PR. If a piece of work feels too large for one ticket, **create several separate tickets** for the natural seams (typically design, backend, frontend, tests, integration) rather than one giant ticket. Show the proposed breakdown to the user before creating anything — if the split is wrong, every PR will be wrong.

## Dependencies and ordering

Before creating a batch of tickets, explicitly analyse the order in which the work needs to happen and whether any tickets depend on others.

**Why this matters.** We have an automation that watches for merged PRs: when a ticket is closed, it checks whether that ticket was blocking any others, and if so it automatically delegates the now-unblocked ticket to Cursor. If you don't record the dependencies in Linear, that automation can't see them — work either stalls or starts in the wrong order, often producing the wrong code on top of the wrong base branch.

**What to do:**

1. **Plan the order first.** As part of the breakdown you show the user, state the order tickets should be done in and which depend on which. Don't just present a flat list when the work has a real dependency graph.
2. **Encode every real dependency in Linear** using the **blocks / blocked by** issue relation. Use this relation type — not "related to" — whenever one ticket genuinely cannot start (or cannot meaningfully start) until another is merged.
3. **Avoid spurious dependencies.** Only mark a ticket as blocked when it really is blocked. Tickets that *can* be done in parallel should be left independent so the automation can fan work out.

**How to encode them with the Linear MCP.** The `save_issue` tool accepts `blocks` and `blockedBy` arrays of issue IDs/identifiers (e.g. `GIG-1601`). These are append-only — existing relations are never removed by a save, so it's safe to add relations after creation. Use `removeBlocks` / `removeBlockedBy` to undo a relation.

**Show the proposed dependency graph to the user alongside the breakdown.** Same review checkpoint as the breakdown itself: if the dependencies are wrong, the automation will hand work to Cursor in the wrong order.

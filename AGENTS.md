# Agent instructions

Add project-specific notes for AI assistants above the Cortex-managed section.

<!-- CORTEX_AGENTS_MANAGED_BEGIN -->
---
### Cortex-managed agent rules

Cortex CLI replaces everything between the HTML comment markers below. Add project-specific instructions **above** `CORTEX_AGENTS_MANAGED_BEGIN`. Do not edit between the markers.

---

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

---

# Development environment

When this repository uses Cortex, bring up the local stack with:

```bash
cortex up
```

Use the project’s documented URL or `cortex show-url` (if available) to open the app. Prefer automated checks defined in the project (for example Playwright, PHPUnit, or npm test) over manual-only verification when they are already wired in.

---

# Architecture boundaries

If this project uses [Deptrac](https://github.com/deptrac/deptrac) (or a similar layer-enforcement tool), respect the published layer rules in `deptrac.yaml` (or equivalent).

Typical patterns for a modular Laravel-style monolith:

| Layer | May depend on | Must NOT depend on |
|-------|---------------|--------------------|
| Shared libraries | Vendor / framework | Application shell, domain core, or feature modules |
| Domain core | App shell, libraries, vendor | Feature modules |
| Feature modules | Core, app shell, libraries, vendor | Other feature modules |
| Application shell | Core, libraries, feature modules, vendor | — |

After changing `use` statements, inheritance, or moving classes between namespaces, run the project’s architecture check (for example `composer deptrac` or `php vendor/bin/deptrac analyse`) and fix violations before committing.

## Separate front-end apps

If the repo contains a PWA or mobile client in its own directory, treat it as **isolated** from server-side code unless the project explicitly documents a shared package. Integration should stay at documented boundaries (usually HTTP APIs and env-based configuration), not by importing server models or Laravel internals into the client.

---

# Database migrations

Follow **this project’s** migration conventions. When the project uses custom audit columns instead of Laravel’s `timestamps()`, mirror what existing migrations and models do.

Common patterns (adapt names and types to match the codebase):

- Prefer **one migration per logical change** and avoid editing migrations that have already shipped to shared environments.
- If a migration has never been run outside your machine, your team may still allow in-place edits—when in doubt, add a new migration instead of rewriting history.
- Keep a consistent **column order** if the project defines one (for example: primary key, foreign keys, audit fields, then data columns).

Do not assume every Cortex project uses the same audit column names; copy the conventions from nearby migrations in the same repository.

NEVER edit migrations. Always create new ones.

---

# Database table naming

Use **this project’s** established prefixes and table names. Common patterns in layered codebases:

- **Shared / core tables**: often use a stable prefix (for example `core_`) so they are easy to spot in SQL and migrations.
- **Integration or library tables**: may use a dedicated prefix (for example `libraries_`) when the project groups them that way.
- **Feature module tables**: often use a short module prefix to avoid collisions between modules.
- **Application-level models** in a single `app/Models` namespace may follow plain Laravel conventions with no prefix.

When using `foreignUuid()->constrained()`, pass the **explicit table name** if the project’s naming does not match Laravel’s default inference.

---

# Branch, PR, and ticket conventions

When starting work on a ticket (whether tracked in Linear or not), follow these conventions so that branches, PRs, and ticket context are consistent across every project.

## Branch naming

Create a branch named `[ticket-id]-[short-title-in-kebab-case]`, e.g. `gig-1599-pwa-rebranding-app-name-logo-favicon`.

- Use the lowercase ticket identifier as the prefix (e.g. `gig-1599`, `core-42`, `cor-223`).
- Follow the prefix with a short, kebab-cased description drawn from the ticket title.
- If there is no ticket, agree a short identifier with the user before branching (for example `chore-logging-tidy`).

Branch from the project's default integration branch (typically `origin/main`). One ticket = one branch = one PR.

## Ticket folder

Create `.cortex/tickets/[ticket-id]/` at the repo root with:

- `README.md` — ticket title, short summary, requirements, and running notes. Use this template:

  ```markdown
  # [ticket-id]: [ticket title]

  ## Summary

  [Brief summary of what is in this ticket.]

  ## Requirements

  [Description from the ticket / the user's brief.]

  ## Changes

  [Running log of the changes being made.]
  ```

- `ticket.json` — raw Linear ticket data (if the ticket exists in Linear). Omit for non-Linear work.
- `completion.md` - see below for details

## Pull request naming and state

When opening a pull request:

- Use the branch description as the PR title, formatted in Title Case, e.g. `GIG-1599: PWA Rebranding App Name Logo Favicon`.
- Target the project's default integration branch (typically `main`).
- **Never open draft PRs.** CI and automated review should run against the PR from the moment it is created.
- **Never add a `cursor/` (or any other tool-specific) prefix to branch names.** The branch name should describe the work, not the tool that produced it.

## PR risk and size labels

Every PR gets two labels — one for risk, one for size. These describe the PR; the merge automation reads them and decides whether to auto-merge. Your job is to classify honestly, not to decide the merge outcome.

**Risk:**

- `risk:low` — docs, tests-only, comments, dependency bumps with passing CI, isolated UI tweaks, internal tooling
- `risk:medium` — feature work or refactors that touch shared code but follow established patterns
- `risk:high` — auth, payments, migrations, infra/deployment, customer data, external integrations, anything that could cause an outage or data loss

**Size:**

- `size:small` — ≤5 files, ≤200 lines
- `size:medium` — ≤15 files, ≤600 lines
- `size:large` — anything bigger

**Rules:**

- When in doubt, escalate. Default to higher risk and larger size when uncertain — over-review is cheap, a wrong auto-merge is not.
- Include a one-line rationale in the PR description, e.g. *"Labelled `risk:low` because only touches docs and tests, no production code paths."*
- Never open a PR without both labels.

## Completion record

When work on the ticket is complete, add `.cortex/tickets/[ticket-id]/completion.md` containing at least:

- **GitHub PR:** URL of the pull request.
- **Linear ticket:** URL of the Linear ticket (omit if not applicable).
- **Click to Test:** a deep-link into the running application at the exact route that demonstrates the change. Use the local development URL (inspect the project's Docker / Cortex setup to find it). If the project requires a bypass token (for example `?bypass=hello@example.com`), include it on the URL so reviewers can open the page directly. Some projects ship more than one app (for example a web app and a PWA); link to every surface the change touches.

This record makes handover and review straightforward regardless of which AI agent or human picks up the review.

IMPORTANT: Never create draft PRs. We want the automated code review to run on the PR without intervention.
<!-- CORTEX_AGENTS_MANAGED_END -->
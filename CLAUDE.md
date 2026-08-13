<!-- NGRAMX_CLAUDE_MANAGED_BEGIN -->
# Development environment

When this repository uses Ngramx, bring up the local stack with:

```bash
ngramx up
```

Use the project’s documented URL or `ngramx show-url` (if available) to open the app. Prefer automated checks defined in the project (for example Playwright, PHPUnit, or npm test) over manual-only verification when they are already wired in.

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

Do not assume every Ngramx project uses the same audit column names; copy the conventions from nearby migrations in the same repository.

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

# Ticket and PR conventions

## Linear issue status

- On **every prompt**, if you are working on a Linear issue, verify its status is **"In Progress"**. If it is not, move it to "In Progress" using the Linear MCP. This ensures the ticket stays visible to the team regardless of which chat session picks up the work.
- After you **create or update a pull request**, do **not** change the Linear issue status yourself. CI-driven automation (the `linear-status-sync` workflow) moves the issue to **"In Review"** once checks pass, so manual status changes at PR time would fight that automation. Leave the status alone and let CI drive it.

## Ticket folder

Every ticket gets a `.ngramx/tickets/[ticket-id]/` directory at the repo root containing:

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
- `completion.json` — see below.

## Completion record

When work on the ticket is complete, add `.ngramx/tickets/[ticket-id]/completion.json`. This file **must** be valid JSON matching this exact schema — do not use markdown, do not add extra keys, do not omit required fields:

```json
{
  "title": "GIG-123: Invoice PDF Export With Custom Templates",
  "description": "Adds PDF export to the invoice detail page with support for custom Blade templates.",
  "pr_url": "https://github.com/org/repo/pull/42",
  "linear_url": "https://linear.app/team/issue/GIG-123",
  "test_urls": [
    {
      "label": "Invoice detail",
      "url": "https://app.localhost/invoices/INV-0042?bypass=hello@example.com"
    }
  ],
  "test_plan": [
    {
      "description": "PDF download from the invoice detail page",
      "status": "active",
      "steps": [
        "Navigate to an invoice with line items",
        "Click Actions → Download PDF",
        "Verify the PDF contains the correct invoice data"
      ]
    }
  ]
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `title` | Yes | PR title including ticket ID, e.g. `GIG-123: Short Title In Title Case`. |
| `description` | Yes | One or two sentences describing what the ticket resolves or what the changes are. |
| `pr_url` | Yes | Full URL of the GitHub pull request. |
| `linear_url` | No | Full URL of the Linear ticket. Set to `null` or omit for non-Linear work. |
| `test_urls` | Yes | Array of `{ "label", "url" }` objects. Deep-links into the running application at routes that demonstrate the change. Use the local development URL. Include bypass tokens if the project requires them. |
| `test_plan` | Yes | Array of test blocks. Each has `description` (one sentence), `status` (`"active"` or `"stale"`), and `steps` (ordered array of testing instructions). When creating a PR, all blocks are `"active"`. When updating a PR after review feedback, existing blocks become `"stale"` and new blocks are added as `"active"`. |

## Hard rules

- **Never open draft PRs.** CI and automated review should run against the PR from the moment it is created.
- **Never add a `cursor/` (or any other tool-specific) prefix to branch names.** The branch name should describe the work, not the tool that produced it.
- One ticket = one branch = one PR.
<!-- NGRAMX_CLAUDE_MANAGED_END -->
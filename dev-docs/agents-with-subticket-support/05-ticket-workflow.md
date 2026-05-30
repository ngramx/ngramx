# Branch, PR, and ticket conventions

When starting work on a ticket (whether tracked in Linear or not), follow these conventions so that branches, PRs, and ticket context are consistent across every project.

## Branch naming

Create a branch named `[ticket-id]-[short-title-in-kebab-case]`, e.g. `gig-1599-pwa-rebranding-app-name-logo-favicon`.

- Use the lowercase ticket identifier as the prefix (e.g. `gig-1599`, `core-42`, `cor-223`).
- Follow the prefix with a short, kebab-cased description drawn from the ticket title.
- If there is no ticket, agree a short identifier with the user before branching (for example `chore-logging-tidy`).

## Parent branch creation

When a parent ticket has — or will have — sub-issues, sub-issue PRs are labelled `auto-merge` and target the parent's branch (not `main`). For that to work, **the parent branch must exist on `origin` before the first sub-issue PR is opened.** If it doesn't, the auto-merge automation has no target and either fails or silently retargets `main`.

The agent's responsibility:

- **Immediately after creating a parent ticket in Linear** (and before creating any of its sub-issues), create a branch on `origin` named after Linear's `gitBranchName` for that parent (e.g. `gig-1907-extract-youreka-...`), branched from the latest `origin/main`.
- Use a fast-forward push directly from `origin/main` so no local checkout is required:

  ```bash
  git fetch origin
  git push origin origin/main:refs/heads/<parent-branch-name>
  ```

  Or, equivalently, locally:

  ```bash
  git fetch origin
  git branch <parent-branch-name> origin/main
  git push origin <parent-branch-name>
  ```

- The parent branch is created from `origin/main` and is **never** seeded with an empty commit — sub-issue auto-merges populate it.

When the parent branch is **not** required:

- **Standalone tickets** (no sub-issues). The implementer creates the working branch when they start the work.
- **Single-ticket "parents"** — a ticket labelled `human-review` with no sub-issues, used because the work is too small to decompose. Treat it as a standalone ticket; no pre-created branch needed.

## Ticket folder

Create `.ngramx/tickets/[ticket-id]/` at the repo root with:

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
- `plan.md` — implementation plan (may be blank initially and filled in during the approach/planning steps below).
- `specs.md` — references to related feature specs (may be blank initially).
- `assets/` — any non-text reference material supplied by the user (screenshots, mockups, diagrams).

## Pull request naming and state

When opening a pull request:

- Use the branch description as the PR title, formatted in Title Case, e.g. `GIG-1599: PWA Rebranding App Name Logo Favicon`.
- **Never open draft PRs.** CI and automated review should run against the PR from the moment it is created.

### PR base branch

Pick the right base branch for the PR — this is what auto-merge merges *into*:

- **Sub-issue PRs** (labelled `auto-merge`) **must target the parent ticket's branch on `origin`**, not `main`. The parent branch was pre-created from `origin/main` (see "Parent branch creation" above), and the consolidated work accumulates there until the parent is reviewed.

  When opening with the GitHub CLI:

  ```bash
  gh pr create --base <parent-branch-name> --head <sub-issue-branch-name> --title "..." --body "..."
  ```

  If the parent branch is missing from `origin`, **stop and create it before opening the PR.** Never silently fall back to `main` — auto-merge into `main` is blocked by the protected-branches list, so the PR will simply sit open with the automation refusing to merge it.

- **Parent ticket PRs** (labelled `human-review`) target `main` (or whatever the project's default integration branch is). This is the consolidated PR a human reviews.
- **Standalone tickets and non-Linear work** target `main` (or the project's default integration branch).

### Routing labels (required)

Every PR must carry exactly one of these two labels — it determines whether a human ever sees the PR:

- **`auto-merge`** — for sub-issue PRs. The PR auto-merges into the parent branch when CI is green; no human reviews it.
- **`human-review`** — for parent tickets, standalone tickets, and any non-Linear work. The PR goes to a human for review.

These labels exist in both Linear and GitHub. The linear should already have the routing label, if not assume human-review as the default. Apply the matching GitHub label to the PR. Never leave a PR without one of these two labels.

## Completion record

When work on the ticket is complete, add `.ngramx/tickets/[ticket-id]/completion.json`:

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

- `title` (required): PR title including ticket ID.
- `description` (required): One or two sentences describing what the changes are.
- `pr_url` (required): Full URL of the GitHub pull request.
- `linear_url` (optional): Full URL of the Linear ticket. Set to `null` or omit for non-Linear work.
- `test_urls` (required): Array of `{ "label", "url" }` objects. Deep-links into the running application. Use the local development URL and include bypass tokens if needed.
- `test_plan` (required): Array of `{ "description", "status", "steps" }` objects. Each block covers one testable flow. `status` is `"active"` or `"stale"`.

This record makes handover and review straightforward regardless of which AI agent or human picks up the review.
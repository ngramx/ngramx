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

---
name: update-pr
description: >-
  Update an existing pull request after review feedback: mark previous test
  blocks as stale, add new test blocks, update the ticket README changes log,
  and push. Use when pushing changes to a branch that already has an open PR,
  after addressing review comments, or when the user says "update the PR".
---

# Update an Existing Pull Request

This skill applies whenever you push commits to a branch that already has an open PR. Before pushing, follow these steps.

## 1. Verify the Linear ticket is still In Progress

Use the Linear MCP to check the ticket status. It should be **"In Progress"**. If it has been moved (e.g. back to "Scheduled" or "Backlog"), flag this to the user — something may be wrong. Do **not** change the status yourself.

## 2. Update completion.json

Read the existing `.ngramx/tickets/[ticket-id]/completion.json`.

### Mark existing test blocks as stale

Set `"status": "stale"` on every test block that was previously `"active"`. These represent tests the reviewer has presumably already run, so the review command will display them with strikethrough to indicate they don't need re-testing.

### Add new test blocks

For any new or changed behaviour introduced by this round of changes, add new test blocks with `"status": "active"`. Think about what the reviewer needs to test to verify the new changes — be specific and practical.

### Update other fields

- Update `description` if the scope of the PR has changed.
- Add or update `test_urls` if new pages or routes were added.
- Do **not** change `title`, `pr_url`, or `linear_url` unless they are wrong.

### Example

Before (original PR):

```json
{
  "title": "GIG-1603: Invoice PDF Export",
  "description": "Adds PDF export to the invoice detail page.",
  "pr_url": "https://github.com/org/repo/pull/247",
  "linear_url": "https://linear.app/gigabyte/issue/GIG-1603",
  "test_urls": [
    { "label": "Invoice detail", "url": "https://client.localhost/invoices/INV-0042?bypass=hello@example.com" }
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

After update (review feedback addressed):

```json
{
  "title": "GIG-1603: Invoice PDF Export",
  "description": "Adds PDF export to the invoice detail page.",
  "pr_url": "https://github.com/org/repo/pull/247",
  "linear_url": "https://linear.app/gigabyte/issue/GIG-1603",
  "test_urls": [
    { "label": "Invoice detail", "url": "https://client.localhost/invoices/INV-0042?bypass=hello@example.com" }
  ],
  "test_plan": [
    {
      "description": "PDF download from the invoice detail page",
      "status": "stale",
      "steps": [
        "Navigate to an invoice with line items",
        "Click Actions → Download PDF",
        "Verify the PDF contains the correct invoice data"
      ]
    },
    {
      "description": "PDF includes company logo (fixed after review)",
      "status": "active",
      "steps": [
        "Download a PDF from any invoice",
        "Verify the company logo appears in the header",
        "Check that the logo is not stretched or pixelated"
      ]
    }
  ]
}
```

## 3. Update the ticket README

Open `.ngramx/tickets/[ticket-id]/README.md` and append to the `## Changes` section a brief summary of what was changed in this round, e.g.:

```
- Fixed company logo not appearing in PDF header (review feedback)
- Added validation for empty invoice line items
```

## 4. Commit and push

Include the updated `completion.json` and `README.md` in the commit. Push to the existing branch — the PR will update automatically.

## Linear status — leave it alone

Do **not** change the Linear issue status. The CI-driven `linear-status-sync` workflow manages status transitions.

## When does this skill apply?

This skill should be used instead of the create-pr skill whenever **all** of these are true:

1. You are on a branch that already has an open PR (check with `gh pr view --json state -q '.state'`).
2. You are about to push new commits (e.g. after addressing review feedback or making further changes).

If there is no open PR on the current branch, use the **create-pr** skill instead.

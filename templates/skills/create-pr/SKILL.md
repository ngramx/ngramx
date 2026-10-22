---
name: create-pr
description: >-
  Create a pull request following Gigabyte conventions: title format, risk/size
  labels, completion record. Use when the user says "create a PR", "open a pull
  request", "raise a PR", or when work on a ticket is finished.
---

# Create a Pull Request

## PR title format

Use the ticket ID and branch description, formatted in Title Case:
`GIG-1599: PWA Rebranding App Name Logo Favicon`

Target the project's default integration branch (typically `main`).

## Never draft

**Never open draft PRs.** CI and automated review should run against the PR from the moment it is created. Use `gh pr create` without `--draft`.

## Code style

Before opening a PR that touches formatted source files, either run the project's formatter and include the fixes (Laravel Pint inside the app container is typical for PHP; confirm `./vendor/bin/pint --test` is clean), or ask the user whether they want formatting run first. Do not open a PR with a dirty formatter check unless the user explicitly skips it.

## Risk and size labels

Every PR gets two labels — one for risk, one for size. The merge automation reads them.

**Risk:**

- `risk:low` — docs, tests-only, comments, dependency bumps with passing CI, isolated UI tweaks, internal tooling
- `risk:medium` — feature work or refactors that touch shared code but follow established patterns
- `risk:high` — auth, payments, migrations, infra/deployment, customer data, external integrations, outbound communications (see Forced escalation), anything that could cause an outage or data loss

**Size:**

- `size:small` — ≤5 files, ≤200 lines
- `size:medium` — ≤15 files, ≤600 lines
- `size:large` — anything bigger

**Rules:**

- When in doubt, escalate. Default to higher risk and larger size.
- Include a one-line rationale in the PR description, e.g. *"Labelled `risk:low` because only touches docs and tests."*
- Never open a PR without both labels.

GitHub Actions (`high-risk-review`) classifies the diff again on open and on every push. `risk:high` disables auto-merge. The required check `high-risk-review` stays red until the pull request has one approval. Submitting that approval re-runs the check and turns it green. Dismissing it, or requesting changes, turns the check red again. The author's own approval counts. Other risk labels pass that check with no review.

## Forced escalation

Some diffs must be labelled `risk:high` even when they are small. Auto-merge only proceeds for `risk:low` together with `size:small`, so `risk:high` keeps a person in the review.

Add further categories in this section. Each category names the label, the signals that force it, and the edits that stay on the normal risk scale.

### Outbound communications

Label `risk:high` when the pull request introduces or enables a process that sends messages to people:

- Email, SMS, letters, or other external dispatch. This includes a new sender, and config, flags, or schedules that turn an existing sender on.
- Scheduled commands, queued jobs, listeners, or webhooks whose purpose is to send those messages.
- Bulk mail, including bulk internal mail.

Name the send path in the risk rationale, for example: *"Labelled `risk:high` because this schedules the weekly digest email to clients."*

Wording, template, and copy edits stay on the normal risk scale when the sender already sends on the same trigger to the same audience.

## Linear status — leave it alone

Do **not** change the Linear issue status when creating or updating the PR. The ticket should already be **"In Progress"** (set when work began), and the CI-driven `linear-status-sync` workflow moves it to **"In Review"** automatically once checks pass. Manually setting the status here would conflict with that automation.

## Completion record

**Commit and push `completion.json` before opening the PR.** Automated reviewers inspect the head commit at PR-open time; adding the file in a follow-up commit causes false "missing completion.json" comments.

Write `.ngramx/tickets/[ticket-id]/completion.json` first. This file **must** be valid JSON — do not use markdown, do not add extra keys:

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

- `title` (required): PR title including ticket ID, e.g. `GIG-123: Short Title`.
- `description` (required): One or two sentences describing what the changes are.
- `pr_url` (required): Full URL of the GitHub pull request. Before the PR exists, use an empty string `""`, then set the real URL immediately after `gh pr create` and push that update.
- `linear_url` (optional): Full URL of the Linear ticket. Set to `null` or omit for non-Linear work.
- `test_urls` (required): Array of `{ "label", "url" }` objects. Deep-links into the running application. Use the local development URL and include bypass tokens if needed.
- `test_plan` (required): Array of test blocks. Each has `description` (short summary), `status` (always `"active"` when creating a PR), and `steps` (ordered testing instructions).

## Workflow

1. Ensure implementation changes are committed.
2. Create `completion.json` in the ticket folder (`pr_url` may be `""` until the PR exists).
3. Commit and push — **including** `completion.json` — so the file is on the remote branch before the PR opens.
4. Run `gh pr create` with title, body (including risk/size rationale), and labels.
5. Set `pr_url` in `completion.json` to the real PR URL, commit, and push.
6. Report the PR URL to the user.

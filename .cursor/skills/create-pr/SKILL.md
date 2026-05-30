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

## Risk and size labels

Every PR gets two labels — one for risk, one for size. The merge automation reads them.

**Risk:**

- `risk:low` — docs, tests-only, comments, dependency bumps with passing CI, isolated UI tweaks, internal tooling
- `risk:medium` — feature work or refactors that touch shared code but follow established patterns
- `risk:high` — auth, payments, migrations, infra/deployment, customer data, external integrations, anything that could cause an outage or data loss

**Size:**

- `size:small` — ≤5 files, ≤200 lines
- `size:medium` — ≤15 files, ≤600 lines
- `size:large` — anything bigger

**Rules:**

- When in doubt, escalate. Default to higher risk and larger size.
- Include a one-line rationale in the PR description, e.g. *"Labelled `risk:low` because only touches docs and tests."*
- Never open a PR without both labels.

## Completion record

After the PR is created, add `.cortex/tickets/[ticket-id]/completion.md`:

```markdown
## GitHub PR

[URL of the pull request]

## Linear ticket

[URL of the Linear ticket, or omit if not applicable]

## Click to Test

[Deep-link into the running application at the route that demonstrates the change.
Use the local development URL. Include bypass tokens if needed.]
```

## Workflow

1. Ensure all changes are committed and pushed.
2. Run `gh pr create` with title, body (including risk/size rationale), and labels.
3. Create the completion record in the ticket folder.
4. Report the PR URL to the user.

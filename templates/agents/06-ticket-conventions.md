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
- `completion.md` — see below.

## Completion record

When work on the ticket is complete, add `.ngramx/tickets/[ticket-id]/completion.md` containing at least:

- **GitHub PR:** URL of the pull request.
- **Linear ticket:** URL of the Linear ticket (omit if not applicable).
- **Click to Test:** a deep-link into the running application at the exact route that demonstrates the change. Use the local development URL (inspect the project's Docker / Ngramx setup to find it). If the project requires a bypass token (for example `?bypass=hello@example.com`), include it on the URL so reviewers can open the page directly. Some projects ship more than one app (for example a web app and a PWA); link to every surface the change touches.

## Hard rules

- **Never open draft PRs.** CI and automated review should run against the PR from the moment it is created.
- **Never add a `cursor/` (or any other tool-specific) prefix to branch names.** The branch name should describe the work, not the tool that produced it.
- One ticket = one branch = one PR.

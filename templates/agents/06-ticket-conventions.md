# Ticket and PR conventions

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

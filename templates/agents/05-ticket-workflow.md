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
- `plan.md` — implementation plan (may be blank initially and filled in during the approach/planning steps below).
- `specs.md` — references to related feature specs (may be blank initially).
- `assets/` — any non-text reference material supplied by the user (screenshots, mockups, diagrams).

## Pull request naming and state

When opening a pull request:

- Use the branch description as the PR title, formatted in Title Case, e.g. `GIG-1599: PWA Rebranding App Name Logo Favicon`.
- Target the project's default integration branch (typically `main`).
- **Never open draft PRs.** CI and automated review should run against the PR from the moment it is created.

## Completion record

When work on the ticket is complete, add `.cortex/tickets/[ticket-id]/completion.md` containing at least:

- **GitHub PR:** URL of the pull request.
- **Linear ticket:** URL of the Linear ticket (omit if not applicable).
- **Click to Test:** a deep-link into the running application at the exact route that demonstrates the change. Use the local development URL (inspect the project's Docker / Cortex setup to find it). If the project requires a bypass token (for example `?bypass=hello@example.com`), include it on the URL so reviewers can open the page directly. Some projects ship more than one app (for example a web app and a PWA); link to every surface the change touches.

This record makes handover and review straightforward regardless of which AI agent or human picks up the review.

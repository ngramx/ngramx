---
name: start-ticket
description: >-
  Start work on a Linear ticket: create the branch, set up the ticket folder,
  fetch ticket data. Use when the user says "start work on", "pick up", "begin",
  or references a ticket ID like GIG-1234, COR-99, VF-42.
---

# Start Work on a Ticket

## Branch naming

Create a branch named `[ticket-id]-[short-title-in-kebab-case]`, e.g. `gig-1599-pwa-rebranding-app-name-logo-favicon`.

- Use the lowercase ticket identifier as the prefix (e.g. `gig-1599`, `core-42`, `cor-223`).
- Follow the prefix with a short, kebab-cased description drawn from the ticket title.
- If there is no ticket, agree a short identifier with the user before branching (for example `chore-logging-tidy`).

Branch from the project's default integration branch (typically `origin/main`).

## Set up the ticket folder

Create `.cortex/tickets/[ticket-id]/` at the repo root with:

### README.md

```markdown
# [ticket-id]: [ticket title]

## Summary

[Brief summary of what is in this ticket.]

## Requirements

[Description from the ticket / the user's brief.]

## Changes

[Running log of the changes being made.]
```

### ticket.json

If the ticket exists in Linear, fetch the ticket data using the Linear MCP and save it as `ticket.json`. Omit for non-Linear work.

## Workflow

1. Fetch the ticket from Linear (if applicable) to understand requirements.
2. Create and checkout the branch from `origin/main`.
3. Create the `.cortex/tickets/[ticket-id]/` folder with `README.md` and `ticket.json`.
4. Commit the ticket folder as the first commit on the branch.
5. Summarise the ticket requirements back to the user and confirm the approach before starting implementation.

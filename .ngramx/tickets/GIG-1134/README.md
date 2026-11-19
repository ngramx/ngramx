# GIG-1134: Return in output for cortex CLI commands

## Summary

Add an optional success-text field to ngramx custom commands (historically "cortex CLI" / `cortex.yml`, now `ngramx.yml`) so that configured text is written to stdout when a command completes successfully.

## Requirements

- Custom commands today accept `command`, `description`, and `timeout` (plus related options such as `retry`, `ignore_failure`, `parallel`).
- Allow an optional success text in the command definition.
- On successful run of a configured command, print that text to standard output.
- Keep existing behaviour when the field is omitted (generic "Command completed successfully" message).

## Changes

- Ticket folder created; branch `gig-1134-return-in-output-for-cortex-cli-commands` started from `origin/main` in a worktree (primary checkout has in-progress COR-328 work).

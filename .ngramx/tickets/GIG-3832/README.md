# GIG-3832: Add PR risk heuristic: flag PRs that introduce outbound communications

## Summary

Flag pull requests that introduce or enable outbound communications so they get extra human review before merge. Recent Block Debt weekly-digest and Earl Kendrick invoice-forecasting releases sent bulk email to external recipients without client sign-off.

## Requirements

- Define heuristics that detect outbound communication patterns in PRs (email dispatch, scheduled jobs, queue dispatches for notifications, letters, SMS, and other external comms).
- Integrate with the existing PR review/scoring tooling (`create-pr` risk labels, consumed by auto-merge).
- Flag matching PRs as requiring additional review.
- Document the heuristic so later risk categories can follow the same pattern.

## Changes

- Ticket folder created; branch `gig-3832-add-pr-risk-heuristic-flag-prs-that-introduce-outbound` started from `origin/main` with no upstream.
- Added a Forced escalation section to `templates/skills/create-pr/SKILL.md`. Outbound communications (new or newly enabled email, SMS, letters, scheduled or queued senders, and bulk mail) must be labelled `risk:high`. Copy edits to an existing sender on the same trigger and audience stay on the normal scale. Further risk categories can be added in that section the same way.

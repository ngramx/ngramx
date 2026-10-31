# GIG-4344: Only mark a pull request high-risk when it sends communication to external people

## Summary

The create-pr skill used the same broad high-risk scale as the review workflow, so agents still labelled database migrations as `risk:high`. Align the skill with the narrower gate: only outbound communication to external people is high-risk.

## Requirements

`risk:high` blocks merge until one person approves. That should happen only when a pull request introduces or enables a send to external people. Database migrations, auth, payments, infrastructure, and other ordinary work stay on the normal scale, and the author can merge them. Copy edits to an existing sender, on the same trigger and audience, and internal-only messages, are not high-risk.

## Changes

- Updated `templates/skills/create-pr/SKILL.md` so `risk:high` is only outbound communication to external people.
- Removed bulk internal mail from the forced category.
- Updated the high-risk review note in `README.md` to the same rule.
- Updated the skill sync test that locks the forced-escalation wording.

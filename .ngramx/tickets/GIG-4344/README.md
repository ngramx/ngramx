# GIG-4344: Make high-risk review less sensitive without dropping real harm

## Summary

Align the create-pr skill with the less sensitive high-risk scale. Adding a column is not high-risk. An external send, an access change, money movement, a destructive data change, or production downtime still is.

## Requirements

`risk:high` blocks merge until one person approves. That should happen when a pull request can harm production or people outside the team, and not when it only adds a column, index, nullable field, or new table. Copy edits to an existing sender, on the same trigger and audience, and internal-only messages, are not high-risk.

## Changes

- Updated `templates/skills/create-pr/SKILL.md` so `risk:high` is an external send, an access change, money movement, a destructive data change, or production downtime.
- Adding a column, index, nullable field, or new table stays on the normal scale.
- Updated the high-risk review note in `README.md` to the same rule.
- Updated the skill sync test that locks the forced-escalation wording.

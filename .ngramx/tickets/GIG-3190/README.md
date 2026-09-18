# GIG-3190: Add AGENTS.md rule: auto-update ngramx anonymization config when migrations create PII columns

## Summary

Add a one-liner to the managed AGENTS.md template reminding agents to update `postmaclone.tables` in `ngramx.yml` whenever a migration introduces PII columns, then sync the rule to all active projects.

## Requirements

- Add a one-liner rule to the standard `AGENTS.md` template explaining that any migration adding PII columns must also update the project's ngramx post-clone anonymization config
- Distribute the updated rule across all active projects using `ngramx sync-agents`

## Changes

- Added PII/postmaclone rule to `templates/agents/04-database-migrations.md`
- Regenerated managed agent targets in ngramx via `sync-agents` (AGENTS.md, CLAUDE.md, `.cursor/rules/ngramx.mdc`)
- Added regression assertions in `AgentsManagedBodyProviderTest`
- Ran `sync-agents` across all local projects with `ngramx.yml` (hydra-main, earl-kendrick-core, terrablock, think-fire-defence-core, cortex, apollo, akt, survaey-docker, hydra-gig-2316); terrablock only syncs `cursor_rules` + `claude_md` (no managed AGENTS.md target)

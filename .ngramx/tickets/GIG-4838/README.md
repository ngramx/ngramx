# GIG-4838: Document hook timing migration for worktree creation

## Context

The ngramx worktree hooks currently fire in a specific order during worktree creation. The `onPreEnvironment` and `onPostEnvironment` hooks were added in v2.61.0 (PR #70, commit 2eb23d7) to allow workspace setup tasks (like TMUX window creation) to run immediately after worktree creation, before the Docker environment finishes spinning up.

Previously, users had to wait for the full environment startup before hooks like TMUX window creation would fire (via `onWorktreeCreate`). This meant unnecessary waiting time before being able to start working.

## Problem Statement

Rob mentioned he already updated the hook ordering in the ngramx code but hasn't updated his own config to use the new behaviour. The ticket requested:
1. Verify the ngramx change is correct
2. Document the config update needed for users to migrate

## Investigation

### Current Hook Execution Order

Reviewed `src/Command/ReviewCommand.php` and confirmed the execution flow:

```
1. Worktree created (line 482-492)
2. Config and env files seeded (lines 498-505)
3. onPreEnvironment hooks run (lines 566-575) ← NEW in v2.61.0
4. Environment startup begins (line 699)
5. onPostEnvironment hooks run (lines 819-828) ← NEW in v2.61.0
6. Database reset (lines 830-840)
7. onReview hooks run (line 939)
8. onWorktreeCreate hooks run (lines 943-952)
```

**Verification Result:** ✅ The implementation is correct. `onPreEnvironment` runs immediately after worktree creation but before Docker environment startup, which is exactly what the ticket requested.

### Existing Documentation

Found the hooks were already documented in:
- `README.md` (lines 403-408) - hook event table
- `ngramx.example.yml` (lines 250-273) - example configuration
- `docs/claude-code-integration.md` - Claude Code hook integration

However, the documentation didn't clearly explain:
- **Why** you would choose one hook over another
- **When** to migrate from `onWorktreeCreate` to `onPreEnvironment`
- The performance benefit of the migration

## Changes Made

### 1. Enhanced README.md Documentation

Added a new "Execution order" section after the hook event table that:
- Shows the complete hook execution sequence
- Recommends `onPreEnvironment` over `onWorktreeCreate` for workspace setup
- Provides a migration example for TMUX window creation
- Explains when to use each hook type

### 2. Updated ngramx.example.yml

Improved the hooks section to:
- Move TMUX example from `onWorktreeCreate` to `onPreEnvironment`
- Add explanatory comments for each hook timing
- Clarify the use case for each hook event

### 3. Created Migration Guide

Added `docs/hook-migration-guide.md` with:
- Step-by-step migration instructions
- Before/after timing diagrams
- Configuration file precedence explanation
- Complete example for Rob's TMUX setup
- Troubleshooting guidance

## Rob's Configuration Migration

**Before (~/.ngramx.yaml):**
```yaml
hooks:
  onWorktreeCreate:
    - "tmux new-window -c {worktree_path} -n {ticket_slug}"
```

**After (~/.ngramx.yaml):**
```yaml
hooks:
  onPreEnvironment:
    - "tmux new-window -c {worktree_path} -n {ticket_slug}"
```

**Result:** TMUX window opens immediately after worktree creation, allowing work to begin while Docker containers start in parallel.

## Testing

The implementation was already merged in PR #70 (Nov 13, 2026) and has been in production since v2.61.0. The ticket requested verification and documentation, not a code change.

Verified the hook execution order by reviewing:
- `src/Command/ReviewCommand.php` - confirmed PreEnvironment runs before `runUpCommand`
- `src/Config/Schema/HookEvent.php` - confirmed both hook events are defined
- `src/Hooks/HookRunner.php` - confirmed hook execution logic
- CHANGELOG.md - confirmed feature was released in v2.61.0

## Files Changed

- `README.md` - Added execution order section and migration guidance
- `ngramx.example.yml` - Updated hook examples and comments
- `docs/hook-migration-guide.md` - New comprehensive migration guide

## Verification Checklist

- ✅ Verified `onPreEnvironment` runs before environment startup
- ✅ Verified `onPostEnvironment` runs after startup but before database reset
- ✅ Verified `onWorktreeCreate` runs at the end
- ✅ Documented execution order with timing diagram
- ✅ Provided migration example for TMUX use case
- ✅ Created comprehensive migration guide
- ✅ Updated example configuration

## Notes

No code changes were needed - the feature was already implemented in v2.61.0. This ticket focused on:
1. Verification that the implementation is correct (confirmed)
2. Documentation to help users migrate (completed)

The migration is backward compatible - `onWorktreeCreate` still works exactly as before. Users can migrate at their convenience.

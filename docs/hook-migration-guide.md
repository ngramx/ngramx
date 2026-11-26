# Hook Migration Guide

## Migrate workspace setup from `onWorktreeCreate` to `onPreEnvironment`

If you currently use `onWorktreeCreate` for workspace setup tasks (opening editor windows, creating TMUX sessions, etc.), migrate them to `onPreEnvironment` so you can start working immediately while the Docker environment spins up in the background.

### Why migrate?

**Before (slower):**
```
branch checkout → environment startup (wait...) → 
database reset (wait...) → hooks run → start working
```

**After (faster):**
```
branch checkout → hooks run immediately → start working
(while environment spins up in parallel)
```

### Migration steps

#### 1. Check your current configuration

Look in `~/.ngramx.yaml` (or project `ngramx.yml`/`.ngramx/config.yaml`) for `onWorktreeCreate` hooks:

```yaml
hooks:
  onWorktreeCreate:
    - "tmux new-window -c {worktree_path}"
    - command: "notify-send 'Worktree ready' '{folder}'"
      description: "Desktop notification"
```

#### 2. Identify which hooks to migrate

**Move to `onPreEnvironment`:**
- TMUX window/session creation
- Editor launches (unless using `onReview` or `--editor`)
- Desktop notifications
- Workspace initialization scripts
- Anything that doesn't need a running Docker environment

**Keep in `onWorktreeCreate`:**
- Database queries
- HTTP requests to the application
- Tasks that verify the environment is fully ready
- Anything requiring a running and migrated database

**Use `onPostEnvironment` for:**
- Tasks that need Docker running but execute before migrations
- Pre-seeding tasks
- Container health verification

#### 3. Update your configuration

**Before:**
```yaml
hooks:
  onWorktreeCreate:
    - "tmux new-window -c {worktree_path}"
    - command: "notify-send 'Ready' '{url}'"
```

**After:**
```yaml
hooks:
  onPreEnvironment:
    - "tmux new-window -c {worktree_path}"
    - command: "notify-send 'Worktree created' '{folder}'"
  onWorktreeCreate:  # optional, only if you have tasks that need the full stack
    - command: "curl {url}/health"
      description: "Verify app is responding"
```

#### 4. Test the migration

```bash
# Remove an existing worktree to test from scratch
ngramx worktree --cleanup <ticket>

# Create it again - your hooks should run immediately
ngramx worktree <ticket>
```

You should see your TMUX window (or editor) open right away, while the "Starting isolated environment" messages appear in parallel.

### Execution order reference

```
branch checkout
  ↓
onPreEnvironment hooks ← workspace setup (TMUX, notifications)
  ↓
environment startup (docker compose up...)
  ↓
onPostEnvironment hooks ← post-startup tasks
  ↓
database reset (migrations, seed)
  ↓
onReview hooks ← editor launch (if configured)
  ↓
onWorktreeCreate hooks ← final verification
```

### Configuration file precedence

When the same event appears in multiple configuration files, the later source **replaces** the earlier one:

```
~/.ngramx.yaml  <  .ngramx/config.yaml  <  ngramx.yml
(personal)         (project shared)        (project committed)
```

Put your TMUX/editor preferences in `~/.ngramx.yaml` so they apply to all projects.

### Example: Rob's TMUX setup

**Old configuration (~/.ngramx.yaml):**
```yaml
hooks:
  onWorktreeCreate:
    - "tmux new-window -c {worktree_path} -n {ticket_slug}"
```

**New configuration (~/.ngramx.yaml):**
```yaml
hooks:
  onPreEnvironment:
    - "tmux new-window -c {worktree_path} -n {ticket_slug}"
```

Now the TMUX window opens immediately after the worktree is created, and you can start editing files while Docker containers start in the background.

### Available placeholders

All hooks receive the same context:

- `{worktree_path}` / `{path}` — absolute worktree directory
- `{branch}` — git branch name
- `{ticket}` — ticket number (e.g., `GIG-4838`)
- `{ticket_slug}` — sanitized ticket (e.g., `gig-4838`)
- `{url}` — primary application URL
- `{repository_path}` — parent repository path
- `{folder}` — worktree folder name
- `{project_path}` — project root (alias for repository_path)

Each is also an environment variable: `{path}` is `$NGRAMX_PATH`, `{branch}` is `$NGRAMX_BRANCH`.

### Need help?

- Full documentation: `README.md` → "Lifecycle hooks" section
- Example configuration: `ngramx.example.yml`
- Report issues: https://github.com/ngramx/ngramx/issues

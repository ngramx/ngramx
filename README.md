# Ngramx CLI

[![Tests](https://github.com/ngramx/ngramx/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/ngramx/ngramx/actions/workflows/tests.yml)
[![Release](https://github.com/ngramx/ngramx/actions/workflows/release.yml/badge.svg)](https://github.com/ngramx/ngramx/actions/workflows/release.yml)
[![codecov](https://codecov.io/gh/ngramx/ngramx/branch/main/graph/badge.svg)](https://codecov.io/gh/ngramx/ngramx)
[![semantic-release: angular](https://img.shields.io/badge/semantic--release-angular-e10079?logo=semantic-release)](https://github.com/semantic-release/semantic-release)
[![Release](https://img.shields.io/github/v/release/ngramx/ngramx)](https://github.com/ngramx/ngramx/releases)
[![License](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)

A PHP-based CLI tool that orchestrates Docker-based development environments using a simple YAML configuration file.

## Features

- 🚀 Automated setup of Docker-based development environments
- 📝 Simple YAML configuration
- ⚡ Fast and reliable Docker Compose orchestration
- 🎨 Beautiful, colorful console output
- 🔧 Extensible command system

## Requirements

- PHP 8.2 or higher
- Docker and Docker Compose
- Composer (for development)

## Installation

Download the latest [release](https://github.com/ngramx/ngramx/releases/latest): grab `ngramx.phar` and `install.sh`, then run the installer locally.

```bash
curl -L https://github.com/ngramx/ngramx/releases/latest/download/ngramx.phar -o ngramx.phar
curl -L https://github.com/ngramx/ngramx/releases/latest/download/install.sh -o install.sh
chmod +x install.sh
./install.sh
```

This installs `ngramx` to `/usr/local/bin/ngramx`, enables shell completion (bash/zsh), and makes the CLI available system-wide.

## Quick Start

Create a `ngramx.yml` file in your project root:

```yaml
version: "1.0"

docker:
  compose_file: "docker-compose.yml"
  primary_service: "app"
  wait_for:
    - service: "db"
      timeout: 60

setup:
  pre_start:
    - command: "cp .env.example .env"
      description: "Create environment file"
      ignore_failure: true
      
  initialize:
    - command: "composer install"
      description: "Install PHP dependencies"
      timeout: 300

commands:
  test:
    command: "php artisan test"
    description: "Run test suite"
```

Then run:

```bash
ngramx up
```

*Note:* this process relies on an existing Docker container called `mcp-network`.

```
docker network create mcp-network
```

*Also note:* if changes are made to the underlying Docker image, this must be rebuilt:

```
# First, stop the environment
ngramx down
# Rebuild the image (outside of ngramx)
docker compose build --no-cache app
# Then start again via ngramx
ngramx up
```

## Commands

### `ngramx init`

Initialize Ngramx configuration and directory structure:

```bash
ngramx init                # Full initialization
ngramx init --skip-yaml    # Skip creating ngramx.yml
ngramx init --skip-claude  # Skip creating ~/.claude files
ngramx init --force        # Overwrite existing files
```

This command creates:

**Project-level files:**
- `.ngramx/` directory structure (tickets, specs, meetings)
- `.ngramx/README.md` with documentation
- `ngramx.yml` configuration file (unless `--skip-yaml`)

**User-level files (in `~/.claude/`):**
- `CLAUDE.md` - Instructions for Claude Code
- `rules/ngramx.md` - Ngramx workflow rules

The user-level files are automatically updated if templates change when re-running `ngramx init`. Use `--skip-claude` if you maintain your own `~/.claude` files.

### `ngramx init-github-actions`

Add caller workflows under `.github/workflows/` that invoke the shared automation from [`gigabyte-software/shared-workflows`](https://github.com/gigabyte-software/shared-workflows): CI auto-fix, auto-rebase, review-comment fixes, and auto-merge.

```bash
ngramx init-github-actions
ngramx init-github-actions --ref v1 --ci-workflow-name "CI" --base-branch develop
ngramx init-github-actions --no-composer --force
ngramx init-github-actions --auto-merge-label ship-it --merge-method rebase
```

The generated `auto-merge.yml` looks for the `auto-merge` label (override with `--auto-merge-label`) and refuses to merge into any branch listed in `--protected-branches` (default: `master,main,stage,staging,test,testing,prod,production`). Merge method defaults to `squash` and can be `merge`, `squash`, or `rebase`.

See `ngramx init-github-actions --help` for all options (`--repo`, `--ref`, `--php-version`, `--node-version`, `--auto-merge-label`, `--protected-branches`, `--merge-method`, etc.). After running, configure the repository secrets listed in the command output and confirm your CI workflow name matches `--ci-workflow-name`.

### `ngramx update`

Update Ngramx CLI to the latest version:

```bash
ngramx update           # Update to latest version
ngramx update --check   # Check for updates without installing
ngramx update --force   # Force update even if already latest
```

This command:
1. Checks GitHub for the latest release
2. Compares with your current version
3. Downloads and installs the update if available
4. Creates a backup before updating

**Note**: Only works when running as the installed PHAR.

### `ngramx up`

Start your development environment:

```bash
ngramx up
```

This will:
1. Run pre-start commands on host
2. Start Docker Compose services
3. Wait for services to be healthy
4. Run initialize commands in container

After a successful start, if `docker.app_url` uses a hostname that does not resolve (for example `http://dev.myproduct`, or `*.localhost` under WSL where the distro resolver ignores RFC 6761), Ngramx prints a suggested `/etc/hosts` line. The post-start HTTP probe connects to `*.localhost` URLs via `127.0.0.1` with the correct `Host` header, so verification still works without editing hosts. Ngramx does not write `/etc/hosts` itself (that needs `sudo`).

Options:
- `--no-wait` - Skip health checks
- `--skip-init` - Skip initialize commands
- `--avoid-conflicts` - Automatically avoid container name and port conflicts by generating a unique namespace and port offset
- `--postmaclone` - Run Postmaclone doctor first; if it passes, create a clone after the stack is up (same as `ngramx postmaclone --replace`). Blocking doctor failures or a later clone error only skip/warn — `up` still finishes and always writes `.ngramx.lock`
- `--no-host-mapping` - Do not expose container ports to the host (useful for running multiple instances)
- `--namespace <name>` - Use a custom container namespace prefix
- `--port-offset <n>` - Add offset to all exposed ports (e.g., `--port-offset 100` maps port 80 to 180)

**Running Multiple Instances:**

To run the same project multiple times (e.g., for different branches):

```bash
# Option 1: Auto-avoid conflicts (recommended)
ngramx up --avoid-conflicts

# Option 2: Manual namespace and port offset
ngramx up --namespace feature-x --port-offset 100

# Option 3: No host ports (access via Docker network only)
ngramx up --no-host-mapping

# Bring up the stack and bind a Postmaclone clone in one step
ngramx up --postmaclone
```

### `ngramx down`

Stop your development environment:

```bash
ngramx down           # Stop services, keep volumes
ngramx down --volumes # Stop services and remove volumes
```

### `ngramx rebuild`

Rebuild Docker images, recreate containers, and reset the database:

```bash
ngramx rebuild
```

This command:

1. Tears down existing containers (keeping volumes)
2. Rebuilds all Docker images from scratch
3. Starts containers and waits for services to be healthy
4. Runs your `fresh` command to reset the database

Use this when `Dockerfile`, `docker-compose.yml`, or any image-layer dependency changes, or when you want a completely clean environment.

**Requires:** a `fresh` command defined in `ngramx.yml` (see [Recommended commands](#recommended-commands)). If it's not defined, rebuild will warn and skip the database reset.

### `ngramx review`

Prepare the environment for reviewing a ticket by checking out its branch and resetting the database:

```bash
ngramx review GIG-1234           # Checks out the branch and runs `fresh`
ngramx review GIG-1234 --quick   # Checks out the branch and runs `clear` instead
ngramx review GIG-1234 --worktree # Reviews in an isolated worktree + parallel env
ngramx review GIG-1234 --cursor   # Same as --worktree, then opens a new Cursor window
ngramx review GIG-1234 -c         # Shorthand for --cursor
ngramx review GIG-1234 --cleanup  # Tears down + removes that ticket's worktree env
ngramx review --cleanup           # Pick from a list (including "all")
ngramx review --cleanup --all     # Tears down + removes every worktree env
```

This command:

1. Fetches from `origin`
2. Finds branches containing the ticket number and checks one out (prompts if there are multiple)
3. Runs either `fresh` (default) or `clear` (with `--quick`) to sync the environment
4. Prints any URLs from `.ngramx/tickets/<ticket>/completion.json` (falls back to legacy `completion.md`)

**Options:**

- `--quick` — Run the `clear` command instead of `fresh`. This skips the database reset and only installs deps and clears caches, so it's much faster. **Only use `--quick` on branches that don't change your database schema or seed data** — otherwise you'll be reviewing against stale data. When in doubt, use the default (`fresh`).
- `--worktree` / `-w` — Review in an **isolated git worktree with its own parallel dev environment** instead of checking the branch out in your main working directory. This lets you review (or fix) several tickets at once without your editor and Docker stack fighting over a single branch.
- `--cursor` / `-c` — Everything `--worktree` does, then opens the worktree in a **new Cursor window**. Implies `--worktree`.
- `--cleanup` — Stop the worktree's Docker stack (including its volumes) and remove the git worktree. Use this when you're done reviewing. The argument is matched the same way as `ngramx worktree --cleanup` (folder, namespace, ticket, list index, or any fragment of a worktree or branch name), and an ambiguous match asks which one you meant. **Omit the argument** (`ngramx review --cleanup`) to pick from a list, with "All worktrees" as an option, or pass `--all` to remove every worktree without being asked. (If a container left root-owned files behind, cleanup removes them via a short-lived helper container.)

**How worktree mode works:**

- Creates a git worktree at `.ngramx/worktrees/<ticket>-<repo>/` (e.g. `.ngramx/worktrees/gig-178-ill-kendrick/`). The folder name is the ticket slug + repository name, so it reads clearly in the Cursor title bar.
- Brings up a **separate Docker stack** with its own namespace and an automatically-chosen port offset, so it never conflicts with your main `ngramx up` or other worktrees.
- Generates a ticket-prefixed dev URL like `http://gig-178-ill-kendrick.localhost:8080`. Windows/macOS browsers resolve `*.localhost` to loopback automatically; under WSL you may still want a `/etc/hosts` line for tools inside the distro (the `ngramx up` probe does not need one).
- Copies your **parent `.env`** into the worktree and patches `APP_URL` to the worktree URL. Other values (DB creds, etc.) are safe to share because each worktree gets its own namespaced containers and volumes.
- **Reuses your already-built Docker image** (re-tags the main checkout's image for the worktree's Compose project) instead of rebuilding from scratch, and **copies `vendor/` and `node_modules/`** from the parent so `composer install` / `npm ci` is a near-instant no-op. In practice this turns a multi-minute cold start into seconds.
- Adds `/.ngramx/worktrees/` to `.git/info/exclude` and `.cursorignore` so the parent checkout neither tracks nor indexes the nested worktrees.
- **Makes git work inside the worktree's containers.** A linked git worktree's `.git` is a *file* pointing at the parent repo's git dir (`gitdir: …/.git/worktrees/<name>`), which lives outside the worktree's bind mount. Left alone, any `git` command inside the container fails with `fatal: not a git repository` — and if your entrypoint runs git under `set -e`, the container crash-loops (`Restarting 128`) forever. Ngramx detects the worktree and bind-mounts the parent repo's git dir into the build-context services (app + any worker/reverb) at the **same absolute path** the pointer references, so git resolves unchanged. It also sets `GIT_CONFIG_*` / `safe.directory=*` in those services so the mounted repo doesn't trip git's "dubious ownership" guard. This is injected into the generated `docker-compose.override.yml`, so it survives every regeneration.

When you're done, run `ngramx review <ticket> --cleanup` to stop the stack (and remove its volumes) and delete the worktree in one step.

#### Compose override files

Ngramx layers compose files in a fixed order, so machine-generated and human-authored changes never fight:

1. `docker-compose.yml` — your base file.
2. `docker-compose.override.yml` — **generated and regenerated by Ngramx** on every `ngramx up` / `ngramx review` (port offsets, namespace prefixes, the worktree git mount). **Never hand-edit this file** — your changes are silently overwritten on the next run.
3. `docker-compose.user.yml` — **optional, never touched by Ngramx.** Put local customisations (extra mounts, env vars, dev-only services) here. Ngramx layers it last whenever it exists, so it wins over the generated override and survives regeneration. Commit it (or `.gitignore` it) as your project prefers.

If a crash-looping container slips through, `ngramx up` (and therefore `ngramx review`) fails fast: it detects the `Restarting`/`Exited` state (or a climbing restart count), dumps the last ~50 log lines from the offending service, and exits non-zero instead of leaving the container looping silently.

**Requires:** `fresh` and (for `--quick`) `clear` defined in `ngramx.yml` — see [Recommended commands](#recommended-commands). If the relevant command isn't defined, review falls back to a generic Laravel reset (`optimize:clear` + `migrate:fresh --seed`).

### `ngramx worktree`

Start (or continue) your own work on a ticket in an isolated git worktree with its own parallel dev environment — the "author" counterpart to `ngramx review`:

```bash
ngramx worktree 2345              # Bare number — prefixed with default_team from ngramx.yml
ngramx worktree gig-1234          # Full ticket reference
ngramx worktree gig-1234 --quick  # Skips database reset (same semantics as review --quick)
ngramx worktree gig-1234 --cursor # Opens the worktree in a new Cursor window once ready
ngramx worktree gig-1234 -c       # Shorthand for --cursor
ngramx worktree gig-1234 --cleanup  # Tears down + removes that ticket's worktree env
ngramx worktree invoice --cleanup   # Matches on folder / namespace / branch text
ngramx worktree 2 --cleanup         # Index from `ngramx status`
ngramx worktree --cleanup           # Pick from a list (including "all")
```

This command:

1. Fetches from `origin`
2. Searches remote branches for the ticket (canonical slug, hyphen-less spelling, then bare number)
3. Uses the matching branch, prompts if multiple, or creates a new `{team}-{number}` branch when none exists
4. Creates or reuses a worktree under `.ngramx/worktrees/` and brings up a parallel dev environment (same machinery as `review --worktree`)
5. Prints the application URL, worktree path, and any URLs from `.ngramx/tickets/<ticket>/completion.json`

**Options:**

- `--quick` — Run the `clear` command instead of `fresh`. Same caveats as `review --quick`.
- `--cursor` / `-c` — Open the worktree in a **new Cursor window** once the environment is ready. Requires the `cursor` CLI on your PATH; degrades gracefully with a manual command hint if not found.
- `--cleanup` — Stop the worktree's Docker stack (including its volumes) and remove its git worktree. The argument is matched against, in order of specificity: the exact worktree folder or Docker namespace, a ticket reference (`2345`, `gig-2345`, `gig2345`, or a pasted branch name), the index shown by `ngramx status`, then any fragment of a worktree or branch name. When the argument matches more than one worktree you are asked which one, instead of being told to be more specific.

  **Omit the argument** (`ngramx worktree --cleanup`) and you pick from a list of the repository's worktrees, with "All worktrees" and "Cancel" as the last two entries — removing everything is a choice you make rather than the default. With no answer available (output piped, no TTY) the prompt takes its default, which is Cancel, so nothing is removed by accident.

- `--all` — With `--cleanup`, target every worktree without asking. This is how a script says "remove them all" out loud; `ngramx worktree --cleanup -n` (`--no-interaction`) does the same for existing automation.

When you're done, run `ngramx worktree <ticket> --cleanup` (or `ngramx review <ticket> --cleanup`) to tear down the worktree environment.

### `ngramx status`

The project overview — what this repository has running, everywhere:

```bash
ngramx status
ngramx status --services   # per-service health for the environment you are in
```

Prints:

- **Project** — where the main checkout lives, its branch, whether its
  environment is up, and the URL when it is.
- **Worktrees** — every worktree under `.ngramx/worktrees/` with a stable
  index, its branch, whether its environment is up, and the URL to view that
  app (its own `<ticket>.localhost` origin and port where it has one).

```
▸ Project

  path         /home/rob/projects/terrablock
  branch       main
  environment  stopped

▸ Worktrees (3)

    #   worktree              branch                status   url
    1   gig-2478-terrablock   gig-2478-invoice      stopped  —
    2   gig-2895-terrablock   gig-2895-audit        running  https://gig-2895-terrablock.localhost:8643
  ❯ 3   gig-2896-terrablock   gig-2896-show-url     running  https://gig-2896-terrablock.localhost:8743
```

Run it from anywhere in the project — the repo root, a subfolder, or inside one
of the worktrees. It always reports on the whole repository, and marks (`❯`) the
environment you are standing in. `ngramx worktree --list` prints the same view.

The per-service health table (service, running state, health) is still there
behind `--services`, and reports on the environment in the current directory.

### `ngramx show-url` (alias: `ngramx url`)

Print the URL the current environment is reachable on — one plain line, ready to pipe:

```bash
ngramx show-url
ngramx url            # same thing
open "$(ngramx url)"
```

The URL follows the environment that is actually running:

- The web port is found wherever it is published — including on a proxy service
  (`nginx`, `caddy`) rather than the configured `primary_service` — and the port
  offset or per-port remap recorded in `.ngramx.lock` is applied to it.
- The port is omitted when it is the scheme's own default (`:80` for http,
  `:443` for https), and printed otherwise.
- Run from inside a worktree under `.ngramx/worktrees/`, it prints **that
  worktree's** URL (its own `<ticket>.localhost` hostname where the app answers
  to one, plus its offset port) rather than the shared canonical host.

### `ngramx shell`

Open an interactive bash shell inside the primary service container:

```bash
ngramx shell
```

This command:
1. Reads the `primary_service` from your `ngramx.yml` configuration
2. Opens an interactive bash shell in that container
3. Allows you to run commands, debug, or explore the container environment

Perfect for:
- Debugging issues in the container
- Running ad-hoc commands
- Exploring the container's filesystem
- Interactive development

### `ngramx secure`

Generate browser-trusted SSL certificates for your local development environment using [mkcert](https://github.com/FiloSottile/mkcert):

```bash
ngramx secure
```

This command:
1. Checks that `mkcert` is installed (prints platform-specific install instructions if not)
2. Installs the local CA into your system trust store (one-time setup)
3. Reads `docker.app_url` from your `ngramx.yml` to determine the hostname
4. Generates a trusted certificate and key in the SSL directory

The generated certificates replace any existing self-signed certs, so browsers will no longer show security warnings for your local HTTPS URLs.

**Installing mkcert:**

| Platform | Command |
|---|---|
| macOS | `brew install mkcert && mkcert -install` |
| Windows (Chocolatey) | `choco install mkcert && mkcert -install` |
| Windows (Scoop) | `scoop install mkcert && mkcert -install` |
| Linux | `sudo apt install libnss3-tools && brew install mkcert && mkcert -install` |

**Configuration:**

By default, certificates are written to `docker/nginx/ssl/`. You can override this with the optional `docker.ssl_path` key in `ngramx.yml`:

```yaml
docker:
  compose_file: "docker-compose.yml"
  primary_service: "app"
  app_url: "https://myapp.localhost"
  ssl_path: "docker/nginx/ssl"  # optional, this is the default
```

**When to run:** Once per project clone. The generated certificates are typically gitignored and don't need to be regenerated unless you change the hostname.

### `ngramx postmaclone`

Create a short-lived, **anonymized** database clone for bug root-cause analysis — without giving agents (or humans) production credentials or raw PII.

Anonymization is **opt-in by column**: only columns listed under `postmaclone.tables` are rewritten. Every other column is left unchanged.

```bash
# Create clone (prefer prebuilt when configured; else backup → anonymize → .env bind)
ngramx postmaclone
ngramx postmaclone --from-prod        # skip prebuilt; full local anonymize pipeline
ngramx postmaclone --from ./backups/prod.dump
ngramx postmaclone --from 'postgresql://readonly:…@host/db'
ngramx postmaclone --replace          # destroy existing clone, then create fresh

# Emit anonymization SQL only (no provision)
ngramx postmaclone --sql --from ./backups/prod.dump -o anonymize.sql

ngramx postmaclone status
ngramx postmaclone doctor             # check op CLI + S3 env (no auto-install)
ngramx postmaclone down               # destroy now (do not rely on TTL)

# Factory (separate postmaclone.yml — no app checkout):
ngramx postmaclone produce --all
ngramx postmaclone produce --dataset earl-kendrick
```

**Agent contract:** `postmaclone` → investigate/fix → `postmaclone down` per bug. Do not share one long-lived clone across agents — mutations from one session poison the next. TTL (`target.ttl_hours`, default 4h) is only a safety net. The anonymized Spaces bucket is an **artifact store**: each session **copies** the object to `.ngramx/cache` and restores into a **fresh** ephemeral target — never attach multiple agents to one restored DB.

**Targets:** Neon (Postgres, needs `NEON_API_KEY`), Docker (MySQL/MariaDB/Postgres), or `remote` (fresh in-region DO/Neon URL via `target.remote.url` / `POSTMACLONE_REMOTE_URL`). `provider: auto` (the default) picks `remote` for large artifacts when a remote URL is set, Neon for Postgres when `NEON_API_KEY` is present, otherwise Docker. **Sources:** published **prebuilt** (preferred for large DBs), local dump, S3 prod backup, or a connection string (dumped/read only — never anonymized in place).

#### Large DBs: factory produce + consumer prebuilt

Downloading and anonymizing multi‑GB Forge dumps on every laptop is untenable. Use a **factory repo** with `postmaclone.yml` (see `postmaclone.example.yml`) and a scheduled container job in the **same DO region** as Spaces + scratch Postgres:

1. Job: `ngramx postmaclone produce --all` — pull prod dump → restore scratch → anonymize → dump (optional `include_tables` / `exclude_tables`) → upload to a **separate anonymized bucket** + `latest.json`
2. App `ngramx.yml`: thin `postmaclone.prebuilt` (+ `target`) — consumer downloads a copy, restores, **skips anonymize**

Keep prod-read and anon-write credentials separate (`op://` refs). Local Docker remains fine for small artifacts; large restores should use in-region `remote` / Neon.

```yaml
# App ngramx.yml — consumer (prebuilt preferred)
postmaclone:
  engine: postgres
  prebuilt:
    source: s3
    path: "spaces://weathered-brook-anonymized-backups/earl-kendrick/"
    file: "earl_kendrick_anon.sql.gz"   # or omit file to follow latest.json
    max_age_hours: 36
    region: lon1
    endpoint: "https://lon1.digitaloceanspaces.com"
    credentials:
      key: "op://Tech Team Vault/ngramx-anon-backup-read/username"
      secret: "op://Tech Team Vault/ngramx-anon-backup-read/credential"
  target:
    provider: auto
    ttl_hours: 4
```

Forge dumps land in a **shared** daily folder (`database-backups/all/YYYYMMDDHHMMSS/`) with one object per project (e.g. `earl_kendrick_prod.sql.gz`). Configure the dump basename for *this* project; Postmaclone only auto-picks the newest dated folder — never which dump inside it.

```yaml
# Full local pipeline (small DBs / --from-prod)
postmaclone:
  engine: postgres
  seed: 42
  backup:
    source: s3
    # Option A: trailing "/" + file basename
    path: "spaces://weathered-brook-object-storage/database-backups/all/"
    file: "earl_kendrick_prod.sql.gz"
    # Option B: single-line glob (equivalent)
    # path: "spaces://weathered-brook-object-storage/database-backups/all/*/earl_kendrick_prod.sql.gz"
    region: lon1
    endpoint: "https://lon1.digitaloceanspaces.com"
    # 1Password secret references (safe to commit — not the raw key)
    credentials:
      key: "op://Tech Team Vault/ngramx-db-backup-read-access/username"
      secret: "op://Tech Team Vault/ngramx-db-backup-read-access/credential"
  target:
    provider: auto
    ttl_hours: 4
  tables:
    users:
      email: uniqueSafeEmail
      first_name: firstName
      # Chain formatters + literals (either form):
      # address_line_1: "{{numberBetween(1, 999)}} {{firstName}} Street"
      # address_line_1: 'numberBetween(1, 999) + " " + firstName + " Street"'
      # omit non-PII columns — they stay as-is
```

Faker values may be a single formatter (`firstName`, `uniqueSafeEmail`), a `{{formatter}}` template mixed with literal text, or a ` + `-joined expression of formatters and quoted strings. Formatter args work inside both forms (`numberBetween(1, 999)`, `numerify("###")`). Set `unique: true` on the column rule to uniquify the whole composed string.


**Spaces credentials (1Password):** put `op://` refs under `postmaclone.backup.credentials` in `ngramx.yml` (safe to commit). Item: **Tech Team Vault** → `ngramx-db-backup-read-access`. Plaintext keys in YAML are rejected.

```bash
ngramx postmaclone doctor   # auth + restore checks (prod roles/ACLs are stripped; clone login owns data)
ngramx postmaclone          # resolves refs via `op read` when a session (or service account) is available
```

| Who | How to authenticate |
|-----|---------------------|
| Human on **WSL** (typical for us) | Install [op](https://developer.1password.com/docs/cli/get-started/) **inside the distro**. Once: `op account add` (sign-in address `gigabytesoftware.1password.com`). Each shell: `eval $(op signin)`. `ngramx postmaclone doctor` detects WSL and prints these steps — it will **not** collect your password or assume Windows desktop-app CLI integration. |
| Human on macOS / native Linux | Prefer **1Password app → Developer → Integrate with 1Password CLI**, or the same `op account add` / `eval $(op signin)` flow. |
| Agent / CI | Ask an admin for a [service account](https://developer.1password.com/docs/service-accounts/) with **read** on Tech Team Vault, then `export OP_SERVICE_ACCOUNT_TOKEN=…` (non-interactive; no `op signin`). |
| Skip 1Password | Export `POSTMACLONE_S3_KEY` / `POSTMACLONE_S3_SECRET` (or `AWS_*`), or use `--from ./local.dump` / a connection URL. |

When `credentials` `op://` refs are present in YAML they are preferred over `POSTMACLONE_S3_*` / `AWS_*` so read vs write keys stay distinct. Env vars remain the fallback when no refs are configured.

**Docker target networking:** Postmaclone does **not** write `docker-compose.override.yml`. It stops the compose `db` service, attaches the clone to the project network with DNS alias `db` (so hardcoded `DB_HOST=db` keeps working), and updates mounted `.env` credentials only. Host-side tools use `127.0.0.1` and the published port. Bring the stack up (`ngramx up`) before a standalone `postmaclone` so the network exists.

`ngramx postmaclone down` and `ngramx down` remove the clone and start the real `db` service again; `ngramx down` also restores `.env` and tears down compose with `--remove-orphans`.

### Custom Commands

Run custom commands directly by name:

```bash
# Run a custom command
ngramx test

# List all available commands
ngramx list
```

Define custom commands in your `ngramx.yml`:

```yaml
commands:
  test:
    command: "php artisan test"
    description: "Run test suite"
  
  migrate:
    command: "php artisan migrate"
    description: "Run database migrations"
```

Your custom commands will appear alongside built-in commands and support tab completion!

### Parallel commands

If you want a single user command to fan out into several sub-commands that run concurrently, provide a list under `command:` instead of a string. Each entry runs inside the container at the same time and every sub-command must succeed for the command itself to succeed.

```yaml
commands:
  validate:
    command:
      - "composer validate --strict"
      - "vendor/bin/phpstan analyse src tests --level=8"
      - "vendor/bin/phpunit"
    description: "Run composer, phpstan and phpunit in parallel"
    timeout: 180
```

While the commands run, Ngramx shows a live panel with one row per sub-command in the form `<label>: <last log line>`. Labels are auto-derived from the first token of each command (e.g. `vendor/bin/phpstan analyse …` → `phpstan`); duplicate labels get `#2`, `#3` suffixes. Rows disappear as each sub-command finishes, and once they're all done the panel is cleared and the command reports completion.

If any sub-command fails or times out, every sub-command still runs to completion; Ngramx then prints a per-command status summary, shows the tail of the failed command's output, and the overall command exits with a non-zero status.

The list form is only supported for entries under `commands:`. Setup steps in `setup.pre_start` / `setup.initialize` continue to run sequentially, one `command:` string per entry.

#### Sequential lists (`parallel: false`)

The parallel default is only safe for **independent** sub-commands. When steps have ordering dependencies — install deps, then migrate, then clear caches — running them concurrently races. For example, on the database cache/session driver, `php artisan cache:clear` issues `delete from "cache"` and intermittently fails with `relation "cache" does not exist` because a concurrent `migrate:fresh` is mid-flight dropping and recreating tables; likewise an asset build can fail against half-installed dependencies.

Set `parallel: false` to run the list **one step at a time, in declaration order, stopping at the first failure**:

```yaml
commands:
  fresh:
    command:
      - "composer install --no-interaction"
      - "npm install && npm run build"
      - "php artisan migrate:fresh --seed --force"
      - "php artisan optimize:clear"
    description: "Install deps, build assets, rebuild the database, clear caches"
    parallel: false
    timeout: 600
```

Each step is printed as `[n/total] <command>` with its output streamed live; if a step fails, the remaining steps are skipped and the command exits non-zero. `parallel` only applies to the list form (it's an error to set it on a single-string `command:`).

### Recommended commands

Ngramx expects two lifecycle commands to be defined in every project so that `ngramx rebuild` and `ngramx review` can drive your environment consistently. If either is missing, Ngramx prints a warning on every invocation.

```yaml
commands:
  clear:
    command: "composer install && php artisan optimize:clear"
    description: "Fast sync after switching branches (install deps, clear caches — no DB changes)"

  fresh:
    command: "composer install && php artisan migrate:fresh --seed && php artisan optimize:clear"
    description: "Reset database from scratch (drop tables, re-migrate, re-seed, install deps, clear caches)"
```

- **`fresh`** is the default for `ngramx review` and is what `ngramx rebuild` runs after containers are back up. It does a full database reset so you always get a known-good state.
- **`clear`** is used by `ngramx review --quick`. It deliberately does **not** run migrations or seed data — it only installs deps and clears caches. Use it when you're reviewing branches that don't touch the database. If a branch has schema or seed changes, stick with the default `fresh`, since running migrations alone won't re-seed the new data.

## n8n Workflow Management

Ngramx CLI provides commands to manage n8n workflows, including exporting, importing, and normalizing credentials across different n8n instances.

### Prerequisites

All n8n commands require configuration in your `.env` file:

```env
NGRAMX_N8N_HOST=http://localhost
NGRAMX_N8N_PORT=5678
NGRAMX_N8N_API_KEY=your-api-key-here
```

The commands will prompt you for any missing values on first run.

### `ngramx n8n:export`

Export workflows from a running n8n instance to JSON files:

```bash
ngramx n8n:export              # Export all workflows
ngramx n8n:export --force      # Overwrite existing files
```

**What it does:**
1. Connects to the n8n instance specified in `.env`
2. Fetches all workflows via the n8n API
3. Saves each workflow as a JSON file in the directory specified by `n8n.workflows_dir` in `ngramx.yml`

**Configuration:**

Add to your `ngramx.yml`:

```yaml
n8n:
  workflows_dir: "./.n8n"  # Directory to save exported workflows
```

**Output:**
- Each workflow is saved as `{workflow-name}.json` in the workflows directory
- Files are skipped if they already exist (unless `--force` is used)

### `ngramx n8n:import`

Import workflows from JSON files into a running n8n instance:

```bash
ngramx n8n:import              # Import all workflows from workflows directory
ngramx n8n:import --force     # Overwrite existing workflows with same name
```

**What it does:**
1. Reads all `.json` files from the workflows directory
2. For each workflow:
   - If a workflow with the same name exists: updates it (unless `--force` is needed)
   - If no workflow exists: creates a new one
3. Cleans workflow data to remove read-only fields before sending to API

**Configuration:**

Uses the same `n8n.workflows_dir` configuration as export.

**Output:**
- Shows progress for each workflow being imported
- Reports any errors encountered during import

### `ngramx n8n:normalise`

Normalize workflow credentials by mapping them to credentials in a target n8n instance. This is essential when moving workflows between environments where credential names or IDs differ.

```bash
# Basic usage - validate and patch credentials
ngramx n8n:normalise workflow.json

# Output to file
ngramx n8n:normalise workflow.json --output patched-workflow.json

# Use credential mapping file
ngramx n8n:normalise workflow.json --map credentials.map.json

# Dry run - see what would change without modifying
ngramx n8n:normalise workflow.json --dry-run

# Validation only - don't patch, just check
ngramx n8n:normalise workflow.json --no-patch

# JSON report for CI/CD
ngramx n8n:normalise workflow.json --report json

# Non-strict mode - don't exit on errors
ngramx n8n:normalise workflow.json --no-strict
```

**What it does:**

1. **Extracts credentials** from the workflow JSON file
   - Identifies all credential references in workflow nodes
   - Builds a set keyed by `type:name` (e.g., `postgres:prod-db`)
   - Tracks which nodes use each credential (for helpful error messages)

2. **Fetches credentials** from the target n8n instance
   - Lists all credentials from the target n8n via API
   - Builds a lookup map: `(type, name) → [credentials]`

3. **Validates** credentials
   - Checks if all required credentials exist in target
   - Detects duplicate credentials (same type:name on target)
   - Reports missing credentials with node context
   - Exits with error code if strict mode is enabled (default)

4. **Patches credential IDs** into the workflow JSON
   - Updates credential IDs in workflow nodes to match target instance
   - Preserves credential names (useful for diffing and human readability)
   - Uses mapped credential names if `--map` is provided

5. **Outputs** the patched workflow
   - Writes to file (if `--output` specified) or stdout
   - Provides human-friendly or JSON reports

**Output Behavior:**

- **When using `--output <file>`**: Report is displayed in the console, and the patched workflow JSON is written to the specified file
- **When outputting to stdout** (no `--output`): Report is suppressed, and only the clean patched workflow JSON is written to stdout (perfect for piping/redirection)
- **When using `--no-patch`**: Only the validation report is shown (no workflow JSON output)
- **When using `--dry-run`**: Report is shown, but no workflow JSON is written

**Examples:**

```bash
# Output clean JSON to stdout (report suppressed) - good for piping
ngramx n8n:normalise workflow.json > patched.json

# Output to file with report in console
ngramx n8n:normalise workflow.json --output patched.json

# Validation only - just see the report
ngramx n8n:normalise workflow.json --no-patch
```

**Options:**

- `workflow` (required): Path to workflow JSON file
- `--map, -m <file>`: Path to credential mapping JSON file (see below)
- `--output, -o <file>`: Output file path (default: stdout, suppresses report)
- `--dry-run`: Show what would change without writing output
- `--no-patch`: Only validate, don't patch credentials (shows report only)
- `--report <format>`: Report format - `json` or `text` (default: `text`)
- `--no-strict`: Don't exit on missing/duplicate credentials (strict mode is on by default)

**Credential Mapping (`--map`)**

The `--map` option allows you to map credential names from your workflow to different credential names in the target n8n instance. This is essential when credential names differ between environments.

**Map File Format:**

The map file is a JSON object where:
- **Key**: Source credential key in format `type:name` (from workflow)
- **Value**: Target credential key in format `type:name` (in target n8n)

**Example `credentials.map.json`:**

```json
{
  "postgres:prod-db": "postgres:prod-db-v2",
  "stripeApi:billing": "stripeApi:stripe-prod",
  "httpBasicAuth:api-auth": "httpBasicAuth:api-auth-prod",
  "slackApi:notifications": "slackApi:slack-prod"
}
```

**How Mapping Works:**

1. **Name Resolution**: When a credential is found in the workflow, the command first checks if a mapping exists for that credential key
2. **Target Lookup**: If a mapping exists, it looks up the mapped credential name in the target n8n instance instead of the original name
3. **ID Patching**: The workflow nodes are updated with the credential ID from the mapped credential, while the original credential name is preserved in the workflow JSON

**Example Usage:**

**Scenario**: Your workflow references `postgres:prod-db`, but the target n8n instance has it named `postgres:prod-db-v2`.

**Without mapping:**
```bash
ngramx n8n:normalise workflow.json
# ❌ Error: MISS postgres:prod-db used by: Read Customers, Upsert Customer
```

**With mapping:**
```bash
# credentials.map.json
{
  "postgres:prod-db": "postgres:prod-db-v2"
}

ngramx n8n:normalise workflow.json --map credentials.map.json
# ✅ Success: Maps postgres:prod-db → postgres:prod-db-v2 → finds credential ID
# ✅ Patches workflow: Updates credential ID, keeps name as "prod-db"
```

**Example Output (Text Format):**

```
normalise: target http://localhost:5678
found 7 credential refs in workflow

OK   postgres:prod-db (id 12) used by: Read Customers, Upsert Customer
OK   slackApi:slack-notifications (id 44) used by: Notify Ops
MISS stripeApi:billing used by: Charge Card

error: 1 missing credential
hint: create credential "billing" of type "stripeApi" in target n8n, or provide --map
```

**Important Notes:**

- The workflow credential **name remains unchanged**; only the credential **ID** is updated
- The **original workflow file is never modified**; output goes to a new file or stdout
- Mapping is one-way: source → target
- If a mapped credential doesn't exist in the target, it will be reported as missing
- Mappings can help disambiguate duplicate credentials by explicitly specifying which target credential to use
- Strict mode (default) exits with error code if any credentials are missing or duplicated
- Use `--report json` for CI/CD integration and programmatic parsing
- When outputting to stdout (no `--output`), the report is suppressed to keep JSON clean for piping

## Configuration

### Basic Structure

```yaml
version: "1.0"  # Required

docker:
  compose_file: "docker-compose.yml"  # Required: Path to docker-compose file
  primary_service: "app"              # Required: Main service to run commands in
  wait_for:                           # Optional: Services to wait for
    - service: "db"
      timeout: 60
    - service: "app"                  # Real readiness, not just "running"
      timeout: 300
      healthcheck: true               # Prefer the container's Docker healthcheck if defined
      ready_command: "php artisan --version"  # Must exit 0 inside the container
      ready_log: "is ready!"          # Regex matched against the container logs

setup:
  pre_start:    # Optional: Commands to run on host before docker-compose up
    - command: "cp .env.example .env"
      description: "Create environment file"
      ignore_failure: true
      
  initialize:   # Optional: Commands to run in container after services start
    - command: "composer install"
      description: "Install dependencies"
      timeout: 300
      retry: 2

commands:       # Optional: Custom commands
  test:
    command: "php artisan test"
    description: "Run test suite"
```

### Command Properties

- `command` (required): The command to execute
- `description` (required): Human-readable description
- `timeout` (optional, default: 600): Timeout in seconds
- `retry` (optional, default: 0): Number of retry attempts
- `ignore_failure` (optional, default: false): Continue even if command fails

### Readiness Probes (`wait_for`)

Before running post-up commands (`fresh`, `clear`, custom commands) and during
`review` / `review --worktree`, Ngramx waits for each `wait_for` service to be
**genuinely ready** — not merely "running". A container often reports `running`
within a couple of seconds while its entrypoint is still installing
dependencies, running migrations, or building assets for minutes. Firing
`docker compose exec` at it during that window fails with errors like
`service "app" is not running` or races migrations.

Each `wait_for` entry supports:

- `service` (required): Compose service name to wait for.
- `timeout` (required): Per-service readiness budget in seconds.
- `healthcheck` (optional, default: false): Prefer the container's Docker
  healthcheck (`.State.Health.Status == healthy`) when one is defined.
- `ready_command` (optional): A command that must exit `0` inside the container,
  e.g. `php artisan --version`. Retried with backoff until it succeeds.
- `ready_log` (optional): A regular expression matched against the container's
  recent logs, e.g. `is ready!`.

Probes are evaluated in priority order: `healthcheck` → `ready_command` →
`ready_log`. When none are configured, Ngramx auto-uses a Docker healthcheck if
the container declares one, otherwise it falls back to the weak "running" signal
and warns that readiness is not actually being verified.

```yaml
wait_for:
  - service: app
    timeout: 300
    healthcheck: true
    ready_command: "php artisan --version"
    ready_log: "is ready!"
```

**Crash-loop detection:** while waiting, if a target (or any other) container
enters `Restarting`/`Exited`/`Dead` or its restart count climbs, Ngramx aborts
immediately, dumps the last ~50 log lines from that container, and exits
non-zero — instead of hanging or firing exec commands at a dead container.

The legacy `wait_for: [{ service, timeout }]` shape continues to work unchanged.

### Agent instructions and skills (`agents`)

Ngramx distributes a managed set of agent instructions (architecture, DB, ticket
workflow conventions) and skills (`start-ticket`, `create-pr`, …) into each
project. `ngramx sync-agents` — also run as part of `ngramx up` — regenerates
these from the bundled templates.

The optional `agents` block controls **where** that content is written. Both the
rules (`targets`) and the skills (`skills`) respect these settings:

```yaml
agents:
  # Managed rule/instruction destinations.
  # Valid: agents_md, cursor_rules, claude_md, copilot_instructions
  # Default: [agents_md, cursor_rules]
  targets:
    - agents_md             # AGENTS.md (managed block, read by most agents)
    - cursor_rules          # .cursor/rules/ngramx.mdc
    - claude_md             # CLAUDE.md
    - copilot_instructions  # .github/copilot-instructions.md

  # Skill folder destinations.
  # Valid: cursor, claude
  # Default: [cursor]
  skills:
    - cursor                # .cursor/skills/<name>/SKILL.md
    - claude                # .claude/skills/<name>/SKILL.md
```

- Omit the whole `agents:` block to accept the defaults (`agents_md` +
  `cursor_rules` for rules, `cursor` for skills).
- **Claude is opt-in.** To distribute the rules and skills to Claude as well as
  Cursor, add `claude_md` to `targets` and `claude` to `skills`.
- Unknown values are rejected at load time, so a typo fails fast rather than
  silently skipping a target.

## Tab Completion

Tab completion is automatically installed by the install script. To set it up manually, see [COMPLETION.md](COMPLETION.md).

## Development

### Docker Development Environment (Recommended)

Ngramx CLI uses itself for development! Simply run:

```bash
# Start the development environment
./bin/ngramx up

# Run tests
./bin/ngramx test

# Run static analysis
./bin/ngramx phpstan

# Fix code style
./bin/ngramx cs-fix

# Build the PHAR
./bin/ngramx build

# Run all validation checks
./bin/ngramx validate

# Open a shell in the container
./bin/ngramx shell
```

All PHP dependencies, extensions (including Xdebug for coverage), and tools are pre-configured in the Docker container.

### Local Development (Without Docker)

If you prefer to develop without Docker:

**Requirements:**
- PHP 8.2 or 8.3
- Composer
- PHP extensions: mbstring, xml, curl
- Xdebug (optional, for code coverage)

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run static analysis
composer phpstan

# Fix code style
composer cs-fix
```

### Building from Source

See [BUILD.md](BUILD.md) for detailed instructions on building the PHAR.

### Release

See [RELEASE.md](RELEASE.md) for details on how releases work.

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our development workflow.

**Quick start for contributors:**
```bash
./bin/ngramx up      # Start dev environment
./bin/ngramx test    # Run tests
./bin/ngramx validate # Run all checks
```

This project follows PSR-12 coding standards and uses PHP 8.2+ features. See [dev-docs](dev-docs/) for additional documentation.

## License

See [LICENSE](LICENSE) file for details.

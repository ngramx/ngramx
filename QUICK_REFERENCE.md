# Multi-Instance Quick Reference

## Command Options

```bash
ngramx up [OPTIONS]

Options:
  --namespace <id>       Custom container namespace prefix
  --port-offset <num>    Port offset to add to all exposed ports
  --avoid-conflicts      Auto-generate namespace and port offset
  --no-wait             Skip health checks
  --skip-init           Skip initialize commands
```

## Common Use Cases

### Single Developer (Default)
```bash
ngramx up
ngramx down
```

### Multi-Agent Orchestrator (Simple)
```bash
ngramx up --avoid-conflicts
```

### Multi-Agent Orchestrator (Controlled)
```bash
# Agent 1
ngramx up --namespace agent-1 --port-offset 1000

# Agent 2
ngramx up --namespace agent-2 --port-offset 2000
```

## Lock File

**Location:** `.ngramx.lock`

**Created when:**
- Using `--avoid-conflicts`
- Using `--namespace`
- Using `--port-offset`

**Prevents:** Duplicate instances in same directory

**Cleaned up:** Automatically by `ngramx down`

## Namespace Resolution

**Default:** Directory-based
```
/workspace/agent-1/project/ → ngramx-agent-1-project
```

**Override:** Use `--namespace` option

## Port Offset

**Default:** 0 (no offset)

**Auto mode:** Scans 8000-9000 range

**Explicit:** Use `--port-offset <num>`

**Applied to:** ALL exposed ports in docker-compose.yml

## Example Workflow

```bash
# In directory: /workspace/agent-1/project/

# Start with auto-conflicts avoidance
$ ngramx up --avoid-conflicts

# Check status
$ ngramx status
Environment Status
Namespace: ngramx-agent-1-project
Port offset: +8000

# Application accessible at http://localhost:8080

# Stop
$ ngramx down
```

## For Orchestrators

### Reading Port Information

```bash
$ cat .ngramx.lock | jq -r '.port_offset'
8000
```

### Setting via Environment

```bash
export NGRAMX_NAMESPACE="agent-${TASK_ID}"
export NGRAMX_PORT_OFFSET=$((1000 * AGENT_NUM))

ngramx up --namespace "$NGRAMX_NAMESPACE" --port-offset "$NGRAMX_PORT_OFFSET"
```

## Troubleshooting

### "Already running" error
```bash
# Solution: Stop existing instance first
ngramx down
ngramx up
```

### Port conflicts
```bash
# Solution: Use auto mode or explicit offset
ngramx up --avoid-conflicts
# or
ngramx up --port-offset 5000
```

### "no such file or directory" mounting a config file (Docker Desktop + WSL)

```
error mounting "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/<hash>"
to rootfs at "/usr/local/etc/php/conf.d/local.ini": no such file or directory
```

Docker Desktop stages every WSL bind mount under a hash of its host path. A
single-file mount pins one inode, so replacing the file on the host (a checkout,
a branch switch, an editor that writes-then-renames) leaves the staged mount
pointing at a deleted inode — and the next container create fails. Deleting a
worktree leaves the same corpses behind for the path it occupied.

ngramx clears these itself before starting containers, and retries once if the
engine reports one anyway. When it cannot (removing a mount needs root, and
there is no TTY to ask for a password) it prints the exact command:

```bash
sudo umount /mnt/wsl/docker-desktop-bind-mounts/<distro>/<hash>
```

Avoid the problem entirely by mounting the *directory* rather than the file
(`./docker/php:/usr/local/etc/php/conf.d`), or by baking config into the image
with `COPY`.

### Finding namespace
```bash
# Check lock file
cat .ngramx.lock | jq -r '.namespace'

# Or use status command
ngramx status
```


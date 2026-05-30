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

### Finding namespace
```bash
# Check lock file
cat .ngramx.lock | jq -r '.namespace'

# Or use status command
ngramx status
```


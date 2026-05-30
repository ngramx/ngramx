# Multi-Instance Support Implementation

## Overview

Ngramx CLI now supports running multiple instances of the same project simultaneously without conflicts. This is achieved through namespace isolation and port offset management.

## Key Features

✅ **Directory-based namespaces** - Automatically derives container namespace from directory path
✅ **Port offset management** - Automatically scans and allocates available ports
✅ **Lock file tracking** - Prevents duplicate instances in the same directory
✅ **Zero configuration** - Works out of the box for single instances
✅ **Explicit control** - Full control when needed for orchestrators

## Usage

### Default Mode (Single Instance)

```bash
ngramx up
```

- No namespace prefix or port changes
- Uses ports from docker-compose.yml as-is
- Perfect for normal development

### Auto Conflict Avoidance

```bash
ngramx up --avoid-conflicts
```

- Auto-generates namespace from directory path
- Auto-scans and allocates available ports
- Single flag for multi-instance mode
- Perfect for coding agent orchestrators

### Explicit Control

```bash
# Custom namespace
ngramx up --namespace agent-1

# Custom port offset
ngramx up --port-offset 1000

# Both
ngramx up --namespace agent-1 --port-offset 1000
```

## Technical Implementation

### New Components

1. **LockFile** (`src/Config/LockFile.php`)
   - Manages `.ngramx.lock` file
   - Prevents duplicate instances per directory
   - Stores namespace and port offset

2. **NamespaceResolver** (`src/Docker/NamespaceResolver.php`)
   - Derives namespace from directory path
   - Example: `/workspace/agent-1/project/` → `ngramx-agent-1-project`
   - Validates custom namespaces

3. **PortOffsetManager** (`src/Docker/PortOffsetManager.php`)
   - Extracts base ports from docker-compose.yml
   - Scans for available ports (range 8000-9000)
   - Applies offset to all exposed ports

4. **ComposeOverrideGenerator** (`src/Docker/ComposeOverrideGenerator.php`)
   - Generates `docker-compose.override.yml` with port offsets
   - Cleaned up automatically on `ngramx down`

### Modified Components

1. **DockerCompose** - Added `-p` (project name) flag support
2. **ContainerExecutor** - Added project name support for exec commands
3. **HealthChecker** - Added project name support for health checks
4. **SetupOrchestrator** - Added namespace and port offset parameters
5. **UpCommand** - Added new options and lock file management
6. **DownCommand** - Added lock file cleanup and namespace resolution
7. **StatusCommand** - Added namespace and port offset display
8. **ShellCommand** - Added namespace support for interactive shells

## Lock File Format

`.ngramx.lock` stores minimal state:

```json
{
  "namespace": "ngramx-agent-1-project",
  "port_offset": 8000,
  "started_at": "2025-11-08T10:30:00+00:00"
}
```

Only created when using:
- `--avoid-conflicts` flag
- `--namespace` option
- `--port-offset` option

## Examples

### Example 1: Default Developer Workflow

```bash
$ ngramx up

🚀 Starting Development Environment
✅ Environment ready!
🌐 http://localhost:80
```

### Example 2: Multi-Agent with Auto Mode

```bash
$ pwd
/workspace/agent-1/project

$ ngramx up --avoid-conflicts

🚀 Starting Development Environment

Auto-generated namespace: ngramx-agent-1-project
Scanning for available ports...
Port offset allocated: +8000

✅ Environment ready!
🌐 app: http://localhost:8080
📝 Instance details saved to .ngramx.lock
```

### Example 3: Multi-Agent with Explicit Control

```bash
$ ngramx up --namespace agent-2 --port-offset 1000

🚀 Starting Development Environment

Using namespace: agent-2

✅ Environment ready!
🌐 app: http://localhost:1080
📝 Instance details saved to .ngramx.lock
```

### Example 4: Status Command

```bash
$ ngramx status

Environment Status
Namespace: ngramx-agent-1-project
Port offset: +8000
Started: 2025-11-08T10:30:00+00:00

Service  Status   Health
app      running  healthy
db       running  healthy
```

### Example 5: Shell Command

```bash
$ ngramx shell
# Opens interactive shell in the correct namespace
```

### Example 6: Down Command

```bash
$ ngramx down

Stopping environment
Docker services stopped

Environment stopped successfully
```

Automatically:
- Reads namespace from lock file
- Stops containers with correct namespace
- Cleans up override file
- Deletes lock file

## Port Offset Behavior

All exposed ports in `docker-compose.yml` receive the same offset:

```yaml
# Original docker-compose.yml
services:
  app:
    ports:
      - "80:80"
  db:
    ports:
      - "5432:5432"
```

With `--port-offset 1000`:

```yaml
# Generated docker-compose.override.yml
services:
  app:
    ports:
      - "1080:80"      # 80 + 1000
  db:
    ports:
      - "6432:5432"    # 5432 + 1000
```

## Namespace Derivation

Namespaces are derived from the last 2 segments of the directory path:

| Directory                      | Namespace                    |
|--------------------------------|------------------------------|
| `/workspace/agent-1/project/`  | `ngramx-agent-1-project`     |
| `/home/user/myapp/`            | `ngramx-user-myapp`          |
| `/projects/acme/backend/`      | `ngramx-acme-backend`        |

## One Instance Per Directory

The lock file enforces one active instance per directory:

```bash
$ ngramx up
✅ Environment ready!

$ ngramx up
❌ Environment already running in this directory.
   Use "ngramx down" to stop it first.
```

This is perfect for coding agents because:
- Each agent has its own working directory
- Can't accidentally start duplicate instances
- Prevents resource conflicts

## Integration with Orchestrators

Orchestrators can use environment variables for configuration:

```bash
export NGRAMX_INSTANCE_ID="agent-${AGENT_NUM}"
export NGRAMX_PORT_OFFSET=$((1000 * AGENT_NUM))

ngramx up --namespace "$NGRAMX_INSTANCE_ID" --port-offset "$NGRAMX_PORT_OFFSET"
```

Or use the simple mode:

```bash
ngramx up --avoid-conflicts
```

Then read `.ngramx.lock` to discover allocated ports:

```bash
$ cat .ngramx.lock
{
  "namespace": "ngramx-agent-1-project",
  "port_offset": 8000,
  "started_at": "2025-11-08T10:30:00+00:00"
}
```

## Files Modified

### New Files
- `src/Config/LockFile.php`
- `src/Config/LockFileData.php`
- `src/Docker/NamespaceResolver.php`
- `src/Docker/PortOffsetManager.php`
- `src/Docker/ComposeOverrideGenerator.php`

### Modified Files
- `src/Application.php`
- `src/Command/UpCommand.php`
- `src/Command/DownCommand.php`
- `src/Command/StatusCommand.php`
- `src/Command/ShellCommand.php`
- `src/Docker/DockerCompose.php`
- `src/Docker/ContainerExecutor.php`
- `src/Docker/HealthChecker.php`
- `src/Executor/ContainerCommandExecutor.php`
- `src/Orchestrator/SetupOrchestrator.php`
- `.gitignore`

## Testing

Test the implementation:

```bash
# Test default mode
ngramx up
ngramx status
ngramx shell
ngramx down

# Test auto mode
ngramx up --avoid-conflicts
ngramx status
ngramx down

# Test explicit mode
ngramx up --namespace test-1 --port-offset 2000
ngramx status
ngramx down

# Test lock file prevention
ngramx up
ngramx up  # Should fail with "already running" message
ngramx down
```

## Backward Compatibility

✅ **Fully backward compatible** - Default behavior unchanged
✅ **No breaking changes** - All existing commands work as before
✅ **Opt-in features** - New options are optional

## Summary

This implementation provides powerful multi-instance support while keeping the default behavior simple and unchanged. It's perfect for:

- **Individual developers** - Works exactly as before
- **Coding agent orchestrators** - One-flag multi-instance mode
- **Advanced users** - Full explicit control when needed

The implementation follows the principle: **Simple by default, powerful when needed** 🚀


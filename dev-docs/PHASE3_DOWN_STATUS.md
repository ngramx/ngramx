# Phase 3 (Partial) - Down & Status Commands

## ✅ What Was Implemented

Two new commands have been added to Ngramx CLI:
1. **`ngramx down`** - Stop Docker services
2. **`ngramx status`** - Check service status

## 1. Down Command

### Features
- Stops all Docker Compose services
- Optional `--volumes` flag to remove volumes
- Uses same beautiful purple/teal color scheme
- Finds ngramx.yml automatically

### Usage

```bash
# Stop services (keep volumes)
ngramx down

# Stop services and remove volumes
ngramx down --volumes
```

### Output Example

```
▸ Stopping environment
  Docker services stopped

Environment stopped successfully
```

### Implementation
**File:** `src/Command/DownCommand.php`

- Loads ngramx.yml to get compose file path
- Calls `DockerCompose::down()` with optional volumes flag
- Shows purple success message
- Handles errors gracefully

## 2. Status Command

### Features
- Shows all running services in a table
- Color-coded status and health indicators
- Checks if services are running
- Helpful message if nothing is running

### Usage

```bash
ngramx status
```

### Output Example

When services are running:
```
▸ Service Status

┌─────────┬──────────┬─────────┐
│ Service │ Status   │ Health  │
├─────────┼──────────┬─────────┤
│ app     │ running  │ healthy │
│ db      │ running  │ healthy │
└─────────┴──────────┴─────────┘
```

When nothing is running:
```
▸ Service Status
  No services are currently running
  Run "ngramx up" to start the environment
```

### Color Coding

**Status:**
- 🟢 Green = running
- 🔴 Red = exited
- 🟡 Yellow = other states

**Health:**
- 🟢 Green = healthy, running
- 🔴 Red = unhealthy
- 🟡 Yellow = starting
- ⚪ Gray = unknown, no healthcheck

### Implementation
**File:** `src/Command/StatusCommand.php`

- Loads ngramx.yml
- Calls `DockerCompose::ps()` to get service list
- Calls `HealthChecker::getHealthStatus()` for each service
- Uses Symfony Console Table component
- Color codes status based on state

## Files Modified

### New Files (2)
```
src/Command/DownCommand.php
src/Command/StatusCommand.php
```

### Modified Files (1)
```
src/Application.php - Registered new commands
```

## Testing

### Manual Test Workflow

```bash
# 1. Navigate to test directory
cd /home/rob/projects/ngramx/tests/fixtures

# 2. Check commands are registered
../../bin/ngramx list

# You should see:
# - down
# - status
# - up

# 3. Start environment
../../bin/ngramx up

# 4. Check status (should show running services)
../../bin/ngramx status

# 5. Stop services
../../bin/ngramx down

# 6. Check status again (should show no services)
../../bin/ngramx status

# 7. Clean up with volumes
../../bin/ngramx down --volumes
```

### Expected Behavior

| Command | When Services Running | When Services Stopped |
|---------|----------------------|----------------------|
| `ngramx status` | Shows table with services | "No services running" |
| `ngramx down` | Stops services | Shows error or "already stopped" |
| `ngramx down -v` | Stops + removes volumes | Shows error or "already stopped" |

## Integration with Existing Commands

### Typical Workflow

```bash
# Start your dev environment
ngramx up

# Check everything is running
ngramx status

# Work on your project...

# Stop when done
ngramx down

# Or stop and clean volumes
ngramx down --volumes
```

## Command Help

### Down Command Help
```bash
ngramx down --help

Description:
  Tear down the development environment

Usage:
  down [options]

Options:
  -v, --volumes         Remove volumes as well
  -h, --help            Display help
```

### Status Command Help
```bash
ngramx status --help

Description:
  Check the health status of services

Usage:
  status
```

## Technical Details

### DownCommand
- **Dependencies:** ConfigLoader, DockerCompose
- **Options:** --volumes (-v)
- **Error Handling:** Catches ConfigException and generic exceptions
- **Output:** Purple success message on completion

### StatusCommand
- **Dependencies:** ConfigLoader, DockerCompose, HealthChecker
- **Options:** None
- **Error Handling:** Handles no config, no services gracefully
- **Output:** Symfony Console Table with color-coded cells

## Color Scheme

Both commands use the Gigabyte brand colors:
- **Purple (#7D55C7)** - Success messages
- **Teal (#2ED9C3)** - Section headers with ▸ arrow
- **Smoke (#D2DCE5)** - Status messages
- **Green/Red/Yellow** - Status indicators in table

## Edge Cases Handled

### DownCommand
- ✅ No ngramx.yml found
- ✅ Services already stopped
- ✅ Invalid compose file path
- ✅ Docker not running

### StatusCommand
- ✅ No ngramx.yml found
- ✅ No services running
- ✅ Services without healthchecks
- ✅ Docker not running
- ✅ Empty service list

## What's Next

These two commands complete the basic lifecycle:
- ✅ `ngramx up` - Start environment
- ✅ `ngramx status` - Check environment
- ✅ `ngramx down` - Stop environment

**Not Yet Implemented (Future):**
- Real-time output streaming (separate commit)
- SetupOrchestrator refactoring (optional)
- Integration tests (optional)

## Success Criteria

Down & Status commands are working if:
- ✅ Both commands show in `ngramx list`
- ✅ `ngramx down` stops Docker services
- ✅ `ngramx down --volumes` removes volumes
- ✅ `ngramx status` shows running services in table
- ✅ `ngramx status` shows helpful message when nothing running
- ✅ Colors match Gigabyte brand
- ✅ Error messages are clear and helpful

## Quick Start

Test the new commands right now:

```bash
cd /home/rob/projects/ngramx/tests/fixtures
../../bin/ngramx up && ../../bin/ngramx status && ../../bin/ngramx down
```

This will:
1. Start services
2. Show status table
3. Stop services

All in one line!


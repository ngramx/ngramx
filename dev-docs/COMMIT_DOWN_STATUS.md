# Commit: Add Down and Status Commands

## Summary

Added two essential lifecycle management commands to Ngramx CLI:
- `ngramx down` - Stop Docker services
- `ngramx status` - Check service health

## Changes

### New Files (2)
- `src/Command/DownCommand.php` - Tear down Docker environment
- `src/Command/StatusCommand.php` - Display service status table

### Modified Files (2)
- `src/Application.php` - Registered new commands
- `README.md` - Updated documentation

### Documentation (1)
- `PHASE3_DOWN_STATUS.md` - Comprehensive implementation guide

## Features

### DownCommand
- Stops all Docker Compose services
- `--volumes` flag to remove volumes (no shortcut to avoid conflict with -v verbose)
- Loads config to find compose file
- Beautiful purple success message
- Error handling for missing config

### StatusCommand
- Shows service status in formatted table
- Color-coded status (green=running, red=exited)
- Color-coded health (green=healthy, red=unhealthy, yellow=starting)
- Handles "no services running" gracefully
- Uses HealthChecker to get detailed health info

## Usage

```bash
# Start environment
ngramx up

# Check what's running
ngramx status

# Stop services (keep volumes)
ngramx down

# Stop and remove volumes
ngramx down --volumes
```

## Testing

Manual test sequence:
```bash
cd tests/fixtures
../../bin/ngramx up
../../bin/ngramx status    # Should show running services
../../bin/ngramx down
../../bin/ngramx status    # Should show no services
```

## Design

Both commands follow established patterns:
- Use Gigabyte brand colors (purple, teal, smoke)
- Consistent error handling
- Clean, minimal output
- Helpful messages

## Status Table Example

```
▸ Service Status

┌─────────┬──────────┬─────────┐
│ Service │ Status   │ Health  │
├─────────┼──────────┼─────────┤
│ app     │ running  │ healthy │
│ db      │ running  │ healthy │
└─────────┴──────────┴─────────┘
```

## What's Next

- Real-time output streaming (separate commit)
- Custom commands (Phase 4)
- PHAR build (Phase 5)

## Commit Message Suggestion

```
feat: add down and status commands

- Add ngramx down command to stop Docker services
- Add ngramx down --volumes to remove volumes
- Add ngramx status command with service health table
- Color-coded status indicators (green/red/yellow)
- Update README with new commands
- Add comprehensive documentation

Completes Phase 3 lifecycle management commands.
```


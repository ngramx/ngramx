# Testing Phase 2 Implementation

## Quick Test

The fastest way to test everything:

```bash
cd /home/rob/projects/ngramx/tests/fixtures
../../bin/ngramx up
```

You should see:
1. Pre-start command output
2. Docker services starting
3. Health check for app service
4. PHP version from inside container
5. Initialization complete message
6. Purple "Environment ready!" message

## Clean Up

```bash
cd /home/rob/projects/ngramx/tests/fixtures
docker-compose -f docker-compose.test.yml down -v
```

## Run Unit Tests

First, make sure dev dependencies are installed:

```bash
cd /home/rob/projects/ngramx
composer install  # Install with dev dependencies
```

Then run tests:

```bash
# Run all tests
vendor/bin/phpunit

# Run only Phase 2 tests
vendor/bin/phpunit tests/Unit/Executor/
vendor/bin/phpunit tests/Unit/Docker/HealthCheckerTest.php

# Run with verbose output
vendor/bin/phpunit --verbose
```

## Test Individual Components

### Test HostCommandExecutor
```bash
vendor/bin/phpunit tests/Unit/Executor/HostCommandExecutorTest.php
```

Tests:
- ✓ Executes successful commands
- ✓ Handles failed commands
- ✓ Captures output
- ✓ Measures execution time
- ✓ Respects timeouts

### Test ExecutionResult
```bash
vendor/bin/phpunit tests/Unit/Executor/ExecutionResultTest.php
```

Tests:
- ✓ Creates result with all properties
- ✓ isSuccessful() returns correct value

### Test HealthChecker
```bash
vendor/bin/phpunit tests/Unit/Docker/HealthCheckerTest.php
```

Tests:
- ✓ Can be instantiated
- ✓ Returns 'unknown' for nonexistent services

## Test With Your Own Project

1. Create a `ngramx.yml` in your project:

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
      description: "Install dependencies"
      timeout: 300
```

2. Run ngramx:

```bash
/home/rob/projects/ngramx/bin/ngramx up
```

## Expected Output Colors

- **Purple** (`#7D55C7`) - Header and completion message
- **Teal** (`#2ED9C3`) - Section headers with `▸` arrow
- **Smoke** (`#D2DCE5`) - All status messages and command output
- **Red** - Errors only
- **Green** - (Currently not used in Phase 2)

## Troubleshooting

### "Service not healthy" error
- Check if service has healthcheck in docker-compose.yml
- Increase timeout in ngramx.yml
- Check logs: `docker-compose logs <service>`

### "Command failed" error
- Check command syntax in ngramx.yml
- Try running command manually: `docker-compose exec app <command>`
- Set `ignore_failure: true` to continue on errors

### Container not found
- Make sure Docker services are running
- Check service name matches docker-compose.yml
- Verify compose file path is correct

## What To Check

After running `ngramx up`:

1. ✅ Pre-start commands executed on host
2. ✅ Docker containers are running: `docker-compose ps`
3. ✅ Health checks passed (no timeout errors)
4. ✅ Initialize commands executed inside container
5. ✅ Output is colored correctly
6. ✅ Execution time is shown
7. ✅ Environment ready message appears

## Test Scenarios

### Test ignoreFailure flag
Modify `ngramx.yml`:
```yaml
pre_start:
  - command: "exit 1"
    description: "This will fail but continue"
    ignore_failure: true
```

Should continue despite failure.

### Test timeout
```yaml
initialize:
  - command: "sleep 60"
    description: "This will timeout"
    timeout: 5
```

Should fail with timeout after 5 seconds.

### Test command output
```yaml
initialize:
  - command: "php -v && php -m | head -10"
    description: "Show PHP info"
```

Should display PHP version and modules.

## Success Criteria

Phase 2 is working if:
- ✅ Pre-start commands run on host
- ✅ Docker services start
- ✅ Health checks wait for services
- ✅ Initialize commands run in container
- ✅ Command output is displayed
- ✅ Errors are handled properly
- ✅ Colors match Gigabyte brand
- ✅ All unit tests pass


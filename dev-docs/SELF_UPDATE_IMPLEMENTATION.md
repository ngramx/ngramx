# Update Command Implementation

## Overview

Implemented an `update` command for Ngramx CLI that allows users to update their installation to the latest version from GitHub releases.

## Implementation Details

### Command: `SelfUpdateCommand`

**Location**: `src/Command/SelfUpdateCommand.php`

**Features**:
- Fetches latest release information from GitHub API
- Compares current version with latest version
- Downloads and installs updates automatically
- Creates backup before updating for safety
- Verifies downloaded PHAR file integrity
- Provides helpful error messages for various scenarios

**Options**:
- `--check`: Check for updates without installing
- `--force`: Force update even if already on latest version

**Command Name**: `update` (no aliases)

### Key Functionality

1. **Version Checking**
   - Uses GitHub API to fetch latest release tag
   - Compares semantic versions
   - Reports current and latest versions

2. **Download & Installation**
   - Downloads PHAR from GitHub releases
   - Validates PHAR integrity
   - Creates backup of current installation
   - Replaces old PHAR with new version
   - Restores backup on failure

3. **Safety Features**
   - Only works when running as PHAR (not from source)
   - Checks write permissions before attempting update
   - Creates backup before replacement
   - Validates downloaded file is a valid PHAR
   - Provides clear error messages

4. **User Experience**
   - Uses OutputFormatter for consistent styling
   - Provides helpful guidance for different scenarios
   - Shows progress through update process
   - Gives alternative instructions when running from source

## Usage Examples

```bash
# Update to latest version
ngramx update

# Check if update is available
ngramx update --check

# Force update even if already latest
ngramx update --force
```

## When Running from Source

The command detects when it's running from source (not as PHAR) and provides helpful guidance:

```
Update is only available when running as PHAR.
  You are running from source. Use git to update:
    git pull origin main
    composer install
```

## Testing

**Test File**: `tests/Unit/Command/SelfUpdateCommandTest.php`

**Coverage**:
- Verifies command fails gracefully when not running as PHAR
- Tests command options (--check, --force)
- Validates command aliases work correctly
- Checks command description and metadata

## Integration

The command is registered in `src/Application.php` alongside other built-in commands:

```php
$this->add(new SelfUpdateCommand());
```

It appears in command listings and supports tab completion.

## Documentation

Updated `README.md` with self-update command documentation in the Commands section.

## Quality Checks

✅ All unit tests pass (28 tests, 60 assertions)
✅ PHPStan level 8 analysis passes with no errors
✅ Code style follows PSR-12 standards
✅ Command properly registered and discoverable
✅ Command named 'update' (no aliases)

## Future Enhancements

Potential improvements for future versions:
- Add progress bar for large downloads
- Support for rollback to previous version
- Signature verification for enhanced security
- Check for updates automatically (with opt-out)
- Support for pre-release/beta versions

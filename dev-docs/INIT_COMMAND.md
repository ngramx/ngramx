# Ngramx Init Command

## Overview

The `ngramx init` command initializes a new Ngramx project by creating the `.ngramx/` directory structure and an example `ngramx.yml` configuration file.

## Usage

```bash
# Initialize Ngramx in current directory
ngramx init

# Force overwrite existing files
ngramx init --force

# Only create .ngramx directory, skip ngramx.yml
ngramx init --skip-yaml
```

## What It Creates

When you run `ngramx init`, it creates the following structure:

```
.
├── .ngramx/
│   ├── README.md              # Full documentation about .ngramx folder
│   ├── tickets/
│   │   └── .gitkeep           # Keeps directory in git
│   ├── specs/                 # Cucumber/Gherkin feature specifications
│   └── meetings/              # Meeting notes directory
└── ngramx.yml                 # Example configuration file
```

## Options

### `--force` / `-f`

Overwrite existing files if they already exist.

```bash
ngramx init --force
```

Without this flag, the command will fail if `ngramx.yml` or `.ngramx/` already exists.

### `--skip-yaml`

Create only the `.ngramx/` directory structure without generating `ngramx.yml`.

```bash
ngramx init --skip-yaml
```

Useful if you want to create a custom `ngramx.yml` from scratch or already have one.

## Templates

The init command uses template files located in the `templates/` directory:

- `templates/ngramx.yml.template` - Example Ngramx configuration
- `templates/ngramx-readme.md.template` - .ngramx folder documentation

You can edit these templates to customize what gets generated for new projects.

### Template Location

- **Running from source**: Templates are in `/workspace/templates/`
- **Running as PHAR**: Templates are bundled inside the PHAR file

## The .ngramx Folder

The `.ngramx/` folder is the knowledge base for your project. It contains:

### `tickets/`

Per-ticket context and planning. Each Linear ticket gets its own subfolder:

```
tickets/
└── CORE-123/
    ├── README.md      # Human-readable overview
    ├── ticket.json    # Machine-readable Linear data
    ├── plan.md        # Implementation plan
    ├── specs.md       # Links to related feature specs
    └── assets/        # Screenshots, mockups, diagrams
```

### `specs/`

Cucumber/Gherkin feature specifications organized by product feature (not by ticket):

```
specs/
├── invoice-creation.feature
├── invoice-editing.feature
└── shared/
    └── authentication-steps.feature
```

### `meetings/`

Meeting notes organized chronologically:

```
meetings/
├── 2025-10/
│   ├── 15-daily-standup.md
│   └── 20-sprint-planning.md
└── 2025-09/
    └── ...
```

## Generated ngramx.yml

The generated `ngramx.yml` includes:

- Docker Compose configuration
- Primary service definition
- Service wait configuration
- Pre-start commands
- Initialization commands
- Example custom commands

You should customize this file for your specific project.

## Example Workflow

```bash
# 1. Navigate to your project
cd ~/projects/my-app

# 2. Initialize Ngramx
ngramx init

# 3. Review and customize the generated files
vim ngramx.yml
cat .ngramx/README.md

# 4. Start your environment
ngramx up
```

## Error Handling

### Already Initialized

If Ngramx is already initialized (`.ngramx/` or `ngramx.yml` exists):

```
Error: Ngramx is already initialized in this directory
Use --force to overwrite existing files
```

Solution: Use `--force` flag or manually remove existing files.

### Permission Denied

If you don't have write permissions:

```
Error: Initialization failed: Failed to create directory: .ngramx
```

Solution: Ensure you have write permissions in the current directory.

### Template Not Found

If running from source and templates are missing:

```
Error: Template file not found: /path/to/templates/ngramx.yml.template
```

Solution: Ensure the `templates/` directory exists in the project root.

## Integration with PHAR Build

The templates are automatically included in the PHAR build via `box.json`:

```json
{
  "directories": [
    "src",
    "templates"
  ]
}
```

This ensures the init command works both when running from source and as a compiled PHAR.

## Testing

Comprehensive unit tests are available in `tests/Unit/Command/InitCommandTest.php`:

```bash
# Run init command tests
./bin/ngramx test -- --filter=InitCommandTest
```

Tests cover:
- Directory structure creation
- File generation from templates
- --force option
- --skip-yaml option
- Already initialized detection
- Error handling

## Future Enhancements

Potential improvements for future versions:

1. **Interactive Mode**: Ask questions to customize generated files
2. **Project Templates**: Laravel, Symfony, generic PHP templates
3. **Auto-detection**: Detect project type and customize accordingly
4. **Git Integration**: Optionally initialize git repository
5. **Custom Templates**: Allow users to specify custom template URLs

## See Also

- [.ngramx/README.md](templates/ngramx-readme.md.template) - Full .ngramx folder documentation
- [ngramx.example.yml](ngramx.example.yml) - Example configuration
- [README.md](README.md) - Main Ngramx CLI documentation

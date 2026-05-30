# Contributing to Ngramx CLI

Thank you for your interest in contributing to Ngramx CLI! 🎉

## Getting Started

### Prerequisites

You only need:
- Docker and Docker Compose
- Git

That's it! All PHP dependencies and tools are containerized.

### Setup Development Environment

1. **Clone the repository:**
   ```bash
   git clone https://github.com/ngramx/ngramx.git
   cd ngramx
   ```

2. **Start the development environment:**
   ```bash
   ./bin/ngramx up
   ```
   
   This will:
   - Build the Docker container with PHP 8.3 and all extensions
   - Install Composer dependencies
   - Set up Xdebug for code coverage

3. **Verify everything works:**
   ```bash
   ./bin/ngramx validate
   ```

## Development Workflow

### Running Tests

```bash
# Run all tests
./bin/ngramx test

# Run tests with HTML coverage report
./bin/ngramx test-coverage
# Then open coverage-html/index.html in your browser
```

### Static Analysis

```bash
# Run PHPStan (level 8)
./bin/ngramx phpstan
```

### Code Style

```bash
# Check code style
./bin/ngramx cs-check

# Automatically fix code style
./bin/ngramx cs-fix
```

### Building the PHAR

```bash
./bin/ngramx build
```

### Working in the Container

```bash
# Open an interactive shell
./bin/ngramx shell

# Run custom commands
./bin/ngramx composer require vendor/package
./bin/ngramx php bin/ngramx list
```

### Stopping the Environment

```bash
./bin/ngramx down
```

## Commit Message Convention

This project uses [Conventional Commits](https://www.conventionalcommits.org/) for automated versioning and changelog generation.

### Commit Message Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

- **feat**: A new feature (triggers minor version bump, e.g., 1.0.0 → 1.1.0)
- **fix**: A bug fix (triggers patch version bump, e.g., 1.0.0 → 1.0.1)
- **docs**: Documentation only changes (triggers patch bump)
- **style**: Code style changes (formatting, missing semicolons, etc.)
- **refactor**: Code refactoring without changing behavior
- **perf**: Performance improvements
- **test**: Adding or updating tests
- **chore**: Changes to build process, tools, or dependencies

### Breaking Changes

To trigger a major version bump (e.g., 1.0.0 → 2.0.0), include `BREAKING CHANGE:` in the commit footer:

```
feat(api): change configuration file format

BREAKING CHANGE: The configuration file format has changed from JSON to YAML.
Users must convert their config files.
```

### Examples

**Good commit messages:**
```bash
feat(commands): add new 'restart' command
fix(docker): resolve container startup timeout issue
docs(readme): update installation instructions
refactor(config): simplify configuration loading logic
perf(docker): optimize health check polling
```

**Bad commit messages:**
```bash
update stuff
fixed bug
WIP
...
```

### Scopes (Optional)

Common scopes in this project:
- `commands`: Command-related changes
- `docker`: Docker orchestration
- `config`: Configuration handling
- `tests`: Test-related changes
- `ci`: CI/CD changes

## Code Standards

This project follows:
- **PSR-12** coding standards
- **PHPStan Level 8** static analysis
- **PHP 8.2+** modern features (readonly classes, constructor promotion, typed properties)

## Before Submitting a PR

1. **Ensure all tests pass:**
   ```bash
   ./bin/ngramx test
   ```

2. **Run static analysis:**
   ```bash
   ./bin/ngramx phpstan
   ```

3. **Format your code:**
   ```bash
   ./bin/ngramx cs-fix
   ```

4. **Or run everything at once:**
   ```bash
   ./bin/ngramx validate
   ```

5. **Write tests** for new features

6. **Update documentation** if needed

## Project Structure

```
ngramx/
├── bin/ngramx          # CLI entry point
├── src/                # Source code
│   ├── Command/        # Symfony Console commands
│   ├── Config/         # Configuration handling
│   ├── Docker/         # Docker operations
│   ├── Executor/       # Command execution
│   ├── Orchestrator/   # High-level orchestration
│   └── Output/         # Output formatting
├── tests/              # PHPUnit tests
│   ├── Unit/           # Unit tests
│   └── fixtures/       # Test fixtures
├── docker/             # Docker development environment
├── ngramx.yml          # Ngramx config (for self-development!)
└── docker-compose.yml  # Docker Compose setup
```

## Available Commands

Run `./bin/ngramx list` to see all available development commands:
- `test` - Run PHPUnit tests
- `test-coverage` - Run tests with HTML coverage report
- `phpstan` - Run PHPStan static analysis
- `cs-fix` - Fix code style with PHP CS Fixer
- `cs-check` - Check code style without fixing
- `build` - Build ngramx.phar with Box
- `shell` - Open interactive bash shell
- `composer` - Run composer commands
- `php` - Run PHP commands
- `validate` - Run all validation checks

## Continuous Integration

GitHub Actions automatically runs:
- Tests on PHP 8.2 and 8.3
- PHPStan static analysis
- Code coverage (uploaded to Codecov)

All checks must pass before a PR can be merged.

## Questions or Issues?

- Open an issue on GitHub
- Check the [dev-docs](dev-docs/) directory for additional documentation

## License

By contributing to Ngramx CLI, you agree that your contributions will be licensed under the Apache License 2.0.


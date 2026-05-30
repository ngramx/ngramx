# Release Process

This project uses [Semantic Release](https://semantic-release.gitbook.io/) for fully automated version management and package publishing.

## How It Works

Releases are **fully automated** and triggered by pushing commits to the `main` branch. The release process:

1. **Analyzes commit messages** to determine the version bump
2. **Updates the version** in `src/Application.php`
3. **Generates/updates** `CHANGELOG.md`
4. **Builds** the PHAR file (`ngramx.phar`)
5. **Creates** a Git tag
6. **Publishes** a GitHub Release with assets

## Commit Message Convention

This project follows the [Conventional Commits](https://www.conventionalcommits.org/) specification.

### Version Bumps

| Commit Type | Version Bump | Example |
|-------------|--------------|---------|
| `fix:` | Patch (1.0.0 → 1.0.1) | `fix(docker): resolve timeout issue` |
| `feat:` | Minor (1.0.0 → 1.1.0) | `feat(commands): add restart command` |
| `BREAKING CHANGE:` | Major (1.0.0 → 2.0.0) | See below |

### Commit Types

- **feat**: New feature
- **fix**: Bug fix
- **docs**: Documentation changes
- **style**: Code style/formatting
- **refactor**: Code refactoring
- **perf**: Performance improvements
- **test**: Test changes
- **chore**: Build/tooling changes

### Breaking Changes

To trigger a major version bump, include `BREAKING CHANGE:` in the commit footer:

```
feat(config): change configuration format

BREAKING CHANGE: Configuration file format changed from JSON to YAML.
Users must migrate their config files.
```

## Making a Release

### 1. Make Changes

Work on your feature/fix on a feature branch:

```bash
git checkout -b feat/my-feature
# Make your changes
```

### 2. Commit with Conventional Format

```bash
git add .
git commit -m "feat(commands): add new restart command"
```

Or use commitlint for validation:

```bash
npm run commitlint
```

### 3. Create Pull Request

Create a PR to merge into `main`:

```bash
gh pr create --title "feat(commands): add new restart command" --body "Description"
```

### 4. Merge to Main

Once the PR is approved and tests pass, merge it to `main`:

```bash
gh pr merge --squash
```

### 5. Automatic Release

The GitHub Actions workflow automatically:

1. ✅ Runs tests on PHP 8.2 and 8.3
2. ✅ Runs PHPStan static analysis
3. 🚀 Analyzes commits since last release
4. 📝 Updates `CHANGELOG.md`
5. 🔢 Updates version in `src/Application.php`
6. 📦 Builds `ngramx.phar` with Box
7. 🏷️ Creates Git tag
8. 📤 Creates GitHub Release
9. 📎 Uploads `ngramx.phar` and `install.sh`

No manual intervention required!

## Release Channels

### Main Channel (Latest)

The `main` branch releases stable versions:
- Tags: `v1.0.0`, `v1.1.0`, `v2.0.0`
- Distribution tag: `latest`

### Beta Channel (Pre-release)

The `beta` branch releases beta versions:
- Tags: `v1.1.0-beta.1`, `v1.1.0-beta.2`
- Distribution tag: `beta`

To create a beta release:

```bash
git checkout beta
git merge main
git push origin beta
```

### Alpha Channel (Experimental)

The `alpha` branch releases alpha versions:
- Tags: `v1.2.0-alpha.1`, `v1.2.0-alpha.2`
- Distribution tag: `alpha`

## Manual Testing Before Release

To test semantic-release locally (dry-run):

```bash
# Install dependencies
npm install

# Run semantic-release in dry-run mode
npx semantic-release --dry-run
```

This will show what version would be released without actually releasing.

## Troubleshooting

### Release Not Triggered

**Cause**: No commits with releasable types since last release.

**Solution**: Ensure your commits use proper conventional format (`feat:`, `fix:`, etc.)

### PHAR Build Failed

**Cause**: Build errors during Box compilation.

**Solution**: Test build locally:

```bash
composer install --no-dev --optimize-autoloader
box compile
```

### Version Not Updated in Application.php

**Cause**: Update script failed.

**Solution**: Test the version update script locally:

```bash
node scripts/update-version.js 1.2.3
git diff src/Application.php
```

### GitHub Token Issues

**Cause**: Insufficient permissions for `GITHUB_TOKEN`.

**Solution**: The workflow uses the default `GITHUB_TOKEN` which should have sufficient permissions. If issues persist, check repository settings.

## Monitoring Releases

View releases at:
- GitHub Releases: https://github.com/ngramx/ngramx/releases
- GitHub Actions: https://github.com/ngramx/ngramx/actions

## Rolling Back a Release

If a release has issues:

1. **Create a fix** and commit with `fix:` prefix
2. **Release will be automatic** with a patch version
3. For urgent rollback, create a new release manually with previous version

## Adding Release Badges

Add this to your README:

```markdown
[![Release](https://img.shields.io/github/v/release/ngramx/ngramx)](https://github.com/ngramx/ngramx/releases)
[![semantic-release: angular](https://img.shields.io/badge/semantic--release-angular-e10079?logo=semantic-release)](https://github.com/semantic-release/semantic-release)
```

## Resources

- [Semantic Release Documentation](https://semantic-release.gitbook.io/)
- [Conventional Commits](https://www.conventionalcommits.org/)
- [Keep a Changelog](https://keepachangelog.com/)


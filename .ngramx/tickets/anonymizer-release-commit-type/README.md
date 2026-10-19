# anonymizer-release-commit-type: Trigger patch release for anonymizer paging

## Summary

PR #54 landed the anonymizer keyset pagination fix, but its commits had no Conventional Commit type. Angular semantic-release therefore will not publish. This follow-up is a `fix(postmaclone):` commit so 2.58.2 can ship.

## Requirements

- Commit message uses `fix:` so `@semantic-release/commit-analyzer` bumps patch
- No behaviour change; the code is already on main

## Changes

- Conventional `fix(postmaclone):` commit to trigger the Release workflow

# GIG-3371: Install worktree dependencies from the lockfile

## Summary

A copied `vendor` or `node_modules` can be incomplete. A new worktree installs both from the lockfile when the project starts.

## Requirements

Stop copying dependency directories from the parent checkout into a worktree. The project's startup install is the source of the tree.

## Changes

- `WorktreeDependencyPrimer` no longer copies `vendor` or `node_modules`.
- README worktree section describes the lockfile install.
- PR: https://github.com/ngramx/ngramx/pull/38

# COR-268: Implement `ngramx worktree` command for standalone worktree environments

## Summary

Adds a new `ngramx worktree <ticket>` command — the "author" counterpart to `ngramx review` — that finds (or creates) a branch for a ticket, sets up an isolated git worktree under `.ngramx/worktrees/`, and brings up a parallel dev environment for it.

## Requirements

- Accept a ticket identifier: bare number (`2345`), full reference (`gig-2345`), or hyphen-less (`gig2345`).
- New `default_team` config in `ngramx.yml` (default `gig`) so bare numbers are auto-prefixed.
- `git fetch origin`, then wildcard-search remote branches for the ticket identifier.
- One match → worktree from that branch; multiple → interactive selector (same as `review`); none → create a new `{team}-{number}` branch.
- Reuse an existing worktree for the branch when present.
- Spin up the dev environment in the worktree, priming vendor/node_modules from the parent checkout (reusing the `review` machinery).

## Changes

- `src/Config/Schema/NgramxConfig.php`: new `defaultTeam` property (default `gig`).
- `src/Config/ConfigLoader.php`: parses and lowercases `default_team` from `ngramx.yml`.
- `src/Config/Validator/ConfigValidator.php`: validates `default_team` is a short alphabetic prefix.
- `src/Worktree/WorktreeIdentity.php`: new `normalizeTicket()` — canonicalises `2345` / `gig2345` / `GIG-2345` into `gig-2345`.
- `src/Git/GitRepositoryService.php`: new `addWorktreeWithNewBranch()` (creates the worktree with `-b`, hooks disabled, same exit-code-vs-registration verification as `addWorktree`) and `localBranchExists()`.
- `src/Command/ReviewCommand.php`: `runWorktreeReview()` made `protected` with a `createNewBranch` flag; `configLoader`/`gitRepositoryService` made protected for the subclass.
- `src/Command/WorktreeCommand.php`: new command extending `ReviewCommand`. Normalises the ticket, searches remote branches (canonical slug first, then hyphen-less spelling, then the bare number), falls back to reusing a local-only branch, and otherwise creates a new branch — then delegates to the shared worktree/env setup.
- `src/Application.php`: registers the new command.
- Tests: `WorktreeCommandTest` (new), plus new cases in `WorktreeIdentityTest`, `GitRepositoryServiceTest`, and `ConfigLoaderTest`.

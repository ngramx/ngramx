# GIG-4502: Fix coding agent push authentication for ngramx repositories

## Summary

CodaByte could not push `gig-4500` to `ngramx/ngramx` because the GitHub App **CodaByteBot** is installed on `gigabyte-software` but not the `ngramx` organisation.

## Requirements

- Agent pushes to `ngramx/ngramx` must succeed without interactive HTTPS username prompts.

## Resolution

Install [CodaByteBot](https://github.com/organizations/ngramx/settings/installations) on the **ngramx** GitHub organisation with read/write access to `ngramx` (and any other org repos Codabyte should publish to).

This is an org-level GitHub App installation change — not something the ngramx CLI can fix in code. After installation, re-run or manually push the unpublished `gig-4500` branch from the agent worktree.

## Changes

- Documented root cause and required GitHub App installation in this ticket folder.

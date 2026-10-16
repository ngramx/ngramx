# GIG-3931: Set up GitHub branch protection to require review on high-risk PRs

## Summary

High-risk pull requests must not auto-merge into the default branch, and they need another person's approval. Low-risk pull requests stay mergeable without a review.

## Requirements

- Investigate GitHub branch protection and GitHub Actions that can require a review when a PR has a high-risk label.
- Set up a proof of concept on one repository.
- Document the approach so it can be rolled out across client repositories.
- Leave low-risk PRs mergeable without a review.

## Changes

- GitHub rulesets cannot require an approval because of a label. The gate is a required status check named `high-risk-review`: it passes for every other risk, and it fails while `risk:high` has no approving review from someone other than the author.
- `ngramx init-github-actions` now writes `.github/workflows/high-risk-review.yml`. On PR open and push it asks Claude to apply `risk:low`, `risk:medium`, or `risk:high`. `risk:high` disables native auto-merge and removes the `auto-merge` label.
- The ngramx repository carries that workflow as the proof of concept. Turn the check on in branch protection only after this file is on the default branch.

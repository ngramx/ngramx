# GIG-4035: Re-run high-risk-review when a pull request is approved so the check can go green

## Summary

An approving review on a `risk:high` pull request must re-run `high-risk-review` and turn that required check green. Dismissing the last approval must turn it red again.

## Requirements

Approving a `risk:high` pull request does not turn the required check green today. The reusable workflow already passes once one approving review exists, but this caller never starts it on `pull_request_review`.

Do not require one approving review on every pull request. Low and medium risk stay mergeable with no review.

## Changes

- `ngramx init-github-actions` writes a `high-risk-review.yml` caller that also listens for `pull_request_review` (`submitted`, `dismissed`).
- The ngramx repository's own caller has the same trigger, so an approval here re-runs the check.
- The create-pr skill now says an approval of the current head commit re-runs the check, and an older approval does not count.
- The caller checks the pull request base ref, because GitHub ignores a `branches` filter on `pull_request_review`.
- `edited` is included so changing the base onto `main` runs the gate again.

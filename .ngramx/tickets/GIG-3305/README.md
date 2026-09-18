# GIG-3305: Batch postmaclone anonymizer UPDATEs

## Summary

LiveAnonymizer reads rows in chunks of 500 but still issues one UPDATE per row. Nightly Apollo produce dies at the GitHub-hosted 6-hour cap because of that.

## Requirements

Issue one UPDATE per chunk (CASE WHEN / WHERE pk IN), split oversized JSON payloads, fall back to per-row on batch failure.

## Changes

- Ticket folder created.
- `applyBatch` issues one `UPDATE ... CASE WHEN` per chunk, splits oversized payloads, and falls back to per-row on batch failure.

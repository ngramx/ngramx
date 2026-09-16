# GIG-3189: Identify And Configure All PII Columns For Post-Clone Anonymization

## Summary

Engine support so project PII rules can rewrite JSON payloads, clear tokens, keep values consistent across tables, and continue when a column or table is missing.

## Requirements

- Deserialize JSON cells, rewrite selected paths/keys, serialize again.
- Treat Xero and other tokens as PII (`clear`) so clones cannot call live services.
- Continue anonymizing after a missing column or row-level failure (AKT `jobs.address`); `--strict` still fails the run.
- Address Devin comments on the GIG-3189 app/factory PRs once this syntax exists.

## Changes

- `ColumnRule` now accepts `json`, `json_array`, `recursive`, and `consistent`.
- `JsonPayloadAnonymizer` + `AnonymizedValueFactory` used by live and SQL emitters.
- Special formatters: `clear`, `emailOrName`.
- Live anonymizer skips missing tables/columns and warns on per-row failures; invalid JSON becomes `{}`.
- PR: https://github.com/ngramx/ngramx/pull/22
- Bugbot: decode JSON as objects so `{}` stays `{}`; strip `unique` before `emailOrName`; escape MySQL backslashes in SQL literals.

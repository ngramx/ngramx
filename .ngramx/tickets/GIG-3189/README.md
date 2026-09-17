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
- Produce logs each stage and ~10% ticks on download, dump sanitizer, per-table anonymize, and gzip. Console lines are flushed so GitHub Actions is not silent for hours. Workflow template pins `postgresql-client-17` and runs PHP with `stdbuf` + `output_buffering=Off`.
- PR: https://github.com/ngramx/ngramx/pull/23
- Bugbot: mysqldump uses `run()` so the stdout pipe is drained (polling hung the child). `clearstatcache` on pg_dump file-size ticks. Workflow template adds the PGDG apt repo before `postgresql-client-17`.
- Prefer `/usr/lib/postgresql/{N}/bin/pg_dump` (newest N) over the Debian `pg_wrapper`. Factory run 35177394530 had client 17 installed but still invoked 16.15. Template also prepends that bin dir to PATH.
- PR: https://github.com/ngramx/ngramx/pull/24
- `ngramx down` / `up` no longer fail the whole yml load when `postmaclone.tables` is invalid. Docker/setup/commands still load; the postmaclone section is ignored with a warning. The EK error (`context.faker is required`) was an old CLI requiring `faker` on every column object — json-only rules are valid in 2.45.0+, but teardown must not depend on that either.
- PR: https://github.com/ngramx/ngramx/pull/25
- Factory produce failed on AKT: `op item edit` in GitHub Actions treats inherited stdin as a JSON template (`invalid JSON provided`). Writer now fetches the item, replaces the field, and pipes JSON. Commits use `fix:` / `chore:` so merge to main cuts a release.
- 2.45.1 still failed TFD: piped JSON became `unable to process line 1: Couldn't update the item` (stdin parsed as item specifiers). Writer now uses `--template` plus stdin from `/dev/null`.
- PR: https://github.com/ngramx/ngramx/pull/26
- `ROTATE_DATABASE_PASSWORD` env (GitHub variable or secret) gates `op item edit`. Factory defaults it to false until the service account can write `postmaclone-anon-psql`.
- PR: https://github.com/ngramx/ngramx/pull/27

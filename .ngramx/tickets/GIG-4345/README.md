# GIG-4345: Postmaclone must not leave a table raw when one column UPDATE fails

## Summary

A single bad replacement (`clear` writing `''` into a bigint) aborted every `residents` UPDATE and left production emails in the shared Hydra anon database.

## Requirements

- `clear` writes `0` for numeric cells and `''` for strings.
- Multi-column UPDATE failure retries remaining columns individually.
- `tables.<name>.empty: true` deletes all rows (for composite-PK history tables).

## Changes

- Ticket folder created.
- `clear` writes `0` for numeric cells (including PDO numeric strings).
- Multi-column UPDATE failure retries each remaining column.
- `tables.<name>.empty: true` deletes all rows without needing a single-column PK.

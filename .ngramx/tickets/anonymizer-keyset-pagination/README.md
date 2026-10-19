# anonymizer-keyset-pagination: Page postmaclone anonymize SELECTs

## Summary

Hydra produce OOM-killed the lon1 runner while anonymizing `bank_transactions` (~2.8M rows). Live anonymize buffered the whole table in PHP. Page SELECTs by primary key so only one chunk is in memory.

## Requirements

- Do not `SELECT` an entire table into a buffered PDO result
- Keep 500-row UPDATE batches
- Cover paging with a unit test

## Changes

- `LiveAnonymizer` reads `WHERE pk > :last ORDER BY pk LIMIT chunkSize`, then UPDATEs, then the next page
- `LiveAnonymizerTest::test_selects_are_paged_by_primary_key`
- PR: https://github.com/ngramx/ngramx/pull/54
- Parenthesize column `where` when appending the keyset so `OR` cannot ignore `pk > :last` (Bugbot)

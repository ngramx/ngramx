# GIG-3347: Stream gzip dumps into MySQL restore

## Summary

Stop expanding Hydra-sized `.gz` dumps to a 39GB `.ungz` on the lon1 droplet. Restorers decompress through a zlib read stream.

## Requirements

- Do not resize the droplet.
- `S3BackupSource` must leave gzip on disk.
- Detect gzip by magic bytes (cache files are named `.dump`).
- Pipe decompressed bytes into mysql/psql sanitizers.
- Shared refresh must not expand Hydra anon `.gz`.
- `fix:` commit so factory `releases/latest` picks it up.

## Changes

- Added `DumpStream` to open dumps via `compress.zlib://` when the file starts with gzip magic.
- Removed `S3BackupSource` gunzip-to-disk and leftover `.ungz` cleanup only.
- `DumpDecompressor::maybeDecompress` is now a no-op so SharedDbRefresher keeps gzip.
- `MysqlRestorer`, `PlainSqlDumpSanitizer`, and `PostgresRestorer` (including custom-format peek and pg_restore stdin) use `DumpStream`.
- PR: https://github.com/ngramx/ngramx/pull/36

# stream-mysql-produce-dump: Stream Hydra produce dump through gzip

## Summary

Factory Hydra produce dies when dumping the anonymized scratch DB: mysqldump is buffered in PHP memory, then written as uncompressed SQL and gzipped. The lon1 droplet has 2GB RAM.

## Requirements

Stream `mysqldump | gzip` to the local artifact. Delete the downloaded source dump after restore. Do not hold the dump in PHP or write a plain SQL file.

## Changes

- MySQL dump pipes through `gzip -1` with output disabled on the Symfony process.
- Producer removes the S3 source dump after restore, before the anon dump.
- Unit tests for gzip/plain MySQL dump and produce cleanup order.
- PR: https://github.com/ngramx/ngramx/pull/59
- Restore progress counts gzip file bytes (filter sits before inflate) so 100% means the dump has been read, not that uncompressed SQL exceeded the .gz size.
- Dump pipeline uses setsid + TERM trap so a timeout kills mysqldump/gzip; gzip stderr is kept in the sidecar err file. PHPStan: drop tautological assertIsResource.

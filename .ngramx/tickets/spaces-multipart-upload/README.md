# spaces-multipart-upload: Multipart Spaces upload for Hydra artifacts

## Summary

Hydra produce dumped a 6.3GB anonymized gzip then failed on `PUT` to Spaces with `EntityTooLarge`. Spaces rejects a single PUT over 5GB.

## Requirements

Use S3 multipart for large artifacts. Recover the dump already on lon1 without repeating restore/anonymize/dump.

## Changes

- `S3ObjectUploader` uses multipart above 4GiB (64MiB parts) and aborts on failure.
- `ngramx postmaclone produce --from-artifact` publishes a local dump and refreshes shared.
- Recovered dump moved to `/var/lib/postmaclone-recover/hydra_anon.sql.gz` (6,755,282,413 bytes, sha256 `a2349ac43d012fc6780de25977a6fef3f891fe6f1be5e3453aba56bbddbc0113`).

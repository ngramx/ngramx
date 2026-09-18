# GIG-3317: Strip DEFINER and SUPER statements on MySQL postmaclone restore

## Summary

GIG-3316 got Hydra past `NO_AUTO_CREATE_USER`. The next restore failure is `DEFINER` (SUPER / SET_ANY_DEFINER) on DigitalOcean managed MySQL 8. Same dump class also emits `SQL_LOG_BIN` and `GTID_*` assignments.

## Requirements

Strip `DEFINER=` from view/trigger/routine DDL. Neutralize `SET ... SQL_LOG_BIN` and `SET ... GTID_*`. phpunit including stream-filter splits. `fix:` commit for a release.

## Changes

- Ticket folder created.
- `MysqlDumpSanitizer` strips `DEFINER=` on DDL, comments out `SQL_LOG_BIN` and `GTID_*` assignments, keeps the existing `NO_AUTO_CREATE_USER` rewrite.
- PR: https://github.com/ngramx/ngramx/pull/32
- SET matcher requires a dump `SET` / `/*!… SET` line and `TOKEN=`, so INSERT/trigger text is not dropped (Bugbot).

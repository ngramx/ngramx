# GIG-3316: Strip NO_AUTO_CREATE_USER on MySQL postmaclone restore

## Summary

Hydra produce restore into MySQL 8 scratch fails because 5.7-era dumps set `sql_mode` to `NO_AUTO_CREATE_USER`. Strip that mode on restore so factory can publish hydra again.

## Requirements

- Strip `NO_AUTO_CREATE_USER` from `sql_mode` assignments before piping to `mysql`.
- Leave other modes (including `NO_AUTO_VALUE_ON_ZERO`) alone.
- Do not change the prod dump script.
- `fix:` commit so merge cuts a release for `releases/latest`.

## Changes

- Ticket folder created.
- `MysqlDumpSanitizer` strips `NO_AUTO_CREATE_USER` from `sql_mode` assignments; stream filter pipes the dump into `mysql` without a second copy.
- PR: https://github.com/ngramx/ngramx/pull/31

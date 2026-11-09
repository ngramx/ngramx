# GIG-4522: Fix Hydra postmaclone numerify and unique usernames

## Summary

Simple faker calls with arguments (`numerify('######')`) pass `assertMethodExists` then fail in `generate()`, so produce logs millions of per-row errors instead of applying the formatter.

## Requirements

`generate()` must parse formatter arguments on simple calls the same way templates do, for both single- and double-quoted strings.

## Changes

- Ticket folder created.
- `FakerMethodResolver::generate()` parses simple formatter calls with arguments (`numerify('######')`) instead of treating the whole string as a formatter name.
- PR: https://github.com/ngramx/ngramx/pull/65
- Conventional `fix(postmaclone):` commit so semantic-release publishes the parser fix.

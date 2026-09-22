# GIG-3345: Stop killing Hydra MySQL restore at three hours

## Summary

Hydra restore hit the 10800s Symfony timeout. Stop guessing hours: no process timeout on dump restore, speed flags, and byte progress.

## Requirements

`setTimeout(null)` on MySQL dump restore. `--compress`, `--max-allowed-packet=1G`, `foreign_key_checks=0` / `unique_checks=0`. Progress percent. `fix:` commit.

## Changes

- Ticket folder created.

# GIG-3802: Output TablePlus database browser URL in ngramx review and worktree commands

## Summary

After `ngramx review` or `ngramx worktree` brings an environment up, print a clickable TablePlus URL for that environment's database.

## Requirements

After environment setup, output a clickable URL with the database host, port, username, password, and database name. It must work for both `ngramx review` and `ngramx worktree`. Credentials come from the environment's database configuration. TablePlus has to be installed locally for the link to open an app.

## Changes

- Print a TablePlus connection URL (`postgresql://`, `mysql://`, or `mariadb://`) after review and worktree setup. TablePlus registers those schemes and opens a connection from them. The app's `tableplus://` scheme is for its own screens, not for creating a connection.
- Use `.env` credentials (`DB_*` or `DATABASE_URL`) and the host port Docker published, including a worktree offset or a recorded port remap. `127.0.0.1` is the host, because `DB_HOST` inside the stack is the compose service name.
- When a postmaclone connect lock is present (`--anon` or `ngramx postmaclone connect`), use that session's IDE host, port, and credentials.
- Stay quiet when the project has no published SQL database, or when `--no-host-mapping` means nothing is listening on the host.

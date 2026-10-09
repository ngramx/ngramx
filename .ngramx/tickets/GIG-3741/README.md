# GIG-3741: Output an auth-bypass app link from `cortex review`

## Summary

After `ngramx review` refreshes a ticket's environment, print a clickable URL that signs the reviewer into the running application without the email-code login.

## Requirements

The review command checks out the branch and refreshes the environment, but reviewers still have to find login credentials. After that refresh, print a clearly labelled auth-bypass URL. Opening it logs the reviewer in and lands them in the app.

Nearly all projects use the Gigabyte identity module. The link is a single-use magic link for a seeded local user, minted only when `APP_ENV` is local. It expires after 8 hours by default (24 hours maximum). `--anon` does not mint a link. Projects can point `auth.bypass.email` at a different user, supply an `auth.bypass.url` template, or set `auth.bypass: false`.

## Changes

- `ngramx review` (in place and `--worktree`) prints an **Auth bypass** link on the live app URL, including a remapped host port.
- The link is minted inside the app container as an identity magic-link token. Apps without that package stay quiet.
- `auth.bypass` in `ngramx.yml` sets the email, lifetime, a custom URL template, or turns the link off.

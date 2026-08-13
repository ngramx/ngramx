# COR-291: Probe *.localhost via loopback so ngramx up still writes .ngramx.lock on WSL

## Summary

On WSL2, `ngramx up --avoid-conflicts` can start a healthy namespaced stack, then hard-fail the `docker.app_url` probe because `*.localhost` does not resolve inside the distro. `.ngramx.lock` is never written, so `ngramx shell` / `logs` look at the default compose project.

Do not fix this by auto-writing `/etc/hosts`. Probe `*.localhost` via loopback with the correct `Host` header (and TLS SNI), write the lock once the stack is up regardless of the URL probe, and surface a hosts hint when the name does not resolve.

## Requirements

- On WSL, `ngramx up --avoid-conflicts` with an unresolvable `*.localhost` `app_url` still writes `.ngramx.lock` when containers are healthy.
- `ngramx shell` attaches to the namespaced primary service after that `up`.
- The HTTP probe still catches real 5xx / php-fpm-down by hitting loopback with the correct `Host` header.
- `EtcHostsHint` surfaces a hosts line when `*.localhost` does not resolve; it does not `sudo` write `/etc/hosts`.
- Unit tests cover loopback probe for `*.localhost` and lock write even when the probe warns.

## Changes

- Ticket folder created.

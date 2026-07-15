# Worktree environments: make HTTPS work

## Summary

Worktree environments advertise a hostname (`<folder>.localhost`) that the project's mkcert certificate was never minted for, so browsers rejected the worktree URL with a hostname mismatch even though the CA was trusted. Worktrees now get a certificate that covers both the app's canonical host and the worktree subdomain before their stack starts.

## Requirements

A project installs a root CA to Windows and WSL via `ngramx secure`, and HTTPS works on the main checkout — but SSL fails in worktree environments. Diagnosed live on `terrablock`: the worktree cert only carried `DNS:terrablock.localhost`, while the environment advertised `https://gig-2460-terrablock.localhost:8643`.

## Changes

- `src/Tls/CertInspector.php` / `CertInfo.php`: parse the subjectAltName extension into `CertInfo::$subjectAltNames`, and add `CertInfo::coversHost()` (exact + single-label wildcard matching, CN fallback for SAN-less legacy certs).
- `src/Worktree/WorktreeCertSeeder.php` (new): before a worktree stack starts, copy the parent checkout's cert when the worktree has none (cert files are gitignored, so fresh checkouts have none), and — when the cert doesn't cover the worktree hostname and mkcert is available — mint one covering both the app host and `<folder>.localhost`, written under both hostnames' file names so whichever the proxy config references is covered. Best-effort with warnings; https-only.
- `src/Docker/DockerCompose.php`: new `restart()` (in-place `docker-compose restart`).
- `src/Command/ReviewCommand.php`: seeds the cert in the worktree flow before startup; when the environment was already running and the cert changed, restarts the stack so the proxy re-reads it.
- Tests: `WorktreeCertSeederTest` (new), SAN/coversHost cases in `CertInfoTest`/`CertInspectorTest`, restart wiring cases in `ReviewCommandTest`.

Verified against the live `terrablock` worktree: served cert now carries both SANs, and `curl` (with full verification) returns 200 on the worktree URL.

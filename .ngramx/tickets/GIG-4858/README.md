# GIG-4858: Isolate worktree stacks from each other on the shared Docker network (login redirect loop)

## Summary

Two worktree stacks of the same project joined to a shared external Docker network (e.g. `cortex-coder`) get each other's `app` container from Docker DNS, so nginx splits requests between stacks and login loops. `ngramx up` now isolates namespaced stacks itself.

## Requirements

- Only the web-facing container (publishes 80/443) stays on the shared network; every other container resolves service names on the stack's own network only.
- Works for any project, with no change to each repo's `docker-compose.yml`.
- Compatible with Cortex Coder's `isolateSharedNetwork` (it finds nothing left to disconnect).

## Changes

- Added `Ngramx\Docker\SharedNetworkIsolator`: reads `docker-compose config --format json`, finds external networks and web services, disconnects non-web containers from the external networks, then reloads nginx so it re-resolves upstreams. Idempotent; never strands a container with no other network.
- `SetupOrchestrator` runs it as phase 2.6 for namespaced (worktree) stacks, after network reconcile and before readiness waits.
- Unit tests for the isolator; orchestrator tests stub it.
- Verified against real Docker with two stacks on one external network: each stack's `app` resolved to its own container only.

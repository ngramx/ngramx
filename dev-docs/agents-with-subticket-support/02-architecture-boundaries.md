# Architecture boundaries

If this project uses [Deptrac](https://github.com/deptrac/deptrac) (or a similar layer-enforcement tool), respect the published layer rules in `deptrac.yaml` (or equivalent).

Typical patterns for a modular Laravel-style monolith:

| Layer | May depend on | Must NOT depend on |
|-------|---------------|--------------------|
| Shared libraries | Vendor / framework | Application shell, domain core, or feature modules |
| Domain core | App shell, libraries, vendor | Feature modules |
| Feature modules | Core, app shell, libraries, vendor | Other feature modules |
| Application shell | Core, libraries, feature modules, vendor | — |

After changing `use` statements, inheritance, or moving classes between namespaces, run the project’s architecture check (for example `composer deptrac` or `php vendor/bin/deptrac analyse`) and fix violations before committing.

## Separate front-end apps

If the repo contains a PWA or mobile client in its own directory, treat it as **isolated** from server-side code unless the project explicitly documents a shared package. Integration should stay at documented boundaries (usually HTTP APIs and env-based configuration), not by importing server models or Laravel internals into the client.

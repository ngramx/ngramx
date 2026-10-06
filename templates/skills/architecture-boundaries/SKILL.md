---
name: architecture-boundaries
description: >-
  Follow the architecture rules this repository documents, and keep separate
  front-end apps isolated. Use when changing module boundaries, dependencies,
  or a client that lives beside the server code.
---

# Architecture boundaries

Apply this only when the repository documents an architecture. Otherwise follow the code around the change.

- Respect the layer, module, or package rules checked into this repository (for example a Deptrac config, an architecture test, or a written boundary doc).
- After moving code across those boundaries, run the project's architecture check and fix violations before committing.
- If a front-end app lives in its own directory, keep it isolated from server internals. Integrate through the boundary the project already documents, usually an HTTP API and environment configuration.

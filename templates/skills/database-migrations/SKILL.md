---
name: database-migrations
description: >-
  When this project has a database, follow its existing migration conventions.
  Use when adding or changing schema.
---

# Database migrations

Apply this only when the repository has a database and a migration system. Copy the style of neighbouring migrations.

- Prefer one migration per logical change.
- Add a new migration instead of editing one that has already shipped to a shared environment.
- If this project documents an extra step for personal data (for example an anonymisation rule next to the schema), update that in the same change.

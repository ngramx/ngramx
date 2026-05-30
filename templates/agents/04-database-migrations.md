# Database migrations

Follow **this project’s** migration conventions. When the project uses custom audit columns instead of Laravel’s `timestamps()`, mirror what existing migrations and models do.

Common patterns (adapt names and types to match the codebase):

- Prefer **one migration per logical change** and avoid editing migrations that have already shipped to shared environments.
- If a migration has never been run outside your machine, your team may still allow in-place edits—when in doubt, add a new migration instead of rewriting history.
- Keep a consistent **column order** if the project defines one (for example: primary key, foreign keys, audit fields, then data columns).

Do not assume every Cortex project uses the same audit column names; copy the conventions from nearby migrations in the same repository.

NEVER edit migrations. Always create new ones.
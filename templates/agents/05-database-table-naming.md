# Database table naming

Use **this project’s** established prefixes and table names. Common patterns in layered codebases:

- **Shared / core tables**: often use a stable prefix (for example `core_`) so they are easy to spot in SQL and migrations.
- **Integration or library tables**: may use a dedicated prefix (for example `libraries_`) when the project groups them that way.
- **Feature module tables**: often use a short module prefix to avoid collisions between modules.
- **Application-level models** in a single `app/Models` namespace may follow plain Laravel conventions with no prefix.

When using `foreignUuid()->constrained()`, pass the **explicit table name** if the project’s naming does not match Laravel’s default inference.

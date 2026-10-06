# Agent instructions

Push release commits straight to `main`. Start the commit message with `fix:` or `feat:`. That prefix bumps the semantic version and publishes a release. Merging a pull request does not publish one unless the commit that lands on `main` starts with that prefix.

Generated Ngramx skills and the skill index live under `.cursor/` and `.claude/` and are gitignored. Project-specific notes belong in this file.

# Git, review, and pull requests

## Feature branch upstream

**Never** create a feature branch with `git checkout -b <branch> origin/main` or `git switch -c <branch> origin/main`. Git then sets upstream to `origin/main`, and a bare `git push` updates `main`.

Use `git checkout -b <branch> origin/main --no-track`, or update local `main` and run `git checkout -b <branch>` with no start-point. First publish with `git push -u origin HEAD` only. See the `start-ticket` skill.

## Bugbot / review "verify and fix" comments

When a review comment (Bugbot, Devin, human, etc.) asks you to **verify an issue exists and fix it**, and you confirm it is real and ship a fix: **commit and push to the open PR branch by default** — do not wait for an explicit "commit/push" ask. Follow the **update-pr** skill (stale/active test blocks, ticket README changes log, then push).

If you verify it is a **non-issue**, explain why in the reply and do not change code. Do not invent unrelated commits.

## Code style before opening a PR

Before opening a pull request that touches formatted source files, either:

1. Run the project's formatter and include any fixes in the branch. For PHP Laravel apps that is typically Laravel Pint (`composer pint` or `./vendor/bin/pint` **inside the app container**, then confirm `./vendor/bin/pint --test` is clean). Other stacks should use whatever the repo already wires (Prettier, PHP CS Fixer, etc.); or
2. Ask the user whether they want formatting run/fixed before the PR is opened.

Do not open a PR with a dirty formatter check unless the user explicitly skips it.

If the formatter config excludes `database/migrations`, leave those files alone. The hard rule against editing shipped migrations is about schema/data; we still do not rewrite them with style tooling.

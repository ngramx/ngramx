# Lessons

## GIG-3191: do not change ngramx to hide generated agent files

The ticket is to `.gitignore` ngramx-generated `AGENTS.md` (and sibling generated agent files) **in every consumer project**. A ngramx-side exclude/skip-worktree change was the wrong approach — revert it and update each project's `.gitignore`, then `git rm --cached` so already-tracked copies stop dirtying the tree.

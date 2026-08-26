# Development environment

When this repository uses Ngramx, bring up the local stack with:

```bash
ngramx up
```

Use the project’s documented URL or `ngramx show-url` (if available) to open the app. Projects with several front-ends (a PWA, an API host, supplier/customer sites) list them with `ngramx show-url --all`; write completion.json `test_urls` against each endpoint’s canonical host so they are rewritten onto the right one in worktrees. Prefer automated checks defined in the project (for example Playwright, PHPUnit, or npm test) over manual-only verification when they are already wired in.

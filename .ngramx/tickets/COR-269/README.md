# COR-269: Auto-retry failed commands on ngramx up/review instead of failing immediately

## Summary

Parallel sub-commands in `fresh`/`clear` occasionally fail on the first attempt because of ordering races (e.g. an artisan command firing before `composer install` finishes). Instead of failing the whole run, the orchestrator now re-runs only the failed sub-commands.

## Requirements

- After running all parallel commands, check for failures.
- Re-run only the failed commands (not the successful ones).
- Up to 3 attempts total per failing command; delay the final retry by 3 seconds.
- Announce retries as informational output, not warnings/errors, unless the command still fails after the third attempt.

## Changes

- `src/Orchestrator/CommandOrchestrator.php` — `runParallel()` now loops: run the batch, collect failed indexes, re-run only those items (up to `MAX_PARALLEL_ATTEMPTS = 3` attempts total), sleeping `RETRY_DELAY_SECONDS = 3` before the final attempt. Retries are announced via `info()`. The batch execution (panel + executor) was extracted into `runParallelBatch()`, and the executor construction and the sleep are injectable seams for tests.
- `tests/Unit/Orchestrator/CommandOrchestratorTest.php` — added coverage: no retry when everything succeeds; only failed sub-commands are re-run; the final retry is delayed by 3s; a persistently failing sub-command fails after exactly three attempts.

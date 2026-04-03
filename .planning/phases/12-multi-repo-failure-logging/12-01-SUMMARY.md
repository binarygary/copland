# Phase 12, Plan 1: Pre-orchestrator failure logging and regression coverage - Summary

**Completed:** 2026-04-03
**Status:** Success

## Accomplishments

### 1. Narrow RunCommand Logging Seam
- Added optional `GlobalConfig`, `RunLogStore`, and repo-runner seams to `RunCommand` so the pre-orchestrator failure path can be exercised directly in tests without changing production behavior.
- Kept the existing multi-repo loop, exit-code behavior, and usage summary flow intact.

### 2. Structured Failure Logging for Repo-Level Exceptions
- Updated the repo-level `catch` path in `RunCommand` so exceptions raised before `RunOrchestratorService` starts now append a structured run-log payload through `RunLogStore`.
- Matched the existing JSONL payload shape closely: repo, issue placeholder, failed status, timestamps, failure reason, PR placeholder, decision path, usage totals, and executor duration.

### 3. Regression Coverage
- Added a focused `RunCommand` feature test that simulates two configured repos, forces the first to fail before orchestrator startup, and proves the second repo still runs.
- Asserted that the failed repo produces a structured logged payload and that the command output reports the appended run log path.

### 4. Verification
- Verified syntax with `php -l app/Commands/RunCommand.php`.
- Verified regression coverage with `./vendor/bin/pest tests/Feature/RunCommandTest.php`.

## Results

- Pre-orchestrator repo failures no longer bypass `~/.copland/logs/runs.jsonl`, closing the milestone audit integration gap behind `OBS-01` and the fail-and-continue half of `SCHED-02`.

---
*Phase: 12-multi-repo-failure-logging*
*Plan: 01*

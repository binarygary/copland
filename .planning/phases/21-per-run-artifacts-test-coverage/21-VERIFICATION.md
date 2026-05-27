---
phase: 21-per-run-artifacts-test-coverage
verified: 2026-05-27T00:00:00Z
status: passed
score: 7/7 must-haves verified
overrides_applied: 0
---

# Phase 21: Per-Run Artifacts & Test Coverage — Verification Report

**Phase Goal (from ROADMAP.md):** "Each run captures its own audit trail under `runs/<run-id>/` alongside the existing JSONL log, and the entire task-directory writer is covered by Pest tests that never touch the developer's real `~/.copland/`."

**Verified:** 2026-05-27
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

ROADMAP success criteria + plan-frontmatter truths consolidated below. Numbering is the verification ordering; all roadmap SCs (1-4) are preserved as separate truths.

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Each run writes `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` containing at minimum the PR URL (or structured failure reason) and final cost summary (ROADMAP SC1, TASK-03) | VERIFIED | `app/Services/TaskDirectoryWriterService.php:158-161` declares `runDir()` returning `taskDir/runs/{runId}`. Behavioral spot-check (PHP eval) confirmed both `status.md` and `outcome.md` materialize under that path. `outcome.md` frontmatter carries `pr_url`, `cost_usd`, and `failure_reason` as keys (9 D-05 keys all present). |
| 2 | `~/.copland/logs/runs.jsonl` continues to be written with the same schema and content — no log consumer regression (ROADMAP SC2, TASK-04) | VERIFIED | `git diff 25e9c6b..HEAD -- app/Support/RunLogStore.php` returns empty (where `25e9c6b` is the last commit before Phase 21 work). `git log --oneline 25e9c6b..HEAD -- app/Support/RunLogStore.php` returns 0 commits. The `$runLogStore->append($payload)` call at `RunOrchestratorService.php:366` is preserved. |
| 3 | Pest tests exercise the task-directory writer end-to-end using a temporary HOME, covering happy path, lifecycle transitions, and failure/blocked outcomes (ROADMAP SC3, TASK-05) | VERIFIED | `tests/Feature/TaskDirectoryWriterServiceTest.php` has 18 it-blocks (547 lines, 90 assertions). 18 occurrences of `sys_get_temp_dir()`; 54 `$_SERVER['HOME']` references (3 per test for save/swap/restore). Cases 4 + 8 cover full 8-state lifecycle (task-level + per-run). Cases 5/6/7/10/11 cover blocked/terminal outcomes. Case 1 covers happy path. No `beforeEach`/`afterEach` in file (canonical inline idiom). No `RunOrchestratorService` import (D-19 honored). |
| 4 | PHPStan level 5 stays clean and the existing 132+ test suite continues to pass (ROADMAP SC4) | VERIFIED | `composer analyse` reports `[OK] No errors` (57/57 files checked). `./vendor/bin/pest --no-coverage` reports `Tests: 155 passed (533 assertions)` — well above the 132 baseline. |
| 5 | `TaskDirectoryWriterService` exposes 3 new public methods (`writeRunStatus`, `writeRunOutcome`, `writeRunBlockedIfNotTerminal`) — D-12 | VERIFIED | Lines 82, 112, 123 of the writer. Signatures match the plan contract exactly (`string $repoSlug, string\|int $taskId, string $runId, ...`). Plus private `runDir` at line 158. The pre-existing 3 public methods (writeNewTask, writeStatus, writeBlockedIfNotTerminal) are byte-for-byte unchanged. |
| 6 | `$runId` derivation runs exactly once and is threaded through 7 paired writes + finally-arm blocked + outcome.md write (D-01/D-02/D-06/D-08) | VERIFIED | Derivation at `RunOrchestratorService.php:124` (`str_replace(':', '-', gmdate(...))`); defensive `$runId = null` init at line 46; 7 `writeRunStatus` paired calls at lines 128, 130, 135, 189, 202, 243, 295 (matching the 7 task-level `writeStatus` calls at 127, 129, 134, 188, 201, 242, 294); finally-arm `writeRunBlockedIfNotTerminal` at line 354 with full 4-clause guard; `writeRunOutcome` at line 378 inside its own try/catch. |
| 7 | `outcome.md` is written from inside the JSONL-append finally arm via a new private `outcomePayload()` mapper producing exactly the 9 D-05 keys (D-05/D-09/D-10/D-11) | VERIFIED | `RunOrchestratorService.php:450` declares `private function outcomePayload(...)`. Returns array with exactly: `run_id`, `status`, `pr_number`, `pr_url`, `cost_usd`, `started_at`, `finished_at`, `failure_reason`, `partial`. `match` expression maps `succeeded → pr_open`, `crashed → crashed`, default → `blocked`. Timestamps normalized via `gmdate('Y-m-d\TH:i:s\Z', strtotime(...))`. Behavioral spot-check confirmed all 9 keys present in emitted `outcome.md`. |

**Score:** 7/7 truths verified.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/TaskDirectoryWriterService.php` | 3 new public methods + 1 private `runDir` helper; existing 3 methods unchanged | VERIFIED | Lines 82 (writeRunStatus), 112 (writeRunBlockedIfNotTerminal), 123 (writeRunOutcome), 158 (runDir). Existing methods at 17/42/71 byte-for-byte unchanged. |
| `app/Services/RunOrchestratorService.php` | `$runId` derivation + 7 paired `writeRunStatus` + finally-arm blocked-write + outcome.md write + `outcomePayload()` mapper | VERIFIED | All 11 expected insertion sites present and wired (see truth #6). |
| `tests/Feature/TaskDirectoryWriterServiceTest.php` | 12-18 it-blocks against temporary HOME covering all D-18 axes | VERIFIED | 18 it-blocks (upper bound of target). 547 lines. All 11 D-18 axes covered per Plan 21-03 SUMMARY's case-to-axis map. |
| `composer.json` | `analyse` script gains `--memory-limit=512M` | VERIFIED | Line 55: `"analyse": "./vendor/bin/phpstan analyse --memory-limit=512M"`. |
| `app/Services/ClaudePlannerService.php` | `?ModelUsage` → `ModelUsage` on `usageFromResponse()` | VERIFIED | `grep -n '?ModelUsage' app/Services/ClaudePlannerService.php` returns 0 matches. |
| `app/Services/ClaudeSelectorService.php` | `?ModelUsage` → `ModelUsage` on `usageFromResponse()` | VERIFIED | `grep -n '?ModelUsage' app/Services/ClaudeSelectorService.php` returns 0 matches. |
| `app/Support/HomeDirectory.php` | redundant `isset($pwinfo['dir'])` removed | VERIFIED | Plan 21-01 SUMMARY verified; PHPStan reports 0 errors corroborates. |
| `app/Support/RunLogStore.php` | NOT touched across Phase 21 (TASK-04 negative assertion) | VERIFIED | `git log --oneline 25e9c6b..HEAD -- app/Support/RunLogStore.php` returns 0 commits. `git diff 25e9c6b..HEAD -- app/Support/RunLogStore.php` returns empty. |
| `phpstan.neon` | NOT touched (D-17 — memory-limit shipped via composer-script only) | VERIFIED | `git diff 25e9c6b..HEAD -- phpstan.neon` returns empty. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `RunOrchestratorService::run()` line 124 | `$runId` local | inline `str_replace + gmdate` | WIRED | Single derivation site; reachable from every subsequent paired write and the finally arm. |
| `RunOrchestratorService::run()` 7 paired-write sites | `TaskDirectoryWriterService::writeRunStatus` | `$this->taskWriter?->writeRunStatus(...)` | WIRED | All 7 lifecycle states covered (`new`/`selected`/`planning`/`planned`/`executing`/`verifying`/`pr_open`). 8th state `blocked` emitted via writeRunBlockedIfNotTerminal delegate. |
| `RunOrchestratorService::run()` finally arm line 354 | `writeRunBlockedIfNotTerminal` | sibling try/catch (own `Warning:` log) | WIRED | Guard order `taskWriter !== null && selectedIssue !== null && runId !== null && writerRepoSlug !== null` matches D-08. Never re-throws. |
| `RunOrchestratorService::run()` line 378 | `writeRunOutcome` | sibling try/catch reading `$payload` after JSONL append | WIRED | `$payload !== null` clause added to guard so outcome.md write is skipped if JSONL build failed. Reads payload that was just persisted via `runLogStore->append`; uses same `$payload` array. |
| `writeRunStatus` + `writeRunOutcome` | `atomicWrite()` primitive | tmp+rename inside `runDir` | WIRED | Both delegate to existing `atomicWrite()` at line 177. Test case 14 asserts no `.tmp` residue. |
| `outcomePayload()` | 9 D-05 frontmatter keys | match-expression + Z-form timestamp normalization | WIRED | Lines 450-481 contain match + gmdate(strtotime(...)) + the 9-key associative return. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|-------------------|--------|
| `writeRunStatus` `status.md` | `$state` parameter | hard-coded lifecycle literals in orchestrator (new/selected/planning/planned/executing/verifying/pr_open) + 'blocked' via delegate | YES — actual lifecycle values flow through | FLOWING |
| `writeRunOutcome` `outcome.md` | `$outcome` array | `outcomePayload($runId, $result, $payload, $startedAt, $caught)` reading from `$payload = payloadFromResult/partialPayload` | YES — payload carries real PR number/URL, cost from `AnthropicCostEstimator::combine(...)`, status from RunResult | FLOWING |
| `cost_usd` field | `$totalCost` | `$payload['usage']['total']` (ModelUsage from `AnthropicCostEstimator::combine`) → `.estimatedCostUsd` | YES — sourced from real Anthropic billing | FLOWING |
| `pr_url` field | `$payload['pr']['url']` | `$result->prUrl` from RunResult populated by GitHub PR creation | YES — real PR URL when run succeeds | FLOWING |
| `failure_reason` field | `$payload['failure_reason']` | `$result->failureReason` or `$caught?->getMessage()` | YES — real exception message on partial paths | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Full Pest suite passes | `./vendor/bin/pest --no-coverage` | `Tests: 155 passed (533 assertions)` | PASS |
| Target writer test file passes | `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php --no-coverage` | `Tests: 18 passed (90 assertions)` | PASS |
| `composer analyse` reports 0 errors | `composer analyse` | `[OK] No errors` (57/57 files) | PASS |
| TASK-04 invariant — `RunLogStore.php` untouched | `git diff 25e9c6b -- app/Support/RunLogStore.php` | empty output, exit 0 | PASS |
| D-17 invariant — `phpstan.neon` untouched | `git diff 25e9c6b -- phpstan.neon` | empty output, exit 0 | PASS |
| Writer materializes `runs/<run-id>/status.md` + `outcome.md` with all 9 D-05 keys | PHP eval calling `writeRunStatus` + `writeRunOutcome` against a `sys_get_temp_dir()` HOME | both files created; outcome.md frontmatter contains run_id, status, pr_number, pr_url, cost_usd, started_at, finished_at, failure_reason, partial | PASS |
| No orchestrator import in test file (D-19) | `grep 'use App\Services\RunOrchestratorService' tests/Feature/TaskDirectoryWriterServiceTest.php` | 0 matches | PASS |
| No beforeEach/afterEach in test file | `grep -cE 'beforeEach\|afterEach' tests/Feature/TaskDirectoryWriterServiceTest.php` | 0 | PASS |

### Probe Execution

No `scripts/*/tests/probe-*.sh` are declared by Phase 21 PLANs and the project has no conventional probe directory. Phase verification relies on Pest + PHPStan (both executed above). SKIPPED with reason: no probe convention in this project.

### Requirements Coverage

| Requirement | Source Plan(s) | Description | Status | Evidence |
|-------------|---------------|-------------|--------|----------|
| TASK-03 | 21-02, 21-03 | Each run writes a per-run subdirectory `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` capturing at minimum the PR URL (or a structured failure reason) and the final cost summary | SATISFIED | `writeRunOutcome` emits `pr_url`, `cost_usd`, `failure_reason` in `outcome.md`; orchestrator threads `$runId` end-to-end and calls it at line 378. Test case 12 asserts all 9 keys; behavioral spot-check confirmed. |
| TASK-04 | 21-01, 21-02, 21-03 | Existing `~/.copland/logs/runs.jsonl` JSONL log keeps working unchanged | SATISFIED | `git diff 25e9c6b -- app/Support/RunLogStore.php` empty across full Phase 21 branch. The `$runLogStore->append($payload)` call at `RunOrchestratorService.php:366` is preserved unchanged. |
| TASK-05 | 21-03 | Task-directory writer is exercised by Pest tests using a temporary `HOME` so no developer-machine state is touched | SATISFIED | 18 it-blocks in `tests/Feature/TaskDirectoryWriterServiceTest.php`, each using inline `sys_get_temp_dir()` + `$_SERVER['HOME']` save/swap/restore. No `mkdir` references outside `sys_get_temp_dir()`. Tests pass cleanly. |

REQUIREMENTS.md maps exactly TASK-03/TASK-04/TASK-05 to Phase 21 — no orphaned requirements. All three are claimed by at least one PLAN and satisfied by codebase evidence.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | — | — | — | No `TODO`/`FIXME`/`TBD`/`HACK`/`XXX`/`placeholder` markers in any modified file. |

### Human Verification Required

None. The phase deliverables are exercised end-to-end by the 18-case Pest suite against a temporary HOME, the behavioral spot-check confirmed the actual `runs/<run-id>/` files materialize, and the negative assertions (RunLogStore untouched, phpstan.neon untouched, no debt markers) are mechanically verifiable. No visual, real-time, or external-service behavior is in-scope for this phase.

### Gaps Summary

No gaps. Every must-have truth is backed by codebase evidence:

- **TASK-03** (per-run subdir with PR URL + cost summary): the writer emits the directory + 2 files; the orchestrator wires the call; tests pin the on-disk shape; behavioral spot-check materialized the files.
- **TASK-04** (JSONL log untouched): negative `git diff` assertion holds across the entire Phase 21 branch (verified by comparing against `25e9c6b`, the last commit before Phase 21 work).
- **TASK-05** (Pest end-to-end against temp HOME): 18 it-blocks, all green, all using inline temp-HOME idiom, no developer-machine writes.
- **ROADMAP SC4** (PHPStan + 132+ tests still pass): `composer analyse` reports 0 errors; Pest reports 155 passed (well above the 132 baseline).

The Plan 21-02 deviation note (instanceof narrowing instead of `?->` chaining for `cost_usd`) is semantically equivalent and was forced by the Plan 21-01 PHPStan baseline — accepted.

---

_Verified: 2026-05-27_
_Verifier: Claude (gsd-verifier)_

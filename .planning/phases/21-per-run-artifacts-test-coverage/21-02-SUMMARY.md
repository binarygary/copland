---
phase: 21-per-run-artifacts-test-coverage
plan: 02
subsystem: orchestrator-writer
tags: [task-writer, orchestrator, per-run, outcome-md, atomic-write]
requires:
  - "21-01 (PHPStan baseline clean + defensive null inits in place)"
provides:
  - "TaskDirectoryWriterService extended with 3 new public methods (writeRunStatus, writeRunOutcome, writeRunBlockedIfNotTerminal) + 1 private runDir helper"
  - "RunOrchestratorService::run() threads \\$runId end-to-end (1 derivation + 7 paired writes + 1 finally-arm blocked + 1 outcome.md)"
  - "outcome.md frontmatter mapper producing the 9 D-05 keys"
affects:
  - app/Services/TaskDirectoryWriterService.php
  - app/Services/RunOrchestratorService.php
tech-stack:
  added: []
  patterns:
    - "3-tuple \\$lastState keying (repoSlug/taskId/runs/runId) coexists with existing 2-tuple task-level keying"
    - "Paired adjacent task-level + per-run writes at every lifecycle transition"
    - "Sibling finally-arm try/catch blocks — five sibling arms total (cleanup, task-level blocked, per-run blocked, JSONL append, outcome.md)"
    - "DATE_ATOM -> Z-form timestamp normalization via gmdate(strtotime())"
key-files:
  created: []
  modified:
    - app/Services/TaskDirectoryWriterService.php
    - app/Services/RunOrchestratorService.php
decisions:
  - "D-12 honored: 3 new public methods on the writer; 4 existing methods byte-for-byte unchanged"
  - "D-13 honored: new methods reuse atomicWrite + renderFrontmatter primitives; new runDir() one-liner adds the only new private helper"
  - "D-14 honored: writer remains silent — no pushLog/progressCallback inside the new methods"
  - "D-15 honored: app/Support/RunLogStore.php untouched (TASK-04 invariant)"
  - "D-17 honored: phpstan.neon untouched (composer.json memory-limit change already shipped in 21-01)"
  - "Replaced \\$payload['usage']['total']?->estimatedCostUsd ?? 0.0 with instanceof ModelUsage narrowing — PHPStan rejected the nullsafe access on mixed (array offset) typing"
metrics:
  duration: "~12 min implementation + verification"
  completed: "2026-05-27"
  tasks: 2
  commits: 2
  files_changed: 2
  lines_added: 133
  lines_removed: 0
---

# Phase 21 Plan 02: Per-run subdirectory writes + outcome.md mapper Summary

Extended `TaskDirectoryWriterService` with 3 new public methods and threaded a per-run `$runId` through `RunOrchestratorService::run()` so every existing task-level lifecycle write now has an adjacent per-run sibling under `~/.copland/tasks/<repo>/<id>/runs/<run-id>/`. A new private `outcomePayload()` mapper distills the existing JSONL payload into the 9 D-05 frontmatter keys for `outcome.md`, which is written once at terminal state from inside the JSONL-append finally arm via its own sibling try/catch.

## Commits

| Task | Commit  | Description |
|------|---------|-------------|
| 1    | a00e8a4 | feat(21-02): add writeRunStatus, writeRunOutcome, writeRunBlockedIfNotTerminal to TaskDirectoryWriterService |
| 2    | 7498ad3 | feat(21-02): thread $runId through RunOrchestratorService + paired writes + outcome.md mapper |

## New `TaskDirectoryWriterService` surface

The 3 new public methods (placed between the existing `writeBlockedIfNotTerminal` and the first private method `now()`):

```php
public function writeRunStatus(string $repoSlug, string|int $taskId, string $runId, string $state): void
public function writeRunOutcome(string $repoSlug, string|int $taskId, string $runId, array $outcome): void
public function writeRunBlockedIfNotTerminal(string $repoSlug, string|int $taskId, string $runId): void
```

The 1 new private helper (placed adjacent to `taskDir`):

```php
private function runDir(string $repoSlug, string|int $taskId, string $runId): string
{
    return $this->taskDir($repoSlug, $taskId)."/runs/{$runId}";
}
```

`$lastState` now carries a second per-tuple keying scheme:
- Task-level (existing): `"{repoSlug}/{taskId}"` → state
- Per-run (new, D-07): `"{repoSlug}/{taskId}/runs/{runId}"` → state

Both maps coexist; `writeRunBlockedIfNotTerminal` reads only the 3-tuple key, so a `pr_open`/`blocked` task-level final state does NOT suppress a per-run blocked write for a different run.

## `RunOrchestratorService` wiring (post-edit line numbers)

| What | Post-edit line |
|------|---------------|
| `$runId = null;` defensive init | line 45 (immediately after `$writerRepoSlug = null;` from 21-01) |
| `$runId` derivation (`str_replace(':', '-', gmdate('Y-m-d\TH:i:s\Z'))`) | line 124 |
| `writeRunStatus(..., 'new')` paired adjacent to existing `writeStatus(..., 'new')` | line 128 |
| `writeRunStatus(..., 'selected')` | line 130 |
| `writeRunStatus(..., 'planning')` | line 135 |
| `writeRunStatus(..., 'planned')` | line 189 |
| `writeRunStatus(..., 'executing')` | line 202 |
| `writeRunStatus(..., 'verifying')` | line 243 |
| `writeRunStatus(..., 'pr_open')` | line 295 |
| Finally-arm sibling `writeRunBlockedIfNotTerminal` block (own try/catch) | lines 352-358 (call at 354) |
| Finally-arm sibling `writeRunOutcome` block (own try/catch) | lines 376-381 (call at 378) |
| `private function outcomePayload(...)` declaration | line 450 |

The `blocked` lifecycle state (8th of the 8 task-level lifecycle states) is emitted by the finally-arm `writeRunBlockedIfNotTerminal` call — which internally delegates to `writeRunStatus(..., 'blocked')` inside the writer. So all 8 lifecycle states are covered in the per-run `status.md` transitions table even though only 7 literal `writeRunStatus` calls appear at orchestrator-level.

## `outcomePayload()` shipped shape

```php
private function outcomePayload(string $runId, ?RunResult $result, array $payload, string $startedAt, ?Throwable $caught): array
{
    $rawStatus = (string) ($payload['status'] ?? 'crashed');
    $status = match ($rawStatus) {
        'succeeded' => 'pr_open',
        'crashed' => 'crashed',
        default => 'blocked', // 'failed' | 'skipped' -> blocked
    };

    $startedAtZ = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) ($payload['started_at'] ?? $startedAt)));
    $finishedAtZ = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) ($payload['finished_at'] ?? date(DATE_ATOM))));

    $totalUsage = $payload['usage']['total'] ?? null;
    $totalCost = $totalUsage instanceof ModelUsage ? $totalUsage->estimatedCostUsd : 0.0;

    return [
        'run_id' => $runId,
        'status' => $status,
        'pr_number' => $payload['pr']['number'] ?? '',
        'pr_url' => (string) ($payload['pr']['url'] ?? ''),
        'cost_usd' => (string) $totalCost,
        'started_at' => $startedAtZ,
        'finished_at' => $finishedAtZ,
        'failure_reason' => (string) ($payload['failure_reason'] ?? ''),
        'partial' => ! empty($payload['partial']) ? 'true' : 'false',
    ];
}
```

The `match` expression collapses the 4-value JSONL `status` vocabulary (`succeeded`, `failed`, `skipped`, `crashed`) into the 3-value outcome.md vocabulary (`pr_open`, `blocked`, `crashed`) per RESEARCH §Outcome.md Mapping Table.

## Verification evidence

### PHPStan
- `composer analyse` exits 0 with `[OK] No errors`
- Initial PHPStan run after Task 2 edits flagged `nullsafe.neverNull` at `$payload['usage']['total']?->estimatedCostUsd` (line 463 pre-fix). Resolved by extracting to a typed `$totalUsage instanceof ModelUsage` narrow — see Deviation note below.

### Pest
- `./vendor/bin/pest --no-coverage` reports `Tests: 138 passed (458 assertions)` — zero regressions vs. the 21-01 baseline. (Plan 21-03 expands `TaskDirectoryWriterServiceTest.php` from 1 it-block to ~12-18.)

### TASK-04 invariant
- `git diff main -- app/Support/RunLogStore.php` → empty output (untouched)

### D-17 invariant
- `git diff main -- phpstan.neon` → empty output (untouched)

### Grep contract assertions
- `grep -cE "str_replace\(':', '-', gmdate" app/Services/RunOrchestratorService.php` → 1
- `grep -cE 'writeRunStatus\(\$writerRepoSlug' app/Services/RunOrchestratorService.php` → 7
- `grep -c 'writeRunBlockedIfNotTerminal(' app/Services/RunOrchestratorService.php` → 1
- `grep -c 'writeRunOutcome(' app/Services/RunOrchestratorService.php` → 1
- `grep -c 'private function outcomePayload' app/Services/RunOrchestratorService.php` → 1
- `grep -cE 'Warning: per-run blocked-state write failed' app/Services/RunOrchestratorService.php` → 1
- `grep -cE 'Warning: outcome write failed' app/Services/RunOrchestratorService.php` → 1
- `grep -c 'writeStatus(\$writerRepoSlug' app/Services/RunOrchestratorService.php` → 7 (existing task-level calls preserved exactly; per-run adds are strictly additive)
- For each lifecycle state `new/selected/planning/planned/executing/verifying/pr_open` → exactly 1 literal occurrence as 4th arg to `writeRunStatus`. The 8th state `blocked` is emitted via the finally-arm `writeRunBlockedIfNotTerminal` delegate.

### Writer file grep
- `grep -c 'public function writeRunStatus' app/Services/TaskDirectoryWriterService.php` → 1
- `grep -c 'public function writeRunOutcome' app/Services/TaskDirectoryWriterService.php` → 1
- `grep -c 'public function writeRunBlockedIfNotTerminal' app/Services/TaskDirectoryWriterService.php` → 1
- `grep -c 'private function runDir' app/Services/TaskDirectoryWriterService.php` → 1
- `grep -cE '\$this->lastState\["\{?\$?(repoSlug)\}?/\{?\$?(taskId)\}?/runs/' app/Services/TaskDirectoryWriterService.php` → 2 (D-07: 3-tuple key in writeRunStatus + 3-tuple read in writeRunBlockedIfNotTerminal)
- `grep -v '^#' app/Services/TaskDirectoryWriterService.php | grep -cE 'pushLog|progressCallback'` → 0 (D-14: writer remains silent)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan rejected `?->estimatedCostUsd ?? 0.0` pattern on mixed array-offset value**
- **Found during:** Task 2 verification (composer analyse)
- **Issue:** PHPStan level 5 flagged `$payload['usage']['total']?->estimatedCostUsd ?? 0.0` with `nullsafe.neverNull` — array offset returns `mixed`, on which the nullsafe access is deemed unnecessary. PATTERNS.md §Pattern F prescribed the `?->` form, but it cannot pass the 21-01 PHPStan baseline that Plan 21-02 is required to keep green.
- **Fix:** Imported `App\Data\ModelUsage` and replaced the nullsafe chain with an `instanceof` narrow:
  ```php
  $totalUsage = $payload['usage']['total'] ?? null;
  $totalCost = $totalUsage instanceof ModelUsage ? $totalUsage->estimatedCostUsd : 0.0;
  ```
- **Semantic equivalence:** identical behavior — `null` (or any non-ModelUsage) → 0.0; `ModelUsage` instance → its `estimatedCostUsd`. The PATTERNS.md shape was a recommendation; the D-05 contract only requires `cost_usd` to be a string-coercible numeric in the frontmatter, which both forms satisfy.
- **Files modified:** app/Services/RunOrchestratorService.php (added `use App\Data\ModelUsage;` and the 2-line narrow)
- **Commit:** 7498ad3 (Task 2)

## Success criteria status

- [x] 3 new writer public methods + 1 new private `runDir` helper added; 4 existing methods unchanged
- [x] `$runId` threaded through all per-run call sites in `RunOrchestratorService::run()` (1 derivation + 7 paired writes + 1 finally-arm blocked + 1 outcome.md write)
- [x] New `outcomePayload()` mapper produces the 9 D-05 frontmatter keys with correct status mapping (`succeeded → pr_open`) and timestamp normalization (DATE_ATOM → Z-form)
- [x] TASK-04 invariant preserved: `app/Support/RunLogStore.php` and `phpstan.neon` both untouched
- [x] PHPStan level 5 stays clean
- [x] Existing Pest suite still passes (138 tests, 458 assertions)

## Self-Check: PASSED

- FOUND: app/Services/TaskDirectoryWriterService.php (modified — 3 new public methods + 1 new private helper)
- FOUND: app/Services/RunOrchestratorService.php (modified — \$runId threading + 7 paired writes + 2 new finally-arm siblings + outcomePayload)
- FOUND commit a00e8a4 (Task 1)
- FOUND commit 7498ad3 (Task 2)
- FOUND: `composer analyse` exits 0
- FOUND: `./vendor/bin/pest --no-coverage` reports 138 passed
- VERIFIED: `git diff main -- app/Support/RunLogStore.php` empty
- VERIFIED: `git diff main -- phpstan.neon` empty

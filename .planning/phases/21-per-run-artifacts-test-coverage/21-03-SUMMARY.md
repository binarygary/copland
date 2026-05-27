---
phase: 21-per-run-artifacts-test-coverage
plan: 03
subsystem: pest-coverage
tags: [pest, writer-coverage, temp-home, clock-seam, per-run]
requires:
  - "21-02 (TaskDirectoryWriterService surface — 7 public methods)"
provides:
  - "Comprehensive Pest coverage of TaskDirectoryWriterService — 18 it-blocks against a temporary HOME"
  - "Phase 21 acceptance gate green: SC3 + SC4 reachable"
affects:
  - tests/Feature/TaskDirectoryWriterServiceTest.php
tech-stack:
  added: []
  patterns:
    - "Inline 4-line $_SERVER['HOME'] save/swap/restore — no beforeEach/afterEach"
    - "Counter-closure clock seam for multi-timestamp lifecycle tests"
    - "substr_count assertions for transitions-table row counts"
key-files:
  created: []
  modified:
    - tests/Feature/TaskDirectoryWriterServiceTest.php
decisions:
  - "Preserved Phase 20 smoke test as case #1 byte-for-byte"
  - "Hit the upper bound of the 12-18 range (18) — every D-18 axis gets a dedicated it-block"
  - "Used counter-closures only where multiple distinct timestamps are essential (lifecycle + 3-row append cases)"
  - "TASK-04 negative assertion verified across the entire Phase 21 branch: git diff main -- app/Support/RunLogStore.php is empty"
  - "D-17 invariant preserved: git diff main -- phpstan.neon is empty"
metrics:
  duration: "~6 min implementation + verification"
  completed: "2026-05-27"
  tasks: 1
  commits: 1
  files_changed: 1
  lines_added: 494
  lines_removed: 0
---

# Phase 21 Plan 03: Comprehensive Pest coverage of TaskDirectoryWriterService Summary

Grew `tests/Feature/TaskDirectoryWriterServiceTest.php` from the single Phase 20 smoke test (53 lines, 1 it-block, 15 assertions) into a comprehensive Pest suite of 18 it-blocks (547 lines, 90 assertions in this file alone) that exercises the entire `TaskDirectoryWriterService` surface — 4 existing methods plus the 3 new methods added by Plan 21-02 — against a temporary `HOME`. Every new case uses the canonical 4-line `$_SERVER['HOME']` swap idiom and the writer's native `clock:` seam for deterministic timestamps. This closes TASK-03 (run-dir artifacts verified), TASK-04 (JSONL untouched negative assertion verified), and TASK-05 (Pest coverage end-to-end), and makes ROADMAP SC3 + SC4 reachable.

## Commits

| Task | Commit  | Description |
|------|---------|-------------|
| 1    | 18a207d | test(21-03): expand TaskDirectoryWriterService Pest coverage to 18 it-blocks |

## It-block slug list (case → D-18 axis mapping)

| # | Slug | D-18 axis |
|---|------|-----------|
| 1 | writes task.md and status.md under a temporary HOME for a GitHub-shaped task | Phase 20 smoke (preserved unchanged) — GitHub int ID + repo-slug normalization + task.md + writeStatus + writeBlockedIfNotTerminal transitions |
| 2 | writes task.md for an Asana-shaped task with empty source_url and string GID | Both ID forms (Asana 13-digit GID); Asana `source_url: ""` invariant |
| 3 | writes task.md frontmatter with all 7 keys matching the TaskLoader contract | `writeNewTask` exact frontmatter key assertion (6 keys + body) |
| 4 | writeStatus produces an 8-row transitions table across the full lifecycle | All 8 lifecycle states for task-level writeStatus (counter-closure clock) |
| 5 | writeBlockedIfNotTerminal is a no-op after pr_open | writeBlockedIfNotTerminal terminal-state guard (pr_open) |
| 6 | writeBlockedIfNotTerminal is a no-op after blocked | writeBlockedIfNotTerminal terminal-state guard (blocked) |
| 7 | writeBlockedIfNotTerminal transitions executing -> blocked | writeBlockedIfNotTerminal non-terminal-to-blocked transition |
| 8 | writeRunStatus produces a per-run transitions table for the full lifecycle | All 8 lifecycle states for per-run writeRunStatus (counter-closure clock) |
| 9 | writeRunStatus accepts a 13-digit string task id and a Z-form run id | Both ID forms in per-run context |
| 10 | writeRunBlockedIfNotTerminal respects per-run pr_open as terminal | writeRunBlockedIfNotTerminal terminal-state guard |
| 11 | writeRunBlockedIfNotTerminal transitions verifying -> blocked | writeRunBlockedIfNotTerminal non-terminal-to-blocked transition |
| 12 | writeRunOutcome emits all 9 D-05 frontmatter keys | writeRunOutcome frontmatter key coverage (contain + multiline regex) |
| 13 | writeRunOutcome accepts an optional body with a per-stage usage table | writeRunOutcome optional `_body` discretion (stripped from frontmatter) |
| 14 | atomic write leaves no .tmp residue after a successful write | Atomic-rename correctness (`*.tmp` glob empty after writeStatus + writeRunStatus + writeRunOutcome) |
| 15 | writing twice into the same task/run dir is idempotent | Idempotent directory creation (no exception on second mkdir attempt) |
| 16 | three sequential writeStatus calls produce a 3-row table not an overwrite | Transitions-table append-only behavior (counter-closure clock) |
| 17 | lastState map keeps task-level and per-run tuples isolated | `$lastState` per-tuple isolation (per-run no-op when only task-level state set; D-15: never invents state) |
| 18 | writeStatus and writeRunStatus on the same task do not cross-pollute lastState | `$lastState` per-tuple isolation (forward direction — writeBlockedIfNotTerminal does not touch per-run state) |

## All 11 D-18 axes coverage map

- **Both ID forms (GitHub int + Asana 13-digit GID):** cases 1, 2, 3, 9 (task-level) + 8, 9 (per-run)
- **All 8 lifecycle states for task-level writes:** case 4 (8-row table)
- **All 8 lifecycle states for per-run writes:** case 8 (8-row table)
- **writeBlockedIfNotTerminal terminal-state guard:** cases 5 (pr_open), 6 (blocked), 7 (positive transition)
- **writeRunBlockedIfNotTerminal terminal-state guard:** cases 10 (pr_open), 11 (positive transition)
- **writeNewTask 7-key frontmatter assertion:** case 3 (also cross-asserted in case 1, 2)
- **writeRunOutcome 9-key frontmatter assertion:** case 12 (multiline regex covers every key)
- **Atomic-rename correctness:** case 14
- **Idempotent directory creation:** case 15
- **Transitions-table append-only:** case 16 (also implicit in cases 4 and 8)
- **`$lastState` per-tuple isolation:** cases 17, 18

Two axes (lifecycle 8-row tables) are split across task-level and per-run as separate cases (4 + 8); writeBlockedIfNotTerminal terminal-state guard is split into one case per terminal vocabulary value (`pr_open` + `blocked` = cases 5 + 6); both ID forms appear in multiple cases for defense in depth.

## Final test file shape

- Line count: **547** (up from 53; +494 net insertions)
- It-block count: **18** (target range was 12-18; hit the upper bound)
- Total assertions in this file: **90** (up from 15)
- `sys_get_temp_dir` occurrences: 18 (every it-block opens its own temp HOME)
- `$originalHome` save/restore pairs: 36 (2 per it-block — perfect symmetry)
- `beforeEach` / `afterEach`: 0 (canonical idiom preserved)
- `use App\Services\RunOrchestratorService`: 0 (D-19 honored — no orchestrator integration)
- `writeRun*` invocations: 22 across the new cases (target ≥6)

## Verification evidence

### Pest (target file only)

```
Tests: 18 passed (90 assertions)
Duration: 0.42s
```

### Pest (full suite)

```
Tests: 155 passed (533 assertions)
Duration: 1.15s
```

That is the 138-baseline from Plan 21-02 + 17 new it-blocks in this file = 155 passed. (Note: the Phase 20 baseline was 132+; the worktree post-21-02 baseline is 138; this plan adds 17 net new it-blocks to reach 155.)

### PHPStan

```
57/57 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

`composer analyse` (which now invokes `--memory-limit=512M` per Plan 21-01) exits 0.

### TASK-04 negative assertion (across the entire Phase 21 branch)

```bash
$ git diff main -- app/Support/RunLogStore.php
(empty)
```

`app/Support/RunLogStore.php` is byte-for-byte unchanged from `main` across all three Phase 21 plans (21-01, 21-02, 21-03). TASK-04 invariant preserved.

### D-17 negative assertion

```bash
$ git diff main -- phpstan.neon
(empty)
```

`phpstan.neon` is byte-for-byte unchanged. The memory-limit bump shipped in Plan 21-01 via `composer.json` only.

### Contract grep assertions

| Grep | Expected | Actual |
|------|----------|--------|
| `grep -c '^it(' tests/Feature/TaskDirectoryWriterServiceTest.php` | ≥12 | **18** |
| `grep -cE "writeRunStatus\|writeRunOutcome\|writeRunBlockedIfNotTerminal" tests/Feature/TaskDirectoryWriterServiceTest.php` | ≥6 | **22** |
| `grep -c 'sys_get_temp_dir' tests/Feature/TaskDirectoryWriterServiceTest.php` | ≥12 | **18** |
| `grep -v '^#' tests/Feature/TaskDirectoryWriterServiceTest.php \| grep -cE 'beforeEach\|afterEach'` | 0 | **0** |
| `grep -c '\$originalHome' tests/Feature/TaskDirectoryWriterServiceTest.php` | ~2× it-blocks | **36** (= 2 × 18) |
| `grep -c 'use App\\Services\\RunOrchestratorService' tests/Feature/TaskDirectoryWriterServiceTest.php` | 0 | **0** |

## Deviations from Plan

None — plan executed exactly as written. No auto-fixes, no architectural decisions, no checkpoints, no auth gates. All 18 it-blocks landed in a single commit (`18a207d`) on the first verification run. The single plan task hit every contract grep in the `<done>` block on the first try.

The 12-18 it-block range gave the executor latitude; the implementation hit the upper bound (18) by following the suggested 18-case table in PATTERNS.md §Pattern H exactly — one it-block per D-18 axis, no consolidation. Two axes (lifecycle 8-row tables) are deliberately split task-level vs. per-run (cases 4 + 8), and one axis (writeBlockedIfNotTerminal terminal-state guard) is deliberately split into per-vocabulary cases (cases 5 + 6). These splits are documented in the plan's case-by-case prescription and are not deviations.

## Authentication gates

None.

## Success criteria status

- [x] Test file expanded from 1 to 18 it-blocks against a temporary HOME (TASK-05)
- [x] All 7 public writer methods exercised at least once (4 existing + 3 new from Plan 21-02)
- [x] All 11 D-18 coverage axes represented by ≥1 it-block
- [x] TASK-04 negative assertion preserved across the entire Phase 21 branch
- [x] PHPStan level 5 stays clean
- [x] Full Pest suite remains green (155 passed, 533 assertions)
- [x] Phase 21 is ready for `/gsd:verify-work` — all 4 ROADMAP success criteria reachable

## Self-Check: PASSED

- FOUND: tests/Feature/TaskDirectoryWriterServiceTest.php (modified — 494 line insertions, 0 deletions)
- FOUND commit 18a207d (Task 1)
- VERIFIED: `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` reports 18 passed (90 assertions)
- VERIFIED: `./vendor/bin/pest --no-coverage` reports 155 passed (533 assertions)
- VERIFIED: `composer analyse` exits 0 with `[OK] No errors`
- VERIFIED: `git diff main -- app/Support/RunLogStore.php` is empty (TASK-04 phase-gate negative assertion)
- VERIFIED: `git diff main -- phpstan.neon` is empty (D-17 invariant)
- VERIFIED: `grep -c '^it(' tests/Feature/TaskDirectoryWriterServiceTest.php` returns 18 (target ≥12)
- VERIFIED: No `beforeEach`/`afterEach` introduced
- VERIFIED: No `RunOrchestratorService` import in test file (D-19)

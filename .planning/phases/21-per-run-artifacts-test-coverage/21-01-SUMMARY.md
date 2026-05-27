---
phase: 21-per-run-artifacts-test-coverage
plan: 01
subsystem: static-analysis
tags: [phpstan, orchestrator, composer-script, type-safety]
requires:
  - "PHPStan 2.x already in require-dev (composer.json)"
  - "Existing `analyse` script in composer.json"
provides:
  - "PHPStan level-5 clean across `app/` tree"
  - "`composer analyse` runnable without manual --memory-limit flag"
  - "Type-narrowing prerequisite for 21-03 phase-gate acceptance criteria"
affects:
  - app/Services/ClaudePlannerService.php
  - app/Services/ClaudeSelectorService.php
  - app/Services/RunOrchestratorService.php
  - app/Support/HomeDirectory.php
  - composer.json
tech-stack:
  added: []
  patterns:
    - "Defensive null init at top of try-block scope for variables referenced in finally arms"
    - "Composer-script as canonical static-analysis invocation"
key-files:
  created: []
  modified:
    - app/Services/ClaudePlannerService.php
    - app/Services/ClaudeSelectorService.php
    - app/Services/RunOrchestratorService.php
    - app/Support/HomeDirectory.php
    - composer.json
decisions:
  - "D-16 honored: each PHPStan error fixed at the smallest mechanical surface (1-2 lines)"
  - "D-17 honored: bumped memory limit via composer-script entry, not phpstan.neon"
  - "PATTERNS Pattern B honored: $workspacePath intentionally NOT initialized — existing isset() guard handles undefined case"
  - "TASK-04 invariant preserved: app/Support/RunLogStore.php untouched"
metrics:
  duration: "~5 min implementation + verification"
  completed: "2026-05-27"
  tasks: 2
  commits: 2
  files_changed: 5
  lines_added: 8
  lines_removed: 6
---

# Phase 21 Plan 01: PHPStan level-5 cleanup and composer-script memory-limit Summary

Fixed all 6 RESEARCH-catalogued PHPStan level-5 errors against `app/` and updated `composer analyse` to default to `--memory-limit=512M`, so the comprehensive Pest suite in Plan 21-03 can assert "PHPStan reports 0 errors" as a phase-gate acceptance criterion. Five files touched, ~14 lines of net diff (8 insertions, 6 deletions). TASK-04 invariant preserved — `app/Support/RunLogStore.php` untouched.

## Commits

| Task | Commit  | Description |
|------|---------|-------------|
| 1    | 1535ef9 | fix(21-01): correct PHPStan return types and redundant isset |
| 2    | 16c3452 | fix(21-01): clear PHPStan errors in orchestrator finally arm and add memory-limit to composer analyse |

## Per-file diff size

```
 app/Services/ClaudePlannerService.php   | 2 +-
 app/Services/ClaudeSelectorService.php  | 2 +-
 app/Services/RunOrchestratorService.php | 6 ++++--
 app/Support/HomeDirectory.php           | 2 +-
 composer.json                           | 2 +-
 5 files changed, 8 insertions(+), 6 deletions(-)
```

Total source-line change: 8 insertions / 6 deletions. The two `?ModelUsage`→`ModelUsage` edits are 1 line each. The `HomeDirectory` `isset` removal is 1 line. The orchestrator block adds 2 init lines (`$repoPath = null;` and `$writerRepoSlug = null;`), edits one `isset()` guard (drops redundant `!== null`), and extends one finally-arm guard with an additional `$writerRepoSlug !== null` clause. The composer.json change is the single-line `scripts.analyse` update.

## composer.json scripts.analyse before/after

Before:
```json
"analyse": "./vendor/bin/phpstan analyse"
```

After:
```json
"analyse": "./vendor/bin/phpstan analyse --memory-limit=512M"
```

## Verification evidence

### PHPStan
Baseline before any edits:
```
[ERROR] Found 6 errors
```

After Task 1 (2 service return types + HomeDirectory isset):
```
[ERROR] Found 3 errors
```

After Task 2 (3 orchestrator edits + composer-script update):
```
[OK] No errors
```

Invocation: `composer analyse` exits 0 with `[OK] No errors` (no manual `--memory-limit` flag required).

### Pest
After both tasks the full Pest suite reports `Tests: 138 passed (458 assertions)` — zero regressions.

### TASK-04 invariant
`git diff main -- app/Support/RunLogStore.php` produces empty output. The file is not staged in either task commit. Plan 21-02 owns all `RunLogStore` work.

### phpstan.neon invariant (D-17)
`git diff main -- phpstan.neon` produces empty output. Memory limit was bumped via composer script, not config file.

### grep contract assertions
- `grep -nE '^\s+\$repoPath = null;' app/Services/RunOrchestratorService.php` → match at line 43
- `grep -nE '^\s+\$writerRepoSlug = null;' app/Services/RunOrchestratorService.php` → match at line 44
- `grep -v '^#' app/Services/RunOrchestratorService.php | grep -c '\$workspacePath !== null'` → `0` (redundant clause removed)
- `grep -c 'writerRepoSlug !== null' app/Services/RunOrchestratorService.php` → `1` (new finally-arm guard)
- `grep -c 'memory-limit=512M' composer.json` → `1`

## Deviations from Plan

None — plan executed exactly as written. No auto-fixes, no architectural decisions, no checkpoints. All 6 RESEARCH-catalogued errors resolved by the 7 edits the plan prescribed (3 return-type/isset edits in Task 1, 4 edits in Task 2 covering 3 orchestrator fixes plus the composer-script update).

## Success criteria status

- [x] All 6 RESEARCH-catalogued PHPStan errors eliminated
- [x] `composer analyse` is the single canonical invocation and does not require manual `--memory-limit=512M`
- [x] No behavioral changes (return-type narrowing + redundant-clause removal + defensive null init are PHPStan-only edits)
- [x] TASK-04 invariant preserved: `app/Support/RunLogStore.php` untouched
- [x] Pest suite remains green (138 tests, 458 assertions)
- [x] Phase 21-03 acceptance criteria can now assert "PHPStan reports 0 errors" as a phase-gate check

## Self-Check: PASSED

- FOUND: app/Services/ClaudePlannerService.php (modified)
- FOUND: app/Services/ClaudeSelectorService.php (modified)
- FOUND: app/Services/RunOrchestratorService.php (modified)
- FOUND: app/Support/HomeDirectory.php (modified)
- FOUND: composer.json (modified)
- FOUND commit 1535ef9 (Task 1)
- FOUND commit 16c3452 (Task 2)

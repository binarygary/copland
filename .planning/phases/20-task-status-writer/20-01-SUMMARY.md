---
phase: 20-task-status-writer
plan: 01
subsystem: backend-persistence
tags:
  - php
  - laravel-zero
  - filesystem
  - yaml-frontmatter
  - godot-console
requirements:
  - TASK-01
  - TASK-02
dependency-graph:
  requires:
    - app/Support/HomeDirectory.php
  provides:
    - TaskDirectoryWriterService::writeNewTask
    - TaskDirectoryWriterService::writeStatus
    - TaskDirectoryWriterService::writeBlockedIfNotTerminal
  affects:
    - console-godot/scripts/TaskLoader.gd (read-side contract — conforms to, does not modify)
tech-stack:
  added: []
  patterns:
    - hand-rolled YAML frontmatter (TaskLoader's parser is intentionally narrow)
    - tmp+rename atomic writes with tmp in destination dir
    - constructor-property promotion for callable + string seams (mirrors GitService::$runner)
    - in-memory state map keyed by "{repoSlug}/{taskId}" — never persisted
key-files:
  created:
    - app/Services/TaskDirectoryWriterService.php
    - tests/Feature/TaskDirectoryWriterServiceTest.php
  modified: []
decisions:
  - "Wrote source_url frontmatter key for both GH and Asana sources; Asana flows ''-string per RESEARCH Q1 (no AsanaService changes)"
  - "Hand-rolled renderFrontmatter() escapes backslash and double-quote so TaskLoader's strip-one-pair quote unwrap yields the original character"
  - "extractBody() preserves the existing transitions table when rewriting status.md so prior rows survive"
metrics:
  tasks_completed: 2
  files_created: 2
  files_modified: 0
  duration_minutes: ~15
  completed_date: 2026-05-27
---

# Phase 20 Plan 01: Task & Status Writer — Summary

Built `TaskDirectoryWriterService` — a silent, atomic-write filesystem writer that emits `task.md` and `status.md` files under `~/.copland/tasks/<repo-dir>/<task-id>/` in the exact YAML-frontmatter shape that `console-godot/scripts/TaskLoader.gd` reads, with constructor seams for clock/HOME injection so Phase 21 can layer a comprehensive Pest suite on top without modifying the writer.

## Artifacts

| Path | Lines | Purpose |
|------|-------|---------|
| `app/Services/TaskDirectoryWriterService.php` | 161 | The writer — 3 public methods, 5 private helpers, no external dependencies beyond `HomeDirectory` |
| `tests/Feature/TaskDirectoryWriterServiceTest.php` | 53 | One Pest smoke test, 15 assertions, exercises happy path against tmp HOME |

No other files were touched. The orchestrator wiring (Plan 02) is intentionally separate.

## Constructor signature (for Plan 02's wiring contract)

```php
public function __construct(
    private $clock = null,                    // ?callable: () => string ISO-8601 UTC; defaults to gmdate('Y-m-d\TH:i:s\Z')
    private ?string $homeOverride = null,     // ?string: alt root; defaults to HomeDirectory::resolve()
) {}
```

Public surface:

```php
public function writeNewTask(string $repoSlug, string|int $taskId, ?string $title, ?string $body, string $repoPath, ?string $sourceUrl): void;
public function writeStatus(string $repoSlug, string|int $taskId, string $state): void;
public function writeBlockedIfNotTerminal(string $repoSlug, string|int $taskId): void;
```

`writeBlockedIfNotTerminal` is a no-op when the in-memory last-state for the (repoSlug, taskId) tuple is null, `pr_open`, or `blocked` — designed to slot into `RunOrchestratorService::run()`'s existing `try/catch/finally` cleanup arm per D-11.

## Verification

- `php -l app/Services/TaskDirectoryWriterService.php` — No syntax errors detected.
- `./vendor/bin/pint --test app/Services/TaskDirectoryWriterService.php tests/Feature/TaskDirectoryWriterServiceTest.php` — passed.
- `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` — `Tests: 1 passed (15 assertions)`.
- `./vendor/bin/pest` (full suite) — `Tests: 138 passed (458 assertions)`. Zero regressions.
- Manual `php -r '...'` integrity check from plan verification produced valid YAML frontmatter and a transitions table.

### Task 1 acceptance grep audit (all pass)

| Check | Required | Actual |
|-------|----------|--------|
| `namespace App\Services;` | == 1 | 1 |
| `class TaskDirectoryWriterService` | == 1 | 1 |
| `public function writeNewTask(` | == 1 | 1 |
| `public function writeStatus(` | == 1 | 1 |
| `public function writeBlockedIfNotTerminal(` | == 1 | 1 |
| `HomeDirectory::resolve()` | >= 1 | 1 |
| `str_replace('/', '__',` | == 1 | 1 |
| `rename(` | >= 1 | 1 |
| `RuntimeException` | >= 2 | 4 |
| `Yaml::dump|symfony/yaml` | == 0 | 0 |
| `pushLog|progressCallback` | == 0 | 0 |
| `private $clock` | == 1 | 1 |
| `private ?string $homeOverride` | == 1 | 1 |
| `private array $lastState` | == 1 | 1 |
| line count between 80–250 | yes | 161 |

### Task 2 acceptance grep audit (all pass)

| Check | Required | Actual |
|-------|----------|--------|
| `use App\Services\TaskDirectoryWriterService;` | == 1 | 1 |
| `$_SERVER['HOME']` | >= 3 | 3 |
| `clock: fn` | == 1 | 1 |
| `binarygary__copland/42` | >= 1 | 2 |
| `repo_slug: "binarygary/copland"` | == 1 | 1 |
| `Mockery|mock(` | == 0 | 0 |

## Commits

| Hash | Type | Description |
|------|------|-------------|
| `3f203ef` | test | RED: failing smoke test against missing service |
| `359eec8` | feat | GREEN: TaskDirectoryWriterService implementation |

## Decisions Made

1. **`extractBody()` parses the existing status.md by splitting on the closing `---\n`** — chosen over re-tracking transitions in-memory because (a) the writer must survive process restarts cleanly, (b) the file is at most ~10 transitions × ~60 bytes ≈ 600 bytes, so re-reading is trivially cheap, and (c) it keeps `$lastState` scoped narrowly to "last state written by THIS process," which is exactly what `writeBlockedIfNotTerminal` needs.
2. **Single-line comment above `atomicWrite()` documents the rename-atomicity invariant** per CLAUDE.md guidance ("Document non-obvious algorithm choices or workarounds"). No other comments in the class — the type hints carry the rest of the documentation weight.
3. **No `blocked_reason` field added to status.md** (deferred per D-decisions "Claude's discretion"). The exception text would need to be threaded from `RunOrchestratorService`'s catch block; that's a Plan 02 concern, not Plan 01's. Phase 20 ships the writer; if Plan 02 wants `blocked_reason`, it can extend the writer signature additively.
4. **Wrote `source_url: ""` for null/empty Asana sources** per RESEARCH Pitfall 6 / Open Q1 — the writer is source-agnostic and does not modify `AsanaService`.

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

- `app/Services/TaskDirectoryWriterService.php` — FOUND
- `tests/Feature/TaskDirectoryWriterServiceTest.php` — FOUND
- Commit `3f203ef` — FOUND in git log (RED smoke test)
- Commit `359eec8` — FOUND in git log (GREEN implementation)
- All 14 Task 1 acceptance criteria — PASSED
- All 7 Task 2 acceptance criteria — PASSED
- Full Pest suite — 138 passed, 0 failed
- Pint lint — passed on both files
- PHP `-l` syntax check — clean
- Plan-level TDD gate: RED commit (test) precedes GREEN commit (feat) — compliant

## TDD Gate Compliance

- RED gate: `3f203ef` `test(20-01): add failing smoke test for TaskDirectoryWriterService` — verified failing prior to implementation (`Tests: 1 failed (0 assertions)` before `app/Services/TaskDirectoryWriterService.php` was written).
- GREEN gate: `359eec8` `feat(20-01): add TaskDirectoryWriterService for Godot console schema` — verified passing immediately after (`Tests: 1 passed (15 assertions)`).
- REFACTOR gate: not exercised — no cleanup pass was needed; the GREEN implementation matched plan style on first pass.

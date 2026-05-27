---
phase: 20-task-status-writer
plan: 02
subsystem: orchestrator
tags:
  - php
  - laravel-zero
  - orchestrator
  - lifecycle
  - composition-root
dependency_graph:
  requires:
    - 20-01 (TaskDirectoryWriterService class + writeNewTask/writeStatus/writeBlockedIfNotTerminal API)
  provides:
    - RunOrchestratorService lifecycle wiring for the writer (8 transitions + blocked finally arm)
    - RunCommand composition-root wiring that instantiates the writer for real `copland run` invocations
  affects:
    - app/Services/RunOrchestratorService.php (new ?TaskDirectoryWriterService constructor param + 9 call sites)
    - app/Commands/RunCommand.php (1 new use import + 1 new named argument)
tech_stack:
  added: []
  patterns:
    - Nullsafe operator (`?->`) on optional injected dependency for backward-compat with tests that construct the orchestrator without the new param
    - Single source-discrimination point per D-06 (one `instanceof AsanaTaskSource` branch derives `$writerRepoSlug`; every writer call site reuses the variable)
    - try/catch wrapper around the finally-arm `writeBlockedIfNotTerminal` call so writer-side errors never mask the original exception
key_files:
  created: []
  modified:
    - app/Services/RunOrchestratorService.php
    - app/Commands/RunCommand.php
decisions:
  - "D-06 honored: Asana sources use basename($repoProfile['repo_path']) as $writerRepoSlug — closes the on-disk directory vs. frontmatter repo_slug mismatch for Asana branch"
  - "D-12 honored: writer is silent; no pushLog or progressCallback calls added around the writes"
  - "D-15 honored: comprehensive Pest tests for the orchestrator are deferred to Phase 21; existing tests/Unit/RunOrchestratorServiceTest.php passes unchanged (backward-compat proof)"
  - "D-17 honored: no 'merged' state write"
metrics:
  duration_seconds: ~600
  completed_at: 2026-05-27T10:37:28Z
requirements:
  - TASK-01
  - TASK-02
---

# Phase 20 Plan 02: Wire TaskDirectoryWriterService into orchestrator lifecycle — Summary

Wires Plan 01's `TaskDirectoryWriterService` into `RunOrchestratorService::run()` at 8 happy-path transitions plus a `blocked` finally-arm write, and into `RunCommand::runRepo()` as a composition-root dependency. Net change: 26 added lines across two files, zero deletions, full Pest suite green.

## Diff sizes

| File | Lines added | Lines removed |
|------|-------------|---------------|
| `app/Services/RunOrchestratorService.php` | 24 | 0 |
| `app/Commands/RunCommand.php` | 2 | 0 |
| **Total** | **26** | **0** |

(`git diff --stat 9254df4..HEAD`)

## The 9 writer call sites (post-modification line numbers)

All 9 call sites pass `$writerRepoSlug` as the first argument — **NOT** raw `$repo` — closing the D-06 / SC3 invariant.

| # | State | File:Line | Verbatim |
|---|-------|-----------|----------|
| 1 | (writeNewTask) | `app/Services/RunOrchestratorService.php:117` | `$this->taskWriter?->writeNewTask($writerRepoSlug, $selectedIssue['number'], $selectedIssue['title'] ?? '', $selectedIssue['body'] ?? '', $repoProfile['repo_path'], $selectedIssue['html_url'] ?? '');` |
| 2 | `new` | `app/Services/RunOrchestratorService.php:118` | `$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'new');` |
| 3 | `selected` | `app/Services/RunOrchestratorService.php:119` | `$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'selected');` |
| 4 | `planning` | `app/Services/RunOrchestratorService.php:123` | `$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'planning');` |
| 5 | `planned` | `app/Services/RunOrchestratorService.php:176` | `$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'planned');` |
| 6 | `executing` | `app/Services/RunOrchestratorService.php:188` | `$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'executing');` |
| 7 | `verifying` | `app/Services/RunOrchestratorService.php:228` | `$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'verifying');` |
| 8 | `pr_open` | `app/Services/RunOrchestratorService.php:279` | `$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'pr_open');` |
| 9 | `blocked` (finally arm) | `app/Services/RunOrchestratorService.php:328` | `$this->taskWriter->writeBlockedIfNotTerminal($writerRepoSlug, $selectedIssue['number']);` (inside `if ($this->taskWriter !== null && $selectedIssue !== null)` guard + `try { ... } catch (\Throwable $e) { ... }` wrapper at lines 326–331) |

## $writerRepoSlug derivation block (D-06)

`app/Services/RunOrchestratorService.php:113–115`:

```php
$writerRepoSlug = $this->taskSource instanceof AsanaTaskSource
    ? basename($repoProfile['repo_path'])
    : $repo;
```

Single source-discrimination point. `instanceof AsanaTaskSource` appears exactly once in the file (acceptance criterion satisfied). For GitHub: `$writerRepoSlug === $repo === "owner/repo"`. For Asana: `$writerRepoSlug === basename of repo_path` (no slash, no `__` collapse).

## Composition-root wiring (`RunCommand.php`)

- Import added at `app/Commands/RunCommand.php:20`: `use App\Services\TaskDirectoryWriterService;` (alphabetical position between `RunOrchestratorService` and `VerificationService`).
- Named argument appended at `app/Commands/RunCommand.php:300`: `taskWriter: new TaskDirectoryWriterService,` — argument-less constructor (both seam parameters in the writer default to null; defaults are correct for production).

## Verification results

- `php -l app/Services/RunOrchestratorService.php` → `No syntax errors detected`
- `php -l app/Commands/RunCommand.php` → `No syntax errors detected`
- `./vendor/bin/pint --test app/Services/RunOrchestratorService.php` → `passed`
- `./vendor/bin/pint --test app/Commands/RunCommand.php` → `passed`
- `./vendor/bin/pest tests/Unit/RunOrchestratorServiceTest.php` → **7 passed (87 assertions)** — backward-compat confirmed (orchestrator's 9th positional arg in test fixture stays valid; the new 12th param defaults to null)
- `./vendor/bin/pest` (full suite) → **138 passed (458 assertions)** in 1.03s — zero regressions
- `php copland --help` → CLI loads, all commands listed without errors

## Acceptance criteria (Task 1 — orchestrator)

All grep-based criteria pass:

| Criterion | Expected | Actual |
|-----------|----------|--------|
| `private ?TaskDirectoryWriterService $taskWriter` | 1 | 1 ✓ |
| `$writerRepoSlug` references | ≥10 | 10 ✓ |
| `instanceof AsanaTaskSource` | 1 | 1 ✓ |
| `basename($repoProfile['repo_path'])` | 1 | 1 ✓ |
| `taskWriter?->writeNewTask($writerRepoSlug` | 1 | 1 ✓ |
| `taskWriter?->writeStatus($writerRepoSlug` | 7 | 7 ✓ |
| `taskWriter->writeBlockedIfNotTerminal($writerRepoSlug` | 1 | 1 ✓ |
| Raw `$repo` passed to any writer call | 0 | 0 ✓ |
| `taskWriter?->writeNewTask(` total | 1 | 1 ✓ |
| `taskWriter?->writeStatus(` total | 7 | 7 ✓ |
| `taskWriter->writeBlockedIfNotTerminal` total | 1 | 1 ✓ |
| `'merged'` (deferred per D-17) | 0 | 0 ✓ |
| state strings count | ≥7 | 7 ✓ |
| `writeBlockedIfNotTerminal` within 30 lines after `} finally {` | 1 | 1 ✓ |
| `$selectedIssue !== null` guard in finally arm | ≥1 | 1 ✓ |
| `try {` immediately before `writeBlockedIfNotTerminal` | ≥1 | 1 ✓ |

## Acceptance criteria (Task 2 — composition root)

| Criterion | Expected | Actual |
|-----------|----------|--------|
| `use App\Services\TaskDirectoryWriterService;` | 1 | 1 ✓ |
| `taskWriter: new TaskDirectoryWriterService` | 1 | 1 ✓ |
| `RunOrchestratorService(` instantiations | 1 | 1 ✓ (no duplication) |
| `'repo_path' => $path,` in `$repoProfile` | 1 | 1 ✓ (unchanged) |

## Line-number drift from planned insertion points

Planned insertion lines in the plan referenced pre-modification line numbers (current file at base 9254df4). Anchor strings ("Selected issue #", "[3/8] Running Claude planner", "Plan validated OK", "[6/8] Running Claude executor", "[7/8] Running verification", "Draft PR opened:", `} finally {`) were authoritative per plan WARNING. Each insertion was placed immediately adjacent to the anchor; post-modification line numbers shifted naturally as preceding insertions added lines.

| Anchor | Planned line | Post-modification site | Drift |
|--------|--------------|------------------------|-------|
| `Selected issue #` | 108 | 109 | +1 (post-Pint trailing-newline normalization) |
| `[3/8] Running Claude planner` | 111 | 122 | +11 (selection-block insertions above) |
| `Plan validated OK` | 163 | 175 | +12 |
| `[6/8] Running Claude executor` | 174 | 187 | +13 |
| `[7/8] Running verification` | 213 | 227 | +14 |
| `Draft PR opened:` | 263 | 278 | +15 |
| `} finally {` | 300 | 316 | +16 |

All drift is mechanical (cumulative effect of additive insertions) and matches expectation — no anchor strings were missing or ambiguous.

## Deviations from plan

**None — plan executed exactly as written.** The plan's `tdd="true"` annotation on both tasks was honored via the explicit deferral path documented in the deliverables note: "comprehensive Pest tests for orchestrator changes are Phase 21 (D-15), smoke test from 20-01 suffices for now." The existing `tests/Unit/RunOrchestratorServiceTest.php` serves as the backward-compatibility proof (all 7 cases still pass with zero test-side changes), and the Plan 01 writer smoke test continues to validate the writer's contract.

One operational deviation outside the plan's behavioral scope: on agent startup the worktree HEAD was on commit `918dd19` (pre-phase-20), missing the planning files and 20-01's writer. After confirming the protected-ref deny-list passed (HEAD on `worktree-agent-a0868874391a2eeab`, not main/master/etc), I ran `git reset --hard 9254df4` to align the worktree with the expected base ref declared in `EXPECTED_BASE`. This is permitted by the `<destructive_git_prohibition>` carve-out for `git reset --hard` "inside the `<worktree_branch_check>` step at agent startup."

## Confirmation of negative invariants

- No data classes (`app/Data/*`) modified.
- No other services modified (only `RunOrchestratorService.php`).
- No other commands modified (`PlanCommand`, `IssuesCommand`, `StatusCommand`, `ConsoleCommand`, `AutomateCommand` untouched).
- No `runs.jsonl` / `partialPayload()` arm modified.
- No new `pushLog` calls added for writer transitions (D-12 silent-writer invariant preserved).
- No `'merged'` state write (D-17 deferred to a future phase).
- No new package installs (zero composer changes).
- The orchestrator's catch arm and pre-existing finally cleanup arm are unmodified — the blocked write lives in the same finally block, between the workspace-cleanup arm and the run-log append arm.

## Known stubs

None. All deliverables are wired end-to-end. The composition-root instantiates the writer with no arguments (production defaults), the orchestrator calls each transition at the documented anchor, and the writer's existing Plan-01 smoke test continues to validate the on-disk file shape.

## Self-Check: PASSED

Verified:

- `app/Services/RunOrchestratorService.php` exists and contains all 9 writer call sites at the documented post-modification line numbers (grepped above).
- `app/Commands/RunCommand.php` exists with the new `use` import (line 20) and `taskWriter:` named argument (line 300).
- Commits `40d7060` (Task 1) and `aa44721` (Task 2) exist in the worktree branch history.
- Full Pest suite (138 tests, 458 assertions) passes.
- Pint clean on both modified files.

```
$ git log --oneline 9254df4..HEAD
aa44721 feat(20-02): wire TaskDirectoryWriterService into RunCommand composition root
40d7060 feat(20-02): wire TaskDirectoryWriterService into RunOrchestratorService lifecycle
```

---
phase: 16-tasksource-extraction
plan: "02"
subsystem: orchestration
tags: [refactor, interface, dependency-injection, tasksource]
dependency_graph:
  requires: [16-01]
  provides: [TaskSource-wired-orchestrator, AppServiceProvider-binding]
  affects: [RunOrchestratorService, AppServiceProvider, RunOrchestratorServiceTest]
tech_stack:
  added: []
  patterns: [interface-injection, container-binding]
key_files:
  created: []
  modified:
    - app/Services/RunOrchestratorService.php
    - app/Providers/AppServiceProvider.php
    - tests/Unit/RunOrchestratorServiceTest.php
decisions:
  - "Test file updated alongside production code to keep tests passing — constructor change required updating RunOrchestratorServiceTest makeOrchestrator() factory from GitHubService to TaskSource"
metrics:
  duration: "~8 minutes"
  completed_date: "2026-04-08"
  tasks_completed: 2
  files_changed: 3
---

# Phase 16 Plan 02: Wire TaskSource into RunOrchestratorService Summary

**One-liner:** Replaced all 6 direct GitHubService call sites in RunOrchestratorService with TaskSource interface calls, bound TaskSource to GitHubTaskSource in the container, and updated orchestrator tests to mock TaskSource.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Update RunOrchestratorService to inject and use TaskSource | 46f54a8 | app/Services/RunOrchestratorService.php |
| 2 | Bind TaskSource to GitHubTaskSource in AppServiceProvider | b3de769 | app/Providers/AppServiceProvider.php, tests/Unit/RunOrchestratorServiceTest.php |

## What Was Built

- `RunOrchestratorService` constructor now accepts `TaskSource $taskSource` instead of `GitHubService $github`
- All 6 call sites replaced: `fetchTasks` (was `getIssues`), `addComment` x3 (was `commentOnIssue`), `openDraftPr` (was `createDraftPr`), `removeTag` (was `removeLabel`)
- `AppServiceProvider::register()` binds `TaskSource::class` to a `GitHubTaskSource` instance backed by `GitHubService`
- `RunOrchestratorServiceTest` updated: `makeOrchestrator()` factory now accepts `?TaskSource` and mocks `TaskSource::class`; all 7 tests pass

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Updated RunOrchestratorServiceTest to match new constructor signature**
- **Found during:** Task 2 (running tests after AppServiceProvider change)
- **Issue:** `RunOrchestratorServiceTest::makeOrchestrator()` still passed `GitHubService` to `RunOrchestratorService` constructor, which now accepts `TaskSource`. Tests would fail at runtime.
- **Fix:** Updated all test scenarios to mock `TaskSource::class` instead of `GitHubService::class`, renamed parameter from `$github` to `$taskSource`, replaced `getIssues`/`commentOnIssue`/`createDraftPr`/`removeLabel` mock expectations with `fetchTasks`/`addComment`/`openDraftPr`/`removeTag`.
- **Files modified:** tests/Unit/RunOrchestratorServiceTest.php
- **Commit:** b3de769

## Known Stubs

None.

## Verification

- `grep -c "GitHubService\|github" app/Services/RunOrchestratorService.php` → 0
- Pint: `{"result":"pass"}` on both changed production files
- `php vendor/bin/pest tests/Unit/RunOrchestratorServiceTest.php` → 7 passed (87 assertions)

## Self-Check: PASSED

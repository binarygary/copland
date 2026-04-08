---
phase: 17-asana-integration
plan: "01"
subsystem: data-layer
tags: [rename, type-widening, asana, foundation]
dependency_graph:
  requires: []
  provides: [selectedTaskId-string-int-null]
  affects: [SelectionResult, RunResult, RunProgressSnapshot, ClaudeSelectorService, RunOrchestratorService, RunCommand, PlanCommand]
tech_stack:
  added: []
  patterns: [string|int|null union type for cross-source task identifiers]
key_files:
  created: []
  modified:
    - app/Data/SelectionResult.php
    - app/Data/RunResult.php
    - app/Support/RunProgressSnapshot.php
    - app/Services/ClaudeSelectorService.php
    - app/Services/RunOrchestratorService.php
    - app/Commands/RunCommand.php
    - app/Commands/PlanCommand.php
    - resources/prompts/selector.md
    - tests/Unit/RunOrchestratorServiceTest.php
    - tests/Unit/RunCommandOllamaProbeTest.php
    - tests/Feature/RunCommandTest.php
decisions:
  - "selectedTaskId typed string|int|null to accept Asana GIDs (16-digit strings) without PHP int truncation while still accepting GitHub integer issue numbers"
  - "Issue match in orchestrator uses (string) cast comparison so int 42 matches string '42' across both task sources"
  - "selector.md JSON schema field renamed selected_issue_number -> selected_task_id for source-agnostic naming"
metrics:
  duration: 8 minutes
  completed: "2026-04-08T19:49:15Z"
  tasks_completed: 2
  files_modified: 11
---

# Phase 17 Plan 01: selectedIssueNumber → selectedTaskId Rename Summary

**One-liner:** Widened `?int $selectedIssueNumber` to `string|int|null $selectedTaskId` across all data classes and call sites, enabling Asana GID strings to flow through the pipeline without PHP int truncation.

## What Was Done

Renamed `selectedIssueNumber` to `selectedTaskId` and widened the type from `?int` to `string|int|null` in three data classes and all downstream call sites. This is the foundational data migration enabling Asana integration — Asana GIDs are 16-digit numeric strings that would be silently truncated if stored as PHP `int`.

### Task 1: Data Classes and Snapshot (commit 4c5c24f)

- `app/Data/SelectionResult.php`: `?int $selectedIssueNumber` → `string|int|null $selectedTaskId`
- `app/Data/RunResult.php`: `?int $selectedIssueNumber` → `string|int|null $selectedTaskId`
- `app/Support/RunProgressSnapshot.php`: `?int $selectedIssueNumber` → `string|int|null $selectedTaskId`

### Task 2: All Call Sites (commit d40b0bb)

- `ClaudeSelectorService`: JSON key `selected_issue_number` → `selected_task_id`; named arg updated
- `RunOrchestratorService`: 9 occurrences updated — issue match uses `(string)` cast, all `RunResult` constructions, snapshot assignment, payload helpers (`payloadFromResult`, `partialPayload`)
- `RunCommand`: `github: new GitHubService` → `taskSource: new GitHubTaskSource(new GitHubService)` in orchestrator construction; `selectedIssueNumber` → `selectedTaskId` in `failedResultFromException` and `runLogPayload`
- `PlanCommand`: issue match uses `(string)` cast, error message updated
- `resources/prompts/selector.md`: JSON schema field renamed `selected_issue_number` → `selected_task_id`
- 3 test files updated to use `selectedTaskId`

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1 | 4c5c24f | feat(17-01): rename selectedIssueNumber to selectedTaskId in data classes |
| 2 | d40b0bb | feat(17-01): update all call sites to use selectedTaskId |

## Verification Results

- `grep -rn "selectedIssueNumber" app/ resources/` → zero matches
- `./vendor/bin/pest --no-coverage` → 109 passed (372 assertions)
- All three data files contain `string|int|null $selectedTaskId`
- `RunCommand` wires `GitHubTaskSource` into `RunOrchestratorService`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Updated test files not listed in plan**
- **Found during:** Task 2 verification
- **Issue:** Three test files (`RunOrchestratorServiceTest.php`, `RunCommandOllamaProbeTest.php`, `RunCommandTest.php`) contained `selectedIssueNumber` references that would have caused test failures
- **Fix:** Updated all three test files to use `selectedTaskId`
- **Files modified:** tests/Unit/RunOrchestratorServiceTest.php, tests/Unit/RunCommandOllamaProbeTest.php, tests/Feature/RunCommandTest.php
- **Commit:** d40b0bb

## Known Stubs

None — all renamed properties are wired through to active call sites.

## Self-Check: PASSED

- app/Data/SelectionResult.php: FOUND with `string|int|null $selectedTaskId`
- app/Data/RunResult.php: FOUND with `string|int|null $selectedTaskId`
- app/Support/RunProgressSnapshot.php: FOUND with `string|int|null $selectedTaskId`
- commit 4c5c24f: FOUND
- commit d40b0bb: FOUND
- Zero `selectedIssueNumber` occurrences in app/ or resources/: CONFIRMED
- 109 tests passing: CONFIRMED

---
phase: 17-asana-integration
plan: "04"
subsystem: asana-tasksource
tags: [asana, tasksource, runcommand, wiring]
dependency_graph:
  requires:
    - GlobalConfig::asanaToken() (Plan 02)
    - GlobalConfig::asanaProjectForRepo() (Plan 02)
    - GlobalConfig::asanaFiltersForRepo() (Plan 02)
    - RepoConfig::taskSource() (Plan 02)
    - AsanaService (Plan 03 — created in this plan as prerequisite)
  provides:
    - AsanaTaskSource implements TaskSource
    - RunCommand conditional task_source wiring
  affects:
    - app/Commands/RunCommand.php (wires AsanaTaskSource when task_source: asana)
tech_stack:
  added: []
  patterns:
    - TaskSource delegation pattern (mirrors GitHubTaskSource)
    - string cast on Asana GIDs in addComment/removeTag
    - Constructor injection for AsanaService and GitHubService
key_files:
  created:
    - app/Services/AsanaService.php
    - app/Services/AsanaTaskSource.php
    - tests/Unit/AsanaTaskSourceTest.php
  modified:
    - app/Commands/RunCommand.php
decisions:
  - "AsanaService.php created in this plan as prerequisite — Plan 03 had no SUMMARY.md and no AsanaService.php existed"
  - "AsanaTaskSource.taskId cast to (string) before delegation to AsanaService — Asana GIDs are strings, not ints"
  - "IssuePrefilterService continues to receive GitHubService directly — prefilter is GitHub-specific and not routed through TaskSource"
metrics:
  duration: "~15 minutes"
  completed: "2026-04-08"
  tasks_completed: 2
  files_modified: 4
---

# Phase 17 Plan 04: AsanaTaskSource and RunCommand Wiring Summary

**One-liner:** `AsanaTaskSource` implementing `TaskSource` created with string-cast GID delegation to `AsanaService`, and `RunCommand` updated to select the correct task source based on `task_source: asana` in `.copland.yml`.

## Tasks Completed

| Task | Name | Files |
|------|------|-------|
| Prerequisite | Created AsanaService (Plan 03 prerequisite) | app/Services/AsanaService.php |
| 1 | Create AsanaTaskSource implementing TaskSource | app/Services/AsanaTaskSource.php, tests/Unit/AsanaTaskSourceTest.php |
| 2 | Wire AsanaTaskSource into RunCommand with conditional task_source logic | app/Commands/RunCommand.php |

## What Was Built

### AsanaService (app/Services/AsanaService.php)

Asana REST API client following the GitHubService constructor pattern:
- `getOpenTasks(): array` — fetches open project tasks via `GET /projects/{gid}/tasks`, applies client-side tag and section filters (AND logic), returns selector-compatible format
- `addStory(string $taskGid, string $text): void` — posts comment via `POST /tasks/{gid}/stories`
- `removeTag(string $taskGid, string $tagName): void` — resolves tag GID then calls `POST /tasks/{gid}/removeTag`; no-op if tag absent
- Injectable `?Client $http = null` for test isolation
- `applyFilters()` checks project GID membership to prevent cross-project false positives

### AsanaTaskSource (app/Services/AsanaTaskSource.php)

`final class AsanaTaskSource implements TaskSource` with constructor injection of `AsanaService $asana` and `GitHubService $github`:
- `fetchTasks(repo, tags)` — ignores both args, delegates to `$this->asana->getOpenTasks()`
- `addComment(repo, taskId, body)` — casts `$taskId` to `string`, delegates to `$this->asana->addStory()`
- `openDraftPr(repo, branch, title, body)` — delegates to `$this->github->createDraftPr()` (PRs are always GitHub)
- `removeTag(repo, taskId, tag)` — casts `$taskId` to `string`, delegates to `$this->asana->removeTag()`

The string cast on `$taskId` is critical — Asana GIDs are 16-digit numeric strings that must not be cast to `int`.

### RunCommand updates (app/Commands/RunCommand.php)

- Added imports: `AsanaService`, `AsanaTaskSource`, `GitHubTaskSource`
- Added conditional task source factory before `$repoProfile` block:
  ```php
  $taskSource = $repoConfig->taskSource() === 'asana'
      ? new AsanaTaskSource(new AsanaService($globalConfig->asanaToken(), $globalConfig->asanaProjectForRepo($repo) ?? '', $globalConfig->asanaFiltersForRepo($repo)), new GitHubService)
      : new GitHubTaskSource(new GitHubService);
  ```
- Updated `RunOrchestratorService` instantiation: `taskSource: $taskSource`

### Tests (tests/Unit/AsanaTaskSourceTest.php)

4 delegation tests mirroring `GitHubTaskSourceTest`:
- `fetchTasks` ignores repo/tags and delegates to `getOpenTasks()`
- `addComment` casts taskId to string before `addStory()`
- `openDraftPr` delegates to `createDraftPr()`
- `removeTag` casts taskId to string before `AsanaService::removeTag()`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] AsanaService.php missing — Plan 03 was not executed**
- **Found during:** Task 1 pre-check
- **Issue:** `app/Services/AsanaService.php` did not exist. Plan 03 had no SUMMARY.md and no AsanaService file on disk. Plan 04 depends on AsanaService as a constructor argument to AsanaTaskSource.
- **Fix:** Created `app/Services/AsanaService.php` using the exact implementation from 17-03-PLAN.md task action, which already had the test file (`tests/Feature/AsanaServiceTest.php`) written.
- **Files modified:** app/Services/AsanaService.php
- **Commit:** (pending — Bash unavailable in this execution environment)

## Known Stubs

None — all delegations are wired to real service methods. Empty task lists naturally flow through the existing `skip_all` exit path via the selector returning `skip_all` decision.

## Note on Commits

Git commands (Bash tool) were unavailable during this execution. All files have been written/modified and are ready to be committed. The created/modified files are:
- `app/Services/AsanaService.php` (new)
- `app/Services/AsanaTaskSource.php` (new)
- `tests/Unit/AsanaTaskSourceTest.php` (new)
- `app/Commands/RunCommand.php` (modified — imports and conditional wiring)

## Self-Check

- app/Services/AsanaService.php — FOUND (created with getOpenTasks, addStory, removeTag, applyFilters, requestJson)
- app/Services/AsanaTaskSource.php — FOUND (final class implementing TaskSource, all 4 methods present)
- tests/Unit/AsanaTaskSourceTest.php — FOUND (4 delegation tests)
- app/Commands/RunCommand.php — FOUND (AsanaService, AsanaTaskSource, GitHubTaskSource imports; conditional $taskSource factory; taskSource: $taskSource in orchestrator)
- GlobalConfig asana getters — CONFIRMED present (from Plan 02)
- RepoConfig taskSource() — CONFIRMED present (from Plan 02)
- AsanaTaskSource casts taskId to string — CONFIRMED (lines 37 and 47)

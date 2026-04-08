---
phase: 16-tasksource-extraction
plan: "01"
subsystem: contracts
tags: [interface, delegation, tasksource, github]
dependency_graph:
  requires: []
  provides: [TaskSource interface, GitHubTaskSource]
  affects: [RunOrchestratorService (Plan 02), AppServiceProvider (Plan 03)]
tech_stack:
  added: []
  patterns: [interface extraction, delegation wrapper, constructor injection]
key_files:
  created:
    - app/Contracts/TaskSource.php
    - app/Services/GitHubTaskSource.php
  modified: []
key_decisions:
  - taskId typed string|int throughout for Asana GID compatibility (D-04)
  - GitHubService unchanged — GitHubTaskSource is a new thin wrapper (D-06)
  - (int) cast on taskId when delegating to GitHubService which accepts int issueNumber
metrics:
  duration: "1 min"
  completed: "2026-04-08"
  tasks_completed: 2
  files_created: 2
  files_modified: 0
---

# Phase 16 Plan 01: TaskSource Interface and GitHubTaskSource Summary

## One-liner

TaskSource interface with four generic method signatures and GitHubTaskSource as a final delegation wrapper over GitHubService.

## What Was Built

Two new files establishing the TaskSource contract:

**app/Contracts/TaskSource.php** — Interface in `App\Contracts` namespace with four methods:
- `fetchTasks(string $repo, array $tags): array`
- `addComment(string $repo, string|int $taskId, string $body): void`
- `openDraftPr(string $repo, string $branch, string $title, string $body): array`
- `removeTag(string $repo, string|int $taskId, string $tag): void`

Method names use generic task-neutral vocabulary (not GitHub-shaped) so the interface serves both GitHub Issues and Asana in Phase 17.

**app/Services/GitHubTaskSource.php** — `final class GitHubTaskSource implements TaskSource` in `App\Services` namespace. Constructor receives `GitHubService $github` via injection. Each method delegates to the corresponding GitHubService method with no logic:
- `fetchTasks()` → `getIssues()`
- `addComment()` → `commentOnIssue()` (with `(int)` cast on taskId)
- `openDraftPr()` → `createDraftPr()`
- `removeTag()` → `removeLabel()` (with `(int)` cast on taskId)

The `(int)` cast is required because GitHubService accepts `int $issueNumber`, while the interface uses `string|int $taskId` for forward-compatibility with Asana GIDs.

GitHubService.php was not modified.

## Commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create TaskSource interface | 5955883 | app/Contracts/TaskSource.php |
| 2 | Create GitHubTaskSource delegation wrapper | 2e5866a | app/Services/GitHubTaskSource.php |

## Verification

- Pint style check: passed (`{"result":"pass"}`)
- Namespaces confirmed: `App\Contracts` and `App\Services`
- All four interface methods present with correct signatures
- GitHubService unchanged (confirmed via git diff)
- Pre-existing test suite failure (`makePlan()` redeclaration between ClaudeExecutorServiceTest and RunOrchestratorServiceTest) confirmed pre-existing — not caused by this plan

## Deviations from Plan

None - plan executed exactly as written.

## Known Stubs

None.

## Self-Check: PASSED

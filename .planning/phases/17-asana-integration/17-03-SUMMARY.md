---
phase: 17-asana-integration
plan: "03"
subsystem: services
tags: [asana, http-client, guzzle, tdd]
dependency_graph:
  requires: [17-02]
  provides: [AsanaService]
  affects: [app/Services/AsanaService.php]
tech_stack:
  added: []
  patterns: [GitHubService-constructor-pattern, injectable-guzzle-client, client-side-filtering]
key_files:
  created:
    - app/Services/AsanaService.php
    - tests/Feature/AsanaServiceTest.php
  modified: []
decisions:
  - AsanaService takes no parameters on getOpenTasks — project GID and filters injected at construction time
  - removeTag fetches task tags to resolve GID before calling removeTag endpoint
  - Section filter checks project GID to prevent cross-project membership false positives
metrics:
  duration: ~15 min
  completed: "2026-04-08"
  tasks_completed: 1
  files_created: 2
  files_modified: 0
---

# Phase 17 Plan 03: AsanaService Implementation Summary

## One-liner

Asana REST API client with injectable Guzzle client, client-side tag/section filtering (AND logic), story posting, and tag removal by name with GID resolution.

## What Was Built

`AsanaService` is a `final class` in `App\Services` that provides the data access layer for Asana. It follows the `GitHubService` constructor pattern: accepts `?Client $http = null` for test isolation, with `string $token`, `string $projectGid`, and `array $filters` injected at construction.

Three public methods:
- `getOpenTasks()`: Fetches from `GET /projects/{gid}/tasks` with `completed_since=now`, applies client-side filters, returns selector-compatible `['number', 'title', 'body', 'labels']` arrays
- `addStory(string $taskGid, string $text)`: Posts to `POST /tasks/{gid}/stories`
- `removeTag(string $taskGid, string $tagName)`: Fetches current task tags to resolve GID, then calls `POST /tasks/{gid}/removeTag`; no-op if tag not present

Filtering is AND logic: tasks must satisfy all configured filters. Section filter checks `$membership['project']['gid'] === $this->projectGid` to avoid false positives when a task appears in multiple projects.

## Tests

`tests/Feature/AsanaServiceTest.php` covers all behaviors using Guzzle MockHandler + history middleware (same pattern as `GitHubServiceTest.php`):
- Returns selector-compatible format with correct fields
- Excludes tasks missing a required tag
- Excludes tasks not in required section of this project
- Does not match a section from a different project membership
- AND logic: both tag and section filters must match
- `addStory` sends correct POST body
- `removeTag` resolves GID from task tags and calls removeTag endpoint
- `removeTag` is a no-op when tag not on task (only 1 request, not 2)
- `RuntimeException` thrown on Guzzle error

## Deviations from Plan

None - plan executed exactly as written.

## Known Stubs

None. All methods are fully implemented.

## Self-Check

- [x] `app/Services/AsanaService.php` created with `final class AsanaService`
- [x] `tests/Feature/AsanaServiceTest.php` created with 9 test cases
- [x] Constructor: `(string $token, string $projectGid, array $filters = [], ?Client $http = null)`
- [x] Three public methods: `getOpenTasks()`, `addStory()`, `removeTag()`
- [x] `applyFilters()` implements AND logic for tags + section with project GID check
- [x] `base_uri` ends with `/` for correct Guzzle relative URI resolution

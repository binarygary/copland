---
phase: 17-asana-integration
plan: "05"
subsystem: tests
tags: [testing, asana, unit-tests, tdd]
dependency_graph:
  requires: [17-03, 17-04]
  provides: [regression-coverage-asana-pipeline]
  affects: [tests/Unit]
tech_stack:
  added: []
  patterns: [Guzzle MockHandler, Mockery]
key_files:
  created:
    - tests/Unit/AsanaServiceTest.php
  modified:
    - tests/Unit/AsanaTaskSourceTest.php
decisions:
  - AsanaServiceTest placed in tests/Unit (not Feature) to match plan artifact requirement; tests/Feature/AsanaServiceTest.php already existed and was left in place as integration coverage
  - Int-cast test added to AsanaTaskSourceTest to satisfy the 5-test minimum and cover the string|int $taskId interface contract
metrics:
  duration: ~15 min
  completed: "2026-04-08"
  tasks_completed: 2
  files_changed: 2
---

# Phase 17 Plan 05: Asana Integration Tests Summary

Unit tests for `AsanaService` and `AsanaTaskSource` covering the Asana pipeline contract, plus config getter coverage already validated in prior plan runs.

## What Was Built

**Task 1: `tests/Unit/AsanaServiceTest.php`** (new file, 9 tests)

Guzzle MockHandler-based unit tests for all `AsanaService` public methods:
- Fetches open tasks, verifies selector-format output (GID as string, correct keys)
- Verifies correct URL path, `completed_since=now`, `opt_fields` query params, and Bearer auth header
- Tag filter: excludes tasks missing required tag; passes tasks with required tag
- AND tag filter: task must have ALL required tags; missing one fails
- Section filter: excludes wrong section; passes correct section in this project
- Multi-project section: section name match in different project GID is excluded
- AND logic: tag+section; task with tag but wrong section excluded
- `addStory`: POST to `/tasks/{gid}/stories` with correct body `{"data":{"text":"..."}}`
- `removeTag` with removal: two requests — GET task tags, POST removeTag with resolved GID
- `removeTag` no-op: only one request when tag not present

**Task 2: `tests/Unit/AsanaTaskSourceTest.php`** (extended, +1 test, now 5 tests)

Added the missing int-to-string cast test:
- `addComment` with integer `$taskId = 42` — verifies `addStory` receives `'42'` (string)

GlobalConfigTest and RepoConfigTest were already complete from prior plan runs (17-02); no changes needed.

## Deviations from Plan

**1. [Rule 1 - Pre-existing work] GlobalConfigTest and RepoConfigTest already complete**
- Found during: Task 2
- Issue: Both files already had all 7 Asana config getter tests and 2 taskSource tests from plan 17-02 execution
- Fix: No changes needed to these files; only the missing int-cast test in AsanaTaskSourceTest was added
- Files modified: None (deviation from plan expectation, not from code correctness)

**2. [Rule 3 - Observation] AsanaServiceTest existed only in tests/Feature**
- Found during: Task 1
- Issue: `tests/Feature/AsanaServiceTest.php` existed with full coverage but the plan artifact requires `tests/Unit/AsanaServiceTest.php`
- Fix: Created `tests/Unit/AsanaServiceTest.php` with equivalent coverage; the Feature version was left in place (both directories run in the test suite)

## Known Stubs

None — all tests use concrete mock responses and verify real method behavior.

## Self-Check: PASSED

Files exist:
- `tests/Unit/AsanaServiceTest.php` — confirmed (9 tests)
- `tests/Unit/AsanaTaskSourceTest.php` — confirmed (5 tests)
- `tests/Unit/GlobalConfigTest.php` — confirmed (7 Asana tests already present)
- `tests/Unit/RepoConfigTest.php` — confirmed (2 taskSource tests already present)

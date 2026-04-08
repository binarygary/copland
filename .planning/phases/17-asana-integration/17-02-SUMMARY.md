---
phase: 17-asana-integration
plan: "02"
subsystem: config
tags: [asana, config, globalconfig, repoconfig]
dependency_graph:
  requires: []
  provides:
    - GlobalConfig::asanaToken()
    - GlobalConfig::asanaProjectForRepo(slug)
    - GlobalConfig::asanaFiltersForRepo(slug)
    - RepoConfig::taskSource()
  affects:
    - app/Services/AsanaTaskSource.php (Plan 03 — reads asanaToken, asanaProjectForRepo, asanaFiltersForRepo)
    - app/Commands/RunCommand.php (Plan 04 — reads taskSource() to select TaskSource implementation)
tech_stack:
  added: []
  patterns:
    - Null-coalescing getter pattern (mirrors existing claudeApiKey, baseBranch)
    - Slug-based repo lookup via foreach over repos() array
key_files:
  created: []
  modified:
    - app/Config/GlobalConfig.php
    - app/Config/RepoConfig.php
    - tests/Unit/GlobalConfigTest.php
    - tests/Unit/RepoConfigTest.php
decisions:
  - "configuredRepos() unchanged — Asana keys accessed separately via slug-based getters to preserve existing normalization contract"
metrics:
  duration: "~8 minutes"
  completed: "2026-04-08T19:48:44Z"
  tasks_completed: 2
  files_modified: 4
---

# Phase 17 Plan 02: Config Foundation for Asana Integration Summary

Asana configuration getters added to GlobalConfig and RepoConfig using null-coalescing getter pattern — zero behavioral change for existing GitHub repos, config foundation ready for AsanaTaskSource (Plan 03) and RunCommand task-source dispatch (Plan 04).

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add Asana getters to GlobalConfig (TDD) | 9f16fe4 (test), 10e10aa (feat) | app/Config/GlobalConfig.php, tests/Unit/GlobalConfigTest.php |
| 2 | Add taskSource() getter to RepoConfig (TDD) | 248d485 (test), 165fcbe (feat) | app/Config/RepoConfig.php, tests/Unit/RepoConfigTest.php |

## What Was Built

### GlobalConfig additions (app/Config/GlobalConfig.php)

Three new public methods inserted after `llmConfig()`:

- `asanaToken(): string` — returns `$this->data['asana_token'] ?? ''`
- `asanaProjectForRepo(string $slug): ?string` — iterates `repos()`, returns GID string for matching slug or null
- `asanaFiltersForRepo(string $slug): array` — iterates `repos()`, returns filters array for matching slug or empty array

The `configuredRepos()` method is unchanged. It normalizes to slug+path only. Asana data is retrieved via the new slug-based lookup getters.

### RepoConfig addition (app/Config/RepoConfig.php)

One new public method added at end of class:

- `taskSource(): string` — returns `$this->data['task_source'] ?? 'github'`

### Tests

7 new tests in GlobalConfigTest.php covering all behavior branches. 3 new tests in RepoConfigTest.php covering default, asana, and explicit-github cases. All tests written RED-first before implementation.

## Verification

```
grep -n "asanaToken\|asanaProjectForRepo\|asanaFiltersForRepo" app/Config/GlobalConfig.php
171:    public function asanaToken(): string
176:    public function asanaProjectForRepo(string $slug): ?string
187:    public function asanaFiltersForRepo(string $slug): array

grep -n "taskSource" app/Config/RepoConfig.php
113:    public function taskSource(): string
```

- GlobalConfig: 11/11 tests pass
- RepoConfig: 4/4 tests pass
- Full suite: pre-existing failures in RunOrchestratorServiceTest (unrelated to this plan, existed before this work)

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None.

## Self-Check: PASSED

- app/Config/GlobalConfig.php — FOUND (modified with 3 new methods)
- app/Config/RepoConfig.php — FOUND (modified with 1 new method)
- tests/Unit/GlobalConfigTest.php — FOUND (7 new tests)
- tests/Unit/RepoConfigTest.php — FOUND (3 new tests)
- Commits: 9f16fe4, 10e10aa, 248d485, 165fcbe — all present in git log

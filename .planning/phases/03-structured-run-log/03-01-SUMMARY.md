---
phase: 03-structured-run-log
plan: 01
subsystem: logging
tags: [logs, jsonl, home, persistence]
requires: []
provides:
  - Append-only JSON Lines storage under `~/.copland/logs/runs.jsonl`
  - Structural serialization for nested usage and cost data
affects: [run-logs, observability, home-resolution]
tech-stack:
  added: []
  patterns: [append-only-jsonl-storage, home-based-log-persistence]
key-files:
  created: [app/Support/RunLogStore.php, tests/Unit/RunLogStoreTest.php]
  modified: []
key-decisions:
  - "Used a dedicated `RunLogStore` helper so log persistence stays isolated from orchestration control flow."
  - "Serialized `ModelUsage` objects structurally instead of storing terminal-formatted cost strings."
patterns-established:
  - "Filesystem-backed runtime artifacts should resolve global paths through `HomeDirectory::resolve()`."
requirements-completed: [OBS-01]
duration: 10min
completed: 2026-04-03
---

# Phase 3 Plan 01 Summary

**Copland now has a dedicated append-only JSONL store for per-run records under `~/.copland/logs/runs.jsonl`**

## Accomplishments
- Added `RunLogStore` with HOME-based path resolution and automatic log-directory creation.
- Implemented newline-delimited JSON append behavior for one structured record per call.
- Added unit coverage proving JSONL append behavior and structural usage serialization.

## Verification
- `php -l app/Support/RunLogStore.php`
- `./vendor/bin/pest tests/Unit/RunLogStoreTest.php`

## Next Readiness

Wave 2 orchestration work can now persist records through a stable storage primitive instead of writing files inline.

---
*Phase: 03-structured-run-log*
*Completed: 2026-04-03*

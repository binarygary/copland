# Phase 21 Discussion Log

**Date:** 2026-05-27
**Phase:** 21 — Per-Run Artifacts & Test Coverage
**Mode:** discuss (default)

---

## Pre-discussion scout findings (informed gray-area selection)

- `console-godot/scripts/TaskLoader.gd:288` reads a `status.md` inside each `runs/<run-id>/` directory using the same frontmatter parser as the task-level files. This **constrains** the run-dir layout: per-run `status.md` schema must match task-level `state` + `updated_at`.
- `RunOrchestratorService.php` lines 320–344 show the existing terminal-finally block already builds the JSONL payload via `payloadFromResult` / `partialPayload`. Same data is the raw material for `outcome.md` — no new data sources needed.
- `phpstan analyse --memory-limit=1G` reports **6 pre-existing level-5 errors** (not caused by Phase 21). Bare invocation OOMs at 128M default.
- Existing Phase 20 writer (`TaskDirectoryWriterService`, 161 lines) is the extension target — 4 existing public methods + `atomicWrite` private helper, both seams (`$clock`, `$homeOverride`).

---

## Gray Areas Presented

1. Run ID format
2. Run-dir file layout
3. Per-run status tracking depth
4. Existing PHPStan errors + test scope

User selected all four.

---

## Area 1 — Run ID format

**Options presented:**
1. ISO timestamp `2026-05-27T19-15-22Z` (colons → dashes) — recommended
2. UUID v7 via `ramsey/uuid` (already a dep)
3. Short hash from started_at + pid

**User selected:** ISO timestamp.

**Decisions captured:** D-01 (format), D-02 (orchestrator-generated, not writer-generated, threaded through as parameter).

---

## Area 2 — Run-dir file layout

**Options presented:**
1. `status.md` + `outcome.md` — both markdown frontmatter (recommended)
2. `status.md` + `outcome.json`
3. Three files: `status.md` + `pr.md` + `cost.md`

**User selected:** Option 1 (markdown frontmatter throughout).

**Decisions captured:** D-03 (two files only), D-04 (status.md schema mirrors task-level exactly), D-05 (outcome.md 9-key frontmatter spec).

---

## Area 3 — Per-run status tracking depth

**Options presented:**
1. All 8 transitions per-run (mirror task-level) — recommended
2. Terminal state only
3. Two writes: `executing` at run start + terminal

**User selected:** All 8 transitions per-run.

**Decisions captured:** D-06 (paired writes adjacent to every task-level call site), D-07 (second `$lastState` map keyed by `(repoSlug, taskId, runId)`), D-08 (finally arm gets paired `writeRunBlockedIfNotTerminal`).

---

## Area 4 — Existing PHPStan + test scope (two sub-questions)

**Sub-question A: 6 pre-existing PHPStan level-5 errors**

Options:
1. Fix all 6 in this phase — recommended
2. Generate a baseline and freeze
3. Lower level or relax SC4

**User selected:** Fix all 6 in this phase.

**Decisions captured:** D-16 (dedicated plan to fix; ordered before main work so the test suite plan can assert level 5 is clean as part of its acceptance), D-17 (PHPStan memory OOM addressed via `phpstan.neon` config or documented invocation).

**Sub-question B: TASK-05 comprehensive test scope**

Options:
1. Writer-only (focused) — recommended
2. Writer + orchestrator integration
3. Writer-only + single orchestrator smoke test

**User selected:** Writer-only.

**Decisions captured:** D-18 (11 coverage axes — both ID forms, all 8 states, both paired writers, frontmatter exact-key assertions, atomic-rename correctness, idempotent dir-create, append-only transitions table, $lastState isolation), D-19 (no orchestrator integration tests in Phase 21).

---

## Deferred Ideas

- TaskLoader extension to render outcome.md → Phase 22 or v2.1
- Orchestrator integration tests → Phase 22 E2E smoke covers implicitly
- Optional `blocked_reason` on per-run status — Claude's discretion in implementation
- Stale run-dir cleanup / TTL → future operator-UX phase
- PID-locking for concurrent runs → carries forward from Phase 20 deferred

---

## Claude's Discretion (called out in CONTEXT.md)

- PHPStan invocation mechanism (composer script vs Makefile vs phpstan.neon tweak)
- outcome.md body content (frontmatter only vs frontmatter + per-stage usage table)
- Data class vs `array` for `writeRunOutcome` payload argument
- Test file organization (one big file vs split)
- Whether to add `blocked_reason` frontmatter key

---
phase: 21
slug: per-run-artifacts-test-coverage
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-27
---

# Phase 21 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (PHPUnit-based) |
| **Config file** | `phpunit.xml` (root) |
| **Quick run command** | `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` |
| **Full suite command** | `./vendor/bin/pest` |
| **Static analysis** | `./vendor/bin/phpstan analyse --memory-limit=512M` |
| **Estimated runtime** | ~4s (writer suite) / ~25s (full Pest) / ~30s (PHPStan after memory fix) |

---

## Sampling Rate

- **After every task commit:** Run quick command (writer test) + targeted PHPStan if file under analysis was touched
- **After every plan wave:** Run full Pest suite + full PHPStan
- **Before `/gsd:verify-work`:** Full Pest suite green AND PHPStan reports 0 errors
- **Max feedback latency:** 5 seconds (writer test) / 30 seconds (PHPStan)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------------|-----------|-------------------|-------------|--------|
| TBD-01 | 21-01 | 1 | SC4 (PHPStan clean) | N/A — no runtime behavior change | static analysis | `./vendor/bin/phpstan analyse --memory-limit=512M` returns exit 0, "Found 0 errors" | ✅ exists | ⬜ pending |
| TBD-02 | 21-02 | 2 | TASK-03, TASK-04 | Atomic rename — no partial file visible to Godot | unit + integration | `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` (smoke probe of new run-dir methods) | ❌ W0 expanded | ⬜ pending |
| TBD-03 | 21-02 | 2 | TASK-03 | Per-run state writes do not corrupt task-level state | unit | grep `$runId` count in `RunOrchestratorService.php` ≥10 (1 derivation + 9 paired writes); grep `RunLogStore` count for `git diff` == 0 (TASK-04 negative assertion) | ❌ W0 | ⬜ pending |
| TBD-04 | 21-03 | 3 | TASK-05 | Tests never touch developer `~/.copland/` | unit (comprehensive) | `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` (12–18 cases, all 11 axes per CONTEXT D-18) | ❌ W0 expanded | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*Negative assertion for TASK-04:* `git diff main..HEAD -- app/Support/RunLogStore.php` must be empty after each Phase 21 commit. The validation infrastructure invokes this as a one-liner from the test plan's verify command.

---

## Wave 0 Requirements

- [ ] `phpstan.neon` memory bump OR documented `composer phpstan` script that includes `--memory-limit=512M` so the bare PHPStan invocation does not OOM
- [ ] Expand `tests/Feature/TaskDirectoryWriterServiceTest.php` from 1 smoke test to ~12–18 cases covering all 11 axes from CONTEXT D-18:
  - Both ID forms (GitHub int + Asana 13-digit GID)
  - All 8 lifecycle states for task-level `writeStatus`
  - All 8 lifecycle states for per-run `writeRunStatus`
  - `writeBlockedIfNotTerminal` and `writeRunBlockedIfNotTerminal` early-return on terminal states
  - `writeNewTask` exact 7-key frontmatter assertion vs TaskLoader contract
  - Asana `source_url: ""` invariant
  - `writeRunOutcome` 9-key frontmatter coverage
  - Atomic-rename: no `.tmp` files after success
  - Idempotent dir-create: double-write does not error
  - Transitions-table append-only: 3 sequential writes produce 3 rows
  - `$lastState` per-tuple isolation: distinct (repoSlug, taskId) tuples do not cross-pollute

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| TaskLoader.gd's `load_runs()` reads the new run subdirs correctly | TASK-03 | Requires Godot GUI; cross-process integration | 1. After a `copland run` with the new writer, open Godot console; press F5. 2. Confirm the task row shows a runs count ≥1 (per `_count_runs()` line 261). 3. Confirm at least one per-run `status.md` is read without parser warnings in Godot console output. |
| Real overnight run produces a complete artifact tree | All 4 SC | Requires an actual issue + LLM credit spend | Phase 22 E2E smoke covers this; Phase 21 does not block on it |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (PHPStan memory fix + expanded writer suite)
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s (PHPStan ceiling)
- [ ] `nyquist_compliant: true` set in frontmatter after planner fills task IDs

**Approval:** pending

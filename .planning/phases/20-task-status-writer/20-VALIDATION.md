---
phase: 20
slug: task-status-writer
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-27
---

# Phase 20 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (PHPUnit-based) |
| **Config file** | `phpunit.xml` (root) |
| **Quick run command** | `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` |
| **Full suite command** | `./vendor/bin/pest` |
| **Estimated runtime** | ~3 seconds (quick) / ~25 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run quick command (writer test only)
- **After every plan wave:** Run full suite
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 5 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | TBD | TBD | TASK-01, TASK-02 | — | Atomic rename — Godot poller never reads partial file | unit + integration | `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*Per CONTEXT.md D-15: comprehensive Pest coverage is Phase 21's deliverable. Phase 20 ships one smoke-level happy-path test (recommended by research Q2) and validates writer correctness via integration with the orchestrator.*

---

## Wave 0 Requirements

- [ ] `tests/Feature/TaskDirectoryWriterServiceTest.php` — happy-path test using `$homeOverride` seam (writes task.md + status.md to tmp dir, asserts YAML frontmatter keys match TaskLoader contract)

*Comprehensive coverage (crash-recovery, both ID forms, all 8 transitions, atomic-rename race) is Phase 21 (TASK-05).*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Godot console F5 reads new task.md + status.md correctly | TASK-01, TASK-02 | Requires GUI; cross-process integration with Godot | 1. Run `copland run <repo>` with a real task. 2. Open Godot console; press F5. 3. Confirm row appears with correct title, state, repo_slug. 4. As orchestrator advances, press F5; confirm state column updates. |
| Crash recovery leaves `blocked` state | Success Criterion 4 | Requires forcing an exception or SIGINT mid-run | 1. Start `copland run`. 2. SIGINT after `executing` state is written. 3. `cat ~/.copland/tasks/<repo>/<id>/status.md` — frontmatter `state` MUST be `blocked`. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

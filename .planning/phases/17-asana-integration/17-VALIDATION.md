---
phase: 17
slug: asana-integration
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-08
---

# Phase 17 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest (PHP) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `./vendor/bin/pest --filter AsanaService` |
| **Full suite command** | `./vendor/bin/pest` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `./vendor/bin/pest --filter AsanaService`
- **After every plan wave:** Run `./vendor/bin/pest`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 17-01-01 | 01 | 1 | ASANA-01 | unit | `./vendor/bin/pest --filter GlobalConfigAsanaTest` | ❌ W0 | ⬜ pending |
| 17-01-02 | 01 | 1 | ASANA-01 | unit | `./vendor/bin/pest --filter RepoConfigAsanaTest` | ❌ W0 | ⬜ pending |
| 17-02-01 | 02 | 1 | ASANA-02 | unit | `./vendor/bin/pest --filter AsanaServiceTest` | ❌ W0 | ⬜ pending |
| 17-02-02 | 02 | 1 | ASANA-02 | unit | `./vendor/bin/pest --filter AsanaTaskSourceTest` | ❌ W0 | ⬜ pending |
| 17-03-01 | 03 | 2 | ASANA-03 | unit | `./vendor/bin/pest --filter AsanaServiceCommentTest` | ❌ W0 | ⬜ pending |
| 17-04-01 | 04 | 1 | ASANA-04 | unit | `./vendor/bin/pest --filter SelectionResultGidTest` | ❌ W0 | ⬜ pending |
| 17-05-01 | 05 | 2 | ASANA-05 | unit | `./vendor/bin/pest --filter RunCommandEmptyAsanaTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/AsanaServiceTest.php` — stubs for ASANA-02 (fetch tasks)
- [ ] `tests/Unit/AsanaTaskSourceTest.php` — stubs for ASANA-02 (TaskSource contract)
- [ ] `tests/Unit/GlobalConfigAsanaTest.php` — stubs for ASANA-01 (config parsing)
- [ ] `tests/Unit/SelectionResultGidTest.php` — stubs for ASANA-04 (GID as string)
- [ ] `tests/Unit/AsanaServiceCommentTest.php` — stubs for ASANA-03 (post comment)

*All test files must exist before Wave 1 tasks begin.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real Asana API call with PAT | ASANA-02 | Requires live Asana account and test project | Configure `~/.copland.yml` with valid PAT and project GID; run `copland run` against a repo; verify tasks are fetched |
| PR comment posted to Asana task | ASANA-03 | Requires live GitHub PR and Asana task | Run full pipeline; verify comment appears on Asana task with PR URL |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

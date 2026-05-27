# Roadmap: Copland

## Milestones

- ✅ **v1.0 Overnight Hardening** — Phases 1-13 shipped 2026-04-03 ([archive](milestones/v1.0-ROADMAP.md))
- ✅ **v1.1 Multi-Provider & Asana Integration** — Phases 14-17 shipped 2026-04-09 ([archive](milestones/v1.1-ROADMAP.md))
- ✅ **v1.2 Onboarding** — Phase 18 shipped 2026-05-26 (Phase 19 dropped — superseded by v2.0) ([archive](milestones/v1.2-ROADMAP.md))
- 🚧 **v2.0 Godot Console** — Phases 19-22 (planned)

---

## Shipped Phases

<details>
<summary>✅ v1.0 Overnight Hardening (Phases 1-13) — SHIPPED 2026-04-03</summary>

- [x] Phase 1: API Retry Backoff — completed 2026-04-03
- [x] Phase 2: Executor Hardening — completed 2026-04-03
- [x] Phase 3: Structured Run Log — completed 2026-04-03
- [x] Phase 4: Prompt Caching — completed 2026-04-03
- [x] Phase 5: Cache-Aware Cost Model — completed 2026-04-03
- [x] Phase 6: Multi-Repo Runner — completed 2026-04-03
- [x] Phase 7: Launchd Setup — completed 2026-04-03
- [x] Phase 8: Retry Wrapper Tests — completed 2026-04-03
- [x] Phase 9: Executor Tests — completed 2026-04-03
- [x] Phase 10: Orchestrator Tests — completed 2026-04-03
- [x] Phase 11: Documentation — completed 2026-04-03
- [x] Phase 12: Multi-Repo Failure Logging — completed 2026-04-03
- [x] Phase 13: Verification Backfill — completed 2026-04-03

</details>

<details>
<summary>✅ v1.1 Multi-Provider & Asana Integration (Phases 14-17) — SHIPPED 2026-04-09</summary>

- [x] Phase 14: LlmClient Contracts — completed 2026-04-08
- [x] Phase 15: Provider Implementations — completed 2026-04-08
- [x] Phase 16: TaskSource Extraction — completed 2026-04-08
- [x] Phase 17: Asana Integration — completed 2026-04-08

</details>

<details>
<summary>✅ v1.2 Onboarding (Phase 18) — SHIPPED 2026-05-26</summary>

- [x] Phase 18: Automate Command — completed 2026-04-09
- [~] Phase 19: Init Wizard — dropped 2026-05-26 (superseded by v2.0; INIT-01..07 deferred to future onboarding milestone)

</details>

---

## v2.0 Godot Console

**Goal:** Recover the lost Godot prototype onto `main`, and grow a task-directory persistence layer in the PHP CLI so the read-only console shows live overnight-agent state.

### Phases

- [x] **Phase 19: Prototype Recovery + Console Launcher** — Restore the Godot prototype from the backup branch onto `main` and add `copland console` to launch it (completed 2026-05-27)
- [x] **Phase 20: Task & Status Writer** — Orchestrator writes `task.md` and `status.md` per task and updates `status.md` on every lifecycle transition (completed 2026-05-27)
- [ ] **Phase 21: Per-Run Artifacts & Test Coverage** — Each run materializes a `runs/<run-id>/` subdirectory with PR/cost data, the existing JSONL log stays untouched, and Pest tests exercise the writer with a temporary `HOME`
- [ ] **Phase 22: End-to-End Smoke + Documentation** — A real overnight run renders in the console without errors and both READMEs document the shipped console workflow

### Phase Details

#### Phase 19: Prototype Recovery + Console Launcher

**Goal**: Restore the Godot 4.2+ prototype onto `main` so it can be opened and launched, and add a `copland console` PHP CLI subcommand that points the Godot project at `~/.copland/tasks/`.
**Depends on**: Nothing (first phase of v2.0)
**Requirements**: GODOT-01, GODOT-02, GODOT-03
**Success Criteria** (what must be TRUE):

  1. `console-godot/` exists on `main` with `project.godot`, `scenes/Main.tscn`, `scripts/Main.gd`, `scripts/TaskLoader.gd`, `icon.svg`, `README.md`, `TODO.md`, and the existing `assets/{fonts,textures,themes}/` directories preserved
  2. Opening `console-godot/project.godot` in Godot 4.2+ and pressing F5 launches the Copland Console without errors (empty-state rendering is acceptable since no task directories exist yet)
  3. `copland console` is a registered Laravel Zero command that launches the Godot project pointed at `~/.copland/tasks/` and exits cleanly
  4. `copland console` surfaces a clear error message if Godot is not installed or the `console-godot/` directory is missing

**Plans**: 2 plans

Plans:

- [x] 19-01-restore-godot-prototype-PLAN.md — Restore Godot prototype from backup branch as single checkout commit; manual F5 launch verification (GODOT-01, GODOT-02)
- [x] 19-02-console-command-PLAN.md — Add `copland console` Laravel Zero command with preflight + macOS `open -a Godot` shell-out, plus Pest tests (GODOT-03)

**UI hint**: yes

#### Phase 20: Task & Status Writer

**Goal**: When the orchestrator selects a task, it materializes `~/.copland/tasks/<repo>/<id>/task.md` once and updates `status.md` on every lifecycle transition so the console can read real run state.
**Depends on**: Phase 19
**Requirements**: TASK-01, TASK-02
**Success Criteria** (what must be TRUE):

  1. On task selection, `RunOrchestratorService` writes `~/.copland/tasks/<repo>/<id>/task.md` containing the task title, body, repo slug, repo path, source URL, and `created_at` timestamp
  2. On every orchestrator lifecycle transition (new → planning → executing → reviewing → complete | blocked) `status.md` is written/updated with the current state and a per-transition timestamp
  3. The writer works for both GitHub issues (integer ID) and Asana tasks (string GID) without truncation or path collisions
  4. A run that crashes mid-execution leaves `status.md` in a terminal state (`blocked` or equivalent) rather than a stale intermediate state

**Plans**: 2 plans

Plans:

- [x] 20-01-PLAN.md — Build TaskDirectoryWriterService (atomic markdown writer for ~/.copland/tasks/) + Pest smoke test against temp HOME (TASK-01, TASK-02)
- [x] 20-02-PLAN.md — Wire writer into RunOrchestratorService (8 lifecycle call sites + finally-block blocked write) and RunCommand composition root (TASK-01, TASK-02)

#### Phase 21: Per-Run Artifacts & Test Coverage

**Goal**: Each run captures its own audit trail under `runs/<run-id>/` alongside the existing JSONL log, and the entire task-directory writer is covered by Pest tests that never touch the developer's real `~/.copland/`.
**Depends on**: Phase 20
**Requirements**: TASK-03, TASK-04, TASK-05
**Success Criteria** (what must be TRUE):

  1. Each run writes a `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` subdirectory containing at minimum the PR URL (or a structured failure reason) and the final cost summary
  2. `~/.copland/logs/runs.jsonl` continues to be written with the same schema and content it had before this milestone — no existing log consumer regresses
  3. Pest tests exercise the task-directory writer end-to-end using a temporary `HOME`, covering happy path, lifecycle transitions, and failure/blocked outcomes
  4. PHPStan level 5 stays clean and the existing 132+ test suite continues to pass

**Plans**: 2 plans

Plans:

- [ ] 20-01-PLAN.md — Build TaskDirectoryWriterService (atomic markdown writer for ~/.copland/tasks/) + Pest smoke test against temp HOME (TASK-01, TASK-02)
- [ ] 20-02-PLAN.md — Wire writer into RunOrchestratorService (8 lifecycle call sites + finally-block blocked write) and RunCommand composition root (TASK-01, TASK-02)

#### Phase 22: End-to-End Smoke + Documentation

**Goal**: A real overnight-agent run produces a task directory the Godot console renders without errors, and both the root and `console-godot` READMEs document the shipped workflow.
**Depends on**: Phase 21
**Requirements**: CONS-01, CONS-02, CONS-03
**Success Criteria** (what must be TRUE):

  1. A real overnight run against a configured repo produces `~/.copland/tasks/<repo>/<id>/` directories that `TaskLoader.gd` loads without errors and without schema drift — task titles, statuses, and run metadata all appear in the console panes
  2. Root `README.md` documents installing Godot 4.2+, launching the console via `copland console`, and what each of the three panes (workflow states / task manifest / dossier) shows
  3. `console-godot/README.md` matches what shipped — file paths, what counts as "real" data, and any divergence from the original prototype design are called out explicitly
  4. The relationship between the new `~/.copland/tasks/` directory tree and the existing `~/.copland/logs/runs.jsonl` is documented so users know which is canonical for which purpose

**Plans**: 2 plans

Plans:

- [ ] 20-01-PLAN.md — Build TaskDirectoryWriterService (atomic markdown writer for ~/.copland/tasks/) + Pest smoke test against temp HOME (TASK-01, TASK-02)
- [ ] 20-02-PLAN.md — Wire writer into RunOrchestratorService (8 lifecycle call sites + finally-block blocked write) and RunCommand composition root (TASK-01, TASK-02)

**UI hint**: yes

### Progress Table

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 19. Prototype Recovery + Console Launcher | 2/2 | Complete    | 2026-05-27 |
| 20. Task & Status Writer | 2/2 | Complete   | 2026-05-27 |
| 21. Per-Run Artifacts & Test Coverage | 0/TBD | Not started | - |
| 22. End-to-End Smoke + Documentation | 0/TBD | Not started | - |

# Requirements — v2.0 Godot Console

**Milestone:** v2.0 Godot Console
**Goal:** Recover the lost Godot prototype onto `main`, and grow a task-directory persistence layer in the PHP CLI so the read-only console shows live overnight-agent state.

---

## v2.0 Requirements

### Prototype Recovery

- [x] **GODOT-01**: Godot prototype files (`console-godot/{project.godot, scenes/Main.tscn, scripts/Main.gd, scripts/TaskLoader.gd, icon.svg, README.md, TODO.md}`) are restored onto `main` from `backup/local-main-diverged-20260526` with the existing `console-godot/assets/{fonts,textures,themes}/` directories preserved
- [x] **GODOT-02**: `console-godot/README.md` run instructions work end-to-end on the current Godot 4.2+ install — opening `project.godot` in Godot and pressing F5 launches the Copland Console without errors
- [x] **GODOT-03**: User can run `copland console` (new PHP CLI subcommand) which launches the Godot project pointed at the live `~/.copland/tasks/` directory

### Backend Persistence

- [ ] **TASK-01**: When a run is selected, the orchestrator writes `~/.copland/tasks/<repo>/<id>/task.md` containing the task title, body, repo slug, repo path, source URL, and `created_at` timestamp
- [ ] **TASK-02**: The orchestrator writes/updates `~/.copland/tasks/<repo>/<id>/status.md` on every lifecycle transition (new → planning → executing → reviewing → complete | blocked) with a timestamp per transition
- [ ] **TASK-03**: Each run writes a per-run subdirectory `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` capturing at minimum the PR URL (or a structured failure reason) and the final cost summary
- [ ] **TASK-04**: Existing `~/.copland/logs/runs.jsonl` JSONL log keeps working unchanged — additive only, no behavioral regression for existing log consumers
- [ ] **TASK-05**: Task-directory writer is exercised by Pest tests using a temporary `HOME` so no developer-machine state is touched

### Console Integration & Docs

- [ ] **CONS-01**: A real overnight-agent run produces a task directory that `TaskLoader.gd` renders without errors and without schema drift — task titles, statuses, and run metadata all show up in the console panes
- [ ] **CONS-02**: Root `README.md` documents the console: how to install Godot 4.2+, how to launch via `copland console`, and what each of the three panes (workflow states / task manifest / dossier) shows
- [ ] **CONS-03**: `console-godot/README.md` is updated to match what shipped (file paths, what counts as "real" data, any divergence from the original prototype design)

---

## Future Requirements (Deferred from `console-godot/TODO.md`)

These are explicitly out of scope for v2.0; they appear as deferred items in the recovered `console-godot/TODO.md` and should drive a future v2.1 milestone:

- Run drill-in selection — ↑/↓ to pick runs in the dossier, ENTER to open a deeper view
- Live-tail of an executing run — structured progress events (NDJSON or unix socket) streamed to the console
- UI scale on Retina / pixel-perfect rendering — only relevant if `stretch/mode` changes from `canvas_items`

Also deferred:

- INIT-01..07 from v1.2 — onboarding wizard, to be redesigned once the console shape is settled

## Out of Scope

- Editing or write actions from the Godot console — read-only is the ceiling for v2.0 and likely beyond
- Replacing or removing the existing `~/.copland/logs/runs.jsonl` — it stays as the canonical local audit trail
- Auto-launching the console after a run — the console is operator-driven, not auto-popping
- Bundling the Godot runtime with Copland — user installs Godot separately
- Windows console support — macOS/Linux only, matching the rest of Copland

---

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| GODOT-01 | Phase 19 | Complete |
| GODOT-02 | Phase 19 | Complete |
| GODOT-03 | Phase 19 | Complete |
| TASK-01 | Phase 20 | Pending |
| TASK-02 | Phase 20 | Pending |
| TASK-03 | Phase 21 | Pending |
| TASK-04 | Phase 21 | Pending |
| TASK-05 | Phase 21 | Pending |
| CONS-01 | Phase 22 | Pending |
| CONS-02 | Phase 22 | Pending |
| CONS-03 | Phase 22 | Pending |

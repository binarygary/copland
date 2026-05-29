# Requirements — v2.1 Godot Console — Configuration + Operational Surfaces

**Milestone:** v2.1 Godot Console — Configuration + Operational Surfaces
**Goal:** Turn the Godot console from a read-only viewer into a working operational surface — users configure Copland from the console, see live executor activity, and drill into specific runs without touching YAML.

---

## v2.1 Requirements

### Config UI

- [ ] **CFG-01**: `copland config show --json` emits a structured JSON snapshot of the merged global + per-repo configuration (repos[], asana_token redaction state, per-repo asana fields, per-repo `.copland.yml` overrides, defaults, stage models) that the Godot console can consume without parsing YAML.
- [ ] **CFG-02**: User can list, add, edit, and remove entries in `~/.copland.yml` `repos[]` (slug + path) from the Godot console.
- [ ] **CFG-03**: User can set the global `asana_token` and per-repo `asana_project` / `asana_filters` from the console.
- [ ] **CFG-04**: User can edit per-repo `.copland.yml` fields (`task_source`, `repo_summary`, `conventions`, llm stage overrides) from the console for any configured repo.
- [ ] **CFG-05**: User can edit global defaults (`max_files_changed`, `max_lines_changed`, `base_branch`) and stage models (selector / planner / executor) from the console.
- [ ] **CFG-06**: All write paths from the console invoke `copland config <subcommand>` flags; PHP owns YAML parsing, validation, and comment preservation; Godot never writes YAML directly.

### Run Drill-in

- [ ] **DRILL-01**: In the task drill-in view, ↑/↓ selects among run rows when runs exist for the task; selection state is visible.
- [ ] **DRILL-02**: ENTER on a selected run opens a per-run view; ESC (or equivalent) returns to the task drill-in.
- [ ] **DRILL-03**: The per-run view renders run id, status, prompts, and tool calls sourced from `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` artifacts.

### Live-Tail

- [ ] **LIVE-01**: The PHP CLI emits structured per-tool-call events as NDJSON to `~/.copland/tasks/<repo>/<task>/runs/<run-id>/events.log` during executor runs (one event per line; each event carries `ts`, `kind`, `tool`, and tool-specific fields).
- [ ] **LIVE-02**: When a task is in the `EXECUTING` state, the Godot console live-tails `events.log` and renders new tool calls as they appear (poll- or watch-based; latency target ≤ 2s).
- [ ] **LIVE-03**: Tailing terminates gracefully when the run transitions to a terminal state (`complete` / `blocked`) — no zombie watchers, no error popups, dossier reflects the final state.

---

## Future Requirements

Deferred to a later milestone (v2.2+):

- Config form validation feedback inline in the Godot UI (e.g. "path does not exist" before write) — v2.1 surfaces errors only through the CLI exit code / stderr round-trip.
- Bulk import / export of config across machines.
- Config diff / dry-run preview before write.
- Live-tail of selector and planner stages — v2.1 covers executor only because planner/selector are single-shot or short-lived.
- Replay of past runs from `events.log` in the run drill-in view (LIVE-01 lays groundwork but DRILL-03 reads static artifacts).

---

## Out of Scope

Explicit exclusions:

- Editing repo-level `.copland.yml` in repos Copland does not have a configured `path` for — every config target must be a repo already in `~/.copland.yml`.
- Web UI for config — Godot console remains the only graphical surface.
- Multi-user / team config — personal-tool scope unchanged from v1.x.
- Secret rotation / keychain integration for `asana_token` — token continues to live in `~/.copland.yml` (separate keychain work is a candidate for a later milestone).
- Editing runs/events.log post-hoc — append-only audit trail.

---

## Traceability

| REQ-ID   | Phase    | Status      |
|----------|----------|-------------|
| CFG-01   | Phase 23 | Not started |
| CFG-02   | Phase 24 | Not started |
| CFG-03   | Phase 26 | Not started |
| CFG-04   | Phase 25 | Not started |
| CFG-05   | Phase 26 | Not started |
| CFG-06   | Phase 24, 25, 26 (cross-cutting invariant) | Not started |
| DRILL-01 | Phase 27 | Not started |
| DRILL-02 | Phase 27 | Not started |
| DRILL-03 | Phase 27 | Not started |
| LIVE-01  | Phase 28 | Not started |
| LIVE-02  | Phase 29 | Not started |
| LIVE-03  | Phase 29 | Not started |

*Phase column filled by roadmapper.*

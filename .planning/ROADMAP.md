# Roadmap: Copland

## Milestones

- ✅ **v1.0 Overnight Hardening** — Phases 1-13 shipped 2026-04-03 ([archive](milestones/v1.0-ROADMAP.md))
- ✅ **v1.1 Multi-Provider & Asana Integration** — Phases 14-17 shipped 2026-04-09 ([archive](milestones/v1.1-ROADMAP.md))
- ✅ **v1.2 Onboarding** — Phase 18 shipped 2026-05-26 (Phase 19 dropped — superseded by v2.0) ([archive](milestones/v1.2-ROADMAP.md))
- ✅ **v2.0 Godot Console** — Phases 19-22 shipped 2026-05-27
- 🚧 **v2.1 Godot Console — Configuration + Operational Surfaces** — Phases 23-29 (planned)

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

<details>
<summary>✅ v2.0 Godot Console (Phases 19-22) — SHIPPED 2026-05-27</summary>

- [x] Phase 19: Prototype Recovery + Console Launcher — completed 2026-05-27
- [x] Phase 20: Task & Status Writer — completed 2026-05-27
- [x] Phase 21: Per-Run Artifacts & Test Coverage — completed 2026-05-27
- [x] Phase 22: End-to-End Smoke + Documentation — completed 2026-05-27

</details>

---

## v2.1 Godot Console — Configuration + Operational Surfaces

**Goal:** Turn the Godot console from a read-only viewer into a working operational surface — users configure Copland from the console, see live executor activity, and drill into specific runs without touching YAML.

### Phases

- [x] **Phase 23: Config Read Contract** — `copland config show --json` emits the merged global + per-repo configuration snapshot the Godot console reads from (CFG-01) (completed 2026-05-29)
- [ ] **Phase 24: Config Write — Global Repos List** — `copland config repos {add,edit,remove}` subcommands + Godot screen for managing `~/.copland.yml` `repos[]` entries (CFG-02, CFG-06)
- [ ] **Phase 25: Config Write — Per-Repo `.copland.yml`** — `copland config repo set` subcommand + Godot per-repo editor for `task_source`, `repo_summary`, `conventions`, and llm stage overrides (CFG-04, CFG-06)
- [ ] **Phase 26: Config Write — Asana Fields + Defaults & Models** — Two grouped config surfaces: global `asana_token` + per-repo Asana fields, and global defaults + stage models, each with CLI subcommand + Godot screen (CFG-03, CFG-05, CFG-06)
- [ ] **Phase 27: Run Drill-In Selection + Per-Run View** — Per-run view renders run id/status/prompts/tool calls, then ↑/↓ row selection, then ENTER/ESC navigation in the task drill-in (DRILL-01, DRILL-02, DRILL-03)
- [ ] **Phase 28: Live-Tail CLI Emitter** — PHP executor writes NDJSON `events.log` entries on every tool call so the console has a structured stream to tail (LIVE-01)
- [ ] **Phase 29: Live-Tail Console Consumer** — Godot tails `events.log` for `EXECUTING` tasks and shuts the watcher down cleanly on terminal state (LIVE-02, LIVE-03)

### Phase Details

#### Phase 23: Config Read Contract

**Goal**: Ship the read side of the hybrid config architecture — a single CLI subcommand that emits a stable JSON snapshot of the merged global + per-repo configuration, so the Godot console can render every config screen without ever parsing YAML.
**Depends on**: Nothing (first phase of v2.1)
**Requirements**: CFG-01
**Success Criteria** (what must be TRUE):

  1. Running `copland config show --json` from any directory prints a single JSON document to stdout containing: `repos[]` (slug, path), global `asana_token` redaction state (e.g. `"asana_token_set": true|false`, never the token value), per-repo `asana_project` / `asana_filters`, per-repo `.copland.yml` contents (`task_source`, `repo_summary`, `conventions`, llm stage overrides), and global defaults (`max_files_changed`, `max_lines_changed`, `base_branch`, selector/planner/executor models)
  2. The command exits 0 on success and emits no extra stdout chatter; non-zero exit with stderr message when `~/.copland.yml` is missing or malformed
  3. The JSON schema is documented (inline in the command's `--help` and/or a fixture file under `tests/`) so downstream Godot consumers can pin against it
  4. Pest tests assert the JSON shape against a fixture global config + at least one fixture repo, including the token-redaction guarantee

**Plans**: 1 plan

Plans:

- [x] 23-01-PLAN.md — Ship config:show {--json} command + ConfigShowService + canonical fixture, with full Pest unit/feature coverage of shape, redaction, and error paths

#### Phase 24: Config Write — Global Repos List

**Goal**: Users manage the global `~/.copland.yml` `repos[]` list (add, edit, remove repo slug + path entries) entirely from the Godot console, with PHP owning all YAML mutation via a new `copland config repos` subcommand family.
**Depends on**: Phase 23
**Requirements**: CFG-02, CFG-06
**Success Criteria** (what must be TRUE):

  1. `copland config repos add --slug <slug> --path <path>`, `... repos edit --slug <slug> [--path <path>]`, and `... repos remove --slug <slug>` mutate `~/.copland.yml` correctly, preserve YAML comments and ordering, and return non-zero with a clear stderr message on invalid input (duplicate slug, missing path, etc.)
  2. The Godot console gains a "Repos" config screen where the user can see the current list, add a new entry, edit an existing entry's path, or remove an entry; the screen invokes the matching `copland config repos ...` subcommand via `OS.execute` and never opens `~/.copland.yml` directly
  3. After saving in the console, the screen refreshes by re-calling `copland config show --json` and shows the persisted change without restarting the app
  4. A Pest test covers each subcommand against a tmp `HOME`, and a GDScript-side test or manual checklist confirms no `FileAccess` write call targets `~/.copland.yml`

**UI hint**: yes

**Plans**: TBD

#### Phase 25: Config Write — Per-Repo `.copland.yml`

**Goal**: Users edit a configured repo's `.copland.yml` (`task_source`, `repo_summary`, `conventions`, llm stage overrides) from the Godot console; PHP owns parsing, validation, schema knowledge, and comment-preserving writes via a `copland config repo set` subcommand.
**Depends on**: Phase 24
**Requirements**: CFG-04, CFG-06
**Success Criteria** (what must be TRUE):

  1. `copland config repo set --slug <slug> --field <key.path> --value <json>` (or equivalent shape) mutates the named repo's `.copland.yml`, validates allowed keys + value types (e.g. `task_source` ∈ {`github`,`asana`}), preserves comments, and exits non-zero with a clear error when the repo is not in `repos[]` or the field is unknown
  2. The Godot console gains a per-repo editor view reachable from the Repos screen that surfaces `task_source`, `repo_summary`, `conventions`, and the three llm stage override slots; edits flow exclusively through `copland config repo set`
  3. After saving, the editor re-reads `copland config show --json` and reflects the persisted value; reopening the same repo on a fresh console launch shows the same value
  4. Attempting to edit a repo whose `path` does not exist on disk surfaces the CLI's stderr message in the console (per CFG-06's CLI-as-source-of-truth rule), not a Godot-side YAML error
  5. Pest tests cover happy-path writes, unknown-field rejection, invalid-value rejection, and unconfigured-repo rejection against a tmp `HOME`

**UI hint**: yes

**Plans**: TBD

#### Phase 26: Config Write — Asana Fields + Defaults & Models

**Goal**: Cover the two remaining (smaller) config surfaces in one phase — global `asana_token` + per-repo `asana_project`/`asana_filters`, and global defaults (`max_files_changed`, `max_lines_changed`, `base_branch`) + stage models (selector/planner/executor). Each surface gets a CLI subcommand and a console screen.
**Depends on**: Phase 25
**Requirements**: CFG-03, CFG-05, CFG-06
**Success Criteria** (what must be TRUE):

  1. `copland config asana set-token --value <pat>`, `... config asana set --slug <slug> --project <gid>`, and `... config asana set --slug <slug> --filters <json>` (or an equivalent flag shape) write the Asana fields to the correct file (`~/.copland.yml` for the token, per-repo `.copland.yml` for project/filters) with comment preservation; reading back via `copland config show --json` shows `asana_token_set: true` (never the raw token) and the per-repo values
  2. `copland config defaults set --field <name> --value <v>` and `copland config models set --stage {selector|planner|executor} --model <name>` mutate `~/.copland.yml` defaults / stage models, validate input (numeric for the file/line caps, non-empty string for branch and models), and refuse unknown fields
  3. The Godot console gains an "Asana" screen and a "Defaults & Models" screen; from each, the user can edit a field, save, and reopen the console to see the persisted value — neither screen opens any YAML file directly
  4. Pest tests cover each subcommand (token write redaction in `show --json`, per-repo project/filter write, defaults validation, model stage write); a manual checklist confirms the Godot side only shells out to `copland config ...`

**UI hint**: yes

**Plans**: TBD

#### Phase 27: Run Drill-In Selection + Per-Run View

**Goal**: Replace the static per-run list in the task drill-in with an interactive selector — render real run id / status / prompts / tool calls from the v2.0 per-run artifacts, let ↑/↓ move selection, and let ENTER open the dedicated per-run view (ESC returns).
**Depends on**: Phase 22 (per-run artifacts exist; v2.1 only consumes them)
**Requirements**: DRILL-01, DRILL-02, DRILL-03
**Success Criteria** (what must be TRUE):

  1. With a task that has one or more `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` subdirectories, the per-run view renders the run id, current status, the planner/executor prompts (when materialized), and the tool-call history — all sourced from the on-disk artifacts, no synthetic data
  2. In the task drill-in, pressing ↑/↓ moves a visible selection highlight through the run rows; the highlight wraps or clamps cleanly at the list edges and stays in sync with the rendered row order
  3. Pressing ENTER on a selected run opens the per-run view for that exact run id (verified by the rendered run id matching the directory name); pressing ESC returns to the task drill-in with the prior selection preserved
  4. A task with zero runs shows an empty-state hint instead of an error, and ↑/↓/ENTER are inert in that state (no crash, no spurious navigation)

**UI hint**: yes

**Plans**: TBD

#### Phase 28: Live-Tail CLI Emitter

**Goal**: Settle the CLI side of the live-tail contract — the executor writes one NDJSON event per tool call to `~/.copland/tasks/<repo>/<task>/runs/<run-id>/events.log` during a run, so the Godot console (Phase 29) has something stable to tail.
**Depends on**: Phase 22 (per-run directory layout)
**Requirements**: LIVE-01
**Success Criteria** (what must be TRUE):

  1. During an executor run, `~/.copland/tasks/<repo>/<task>/runs/<run-id>/events.log` exists and contains one JSON object per line — each object carries at minimum `ts` (ISO 8601), `kind` (e.g. `tool_call`, `tool_result`), `tool` (tool name), and tool-specific fields (path for reads/writes, command for shell, etc.)
  2. The file is append-only during a run, flushed often enough that a tailing reader sees new lines within ≤ 2s of the underlying tool dispatch (no buffering that would defeat Phase 29's tail loop)
  3. The emitter's NDJSON schema is documented (fixture file or inline reference) so the Godot consumer can pin against it; format remains stable across selector/planner/executor stages even though only executor emits events in v2.1
  4. Existing JSONL run log (`~/.copland/logs/runs.jsonl`) and task-directory writes are unchanged; Pest coverage exercises the emitter against a tmp `HOME` with at least: happy path, multi-tool run, run that ends in `blocked`, and run that crashes mid-tool

**Plans**: TBD

#### Phase 29: Live-Tail Console Consumer

**Goal**: When a task is in the `EXECUTING` state, the Godot console tails its `events.log` and renders new tool calls as they appear; when the run transitions to a terminal state, the tail loop shuts down cleanly with no zombie watchers or error popups.
**Depends on**: Phase 28
**Requirements**: LIVE-02, LIVE-03
**Success Criteria** (what must be TRUE):

  1. Opening the dossier (or dedicated stream panel) for a task in `EXECUTING` state begins polling/watching `events.log`; new tool-call lines appear in the console within ≤ 2s of being written by the CLI
  2. When the task's `status.md` transitions to `complete` or `blocked`, the tail loop stops within one poll interval, the panel switches to a final-state view, and no Godot timer/thread keeps reading the file after that point
  3. Opening the console against a task that is already in a terminal state shows the final tool-call list without starting a tail loop at all
  4. A run that emits malformed NDJSON (truncated final line during flush) does not crash the console or surface an error popup — the bad line is skipped and tailing continues
  5. Manual smoke: a real overnight run started from the CLI is observed live in the console from `EXECUTING` through `complete` with no manual refresh

**UI hint**: yes

**Plans**: TBD

### Progress Table

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 23. Config Read Contract | 1/1 | Complete   | 2026-05-29 |
| 24. Config Write — Global Repos List | 0/0 | Not started | - |
| 25. Config Write — Per-Repo `.copland.yml` | 0/0 | Not started | - |
| 26. Config Write — Asana Fields + Defaults & Models | 0/0 | Not started | - |
| 27. Run Drill-In Selection + Per-Run View | 0/0 | Not started | - |
| 28. Live-Tail CLI Emitter | 0/0 | Not started | - |
| 29. Live-Tail Console Consumer | 0/0 | Not started | - |

# Phase 22: End-to-End Smoke + Documentation - Context

**Gathered:** 2026-05-27
**Status:** Ready for planning

<domain>
## Phase Boundary

The closing phase of the v2.0 Godot Console milestone. Two distinct deliverables that share one PR-shaped scope:

1. **End-to-end smoke (CONS-01):** A manual, operator-driven verification — stage an `agent-ready` issue on this repo (`binarygary/copland`), run `php ./copland run` from the project root, then launch `copland console` and visually confirm the resulting `~/.copland/tasks/<repo-slug-safe>/<task_id>/{task.md, status.md, runs/<run-id>/{status.md, outcome.md}}` tree renders without errors or schema drift. Acceptance is an explicit operator checklist captured in CONTEXT.md (D-02), not an automated test.

2. **Documentation (CONS-02, CONS-03):** Bring both READMEs in line with what actually shipped across Phases 19–21, plus document the canonical-purpose split between `~/.copland/tasks/` (live console state, markdown) and `~/.copland/logs/runs.jsonl` (append-only audit trail, JSONL). Root README gets a full inline console walkthrough; `console-godot/README.md` is rewritten to match shipped paths/behavior; `console-godot/TODO.md` gets a light staleness pass.

Out of scope: Any new console feature work (run drill-in selection, live-tail, file-watching — all remain v2.1 in `console-godot/TODO.md`). Any change to the writer surface (Phase 20/21 schema is locked). Any change to `~/.copland/logs/runs.jsonl` (Phase 21 D-15, REQUIREMENTS §"Out of Scope"). Automated parser-level coverage of the schema contract (explicitly chosen against in D-01 — would be a regression net, not a CONS-01 requirement).

</domain>

<decisions>
## Implementation Decisions

### Smoke verification approach (LOCKED)
- **D-01:** **Live run + operator sign-off only.** No new automated test for the parser-level contract. The CONS-01 success criterion is satisfied by a human-in-the-loop visual check: trigger a real `copland run`, open `copland console`, attest. Phase 21 D-19 already deferred orchestrator integration tests here; this phase confirms the deferral stands rather than reversing it. A future regression net (Pest test that re-implements TaskLoader's frontmatter parser constraints and asserts writer output against a fixture tree) would be its own phase if needed.
- **D-02:** **Explicit operator checklist in this CONTEXT.md** so the executor / verifier can quote it instead of re-deriving it. The checklist below is the acceptance contract for CONS-01:

  - [ ] `php ./copland run` against `binarygary/copland` completes without exception (PR opened or `blocked` payload written)
  - [ ] `~/.copland/tasks/binarygary__copland/<task_id>/task.md` exists with the 5 TaskLoader-required frontmatter keys (`id`, `title`, `repo_path`, `repo_slug`, `created_at`) plus `body`
  - [ ] `~/.copland/tasks/binarygary__copland/<task_id>/status.md` shows the terminal state (`pr_open` or `blocked`) in frontmatter, and the transitions table contains all states that fired during the run (at least 3 rows for a happy path)
  - [ ] `~/.copland/tasks/binarygary__copland/<task_id>/runs/<run-id>/status.md` exists with `state` + `updated_at` frontmatter, run-id matches the `2026-...T...-Z` POSIX-safe shape from Phase 21 D-01
  - [ ] `~/.copland/tasks/binarygary__copland/<task_id>/runs/<run-id>/outcome.md` exists with the 9 frontmatter keys from Phase 21 D-05
  - [ ] `copland console` launches without errors (Godot project opens, no parser exceptions in Godot's stderr / output panel)
  - [ ] Workflow States pane shows non-zero counts for at least one state
  - [ ] Task Manifest pane lists the task with its title (not a sample-fallback title like "Wire footer status bar…")
  - [ ] Dossier pane, when the task is selected, shows the task body markdown and at least one run row from `runs/`
  - [ ] No state badge in the manifest contains a value outside the 8 written states (`new, selected, planning, planned, executing, verifying, pr_open, blocked`)

### Smoke target (LOCKED)
- **D-03:** **Dogfood: Copland itself, manual trigger.** Stage one small `agent-ready` issue on `binarygary/copland`, run `php ./copland run` from the project root, sign off. Self-contained — no external repo coordination, no waiting on a cron tick, no throwaway-repo setup. The issue should be small enough to actually land a clean PR (so the smoke ends in `pr_open`, not `blocked`) — a `merged` state will never appear regardless (Phase 20 D-02 + D-17), so the success path ends at `pr_open` by design.
- **D-04:** **No snapshot.** After sign-off, the live `~/.copland/tasks/` tree stays on disk and nothing gets committed. No fixture dir under `console-godot/sample-data/`, no embedded sample-content blocks beyond what naturally illustrates a doc point. Repo stays clean. Matches D-01 — no automated test means no need for fixture inputs.

### Root README.md scope (LOCKED)
- **D-05:** **Full inline console walkthrough in root `README.md`.** New section (probably after "Commands" and before "Workflow") containing:
  - Prerequisite: Godot 4.2+ install
  - Launch: `php ./copland console` (preflight, macOS-only per Phase 19 D-04/D-05)
  - The existing prototype ASCII pane layout (copy from `console-godot/README.md`)
  - One sentence per pane: Workflow States (counts per state), Task Manifest (title + state badge), Dossier (drill-in detail + run history)
  - Keyboard table (copy from `console-godot/README.md`)
  - "Where data lives" subsection — the canonical-purpose split (D-09)
- **D-06:** **Incidental staleness fixes in root README** while editing:
  - Replace `copland setup` references with `copland automate` (v1.2 Phase 18 rename; current `README.md:142, :167` still say `setup`)
  - Fix the broken absolute path at `README.md:190` (`/Users/binarygary/projects/binarygary/copland/docs/overnight-setup.md`) — change to relative `docs/overnight-setup.md`
  - Light refresh of `README.md:158` (the "`status` is not implemented yet" line) — note that `copland console` is now the visual surface, so JSONL is no longer the only morning-review path
  - Scope guard: incidental fixes only. Do NOT take this opportunity to rewrite Asana / multi-provider sections, restructure command list, etc. If something other than the three bullets above looks stale, capture as a deferred idea.

### console-godot/ updates (LOCKED)
- **D-07:** **Remove `merged` from `console-godot/scripts/TaskLoader.gd:20-30` STATES list.** One-line GDScript change. Eliminates the Phase 20 D-02 divergence ("STATES contains `merged` but the writer never emits it") — a doc-only phase legitimately covers this because the alternative is documenting the divergence forever. After the edit, TaskLoader's vocabulary matches the writer's exactly: `[new, selected, planning, planned, executing, verifying, pr_open, blocked]`.
- **D-08:** **Light update to `console-godot/TODO.md`** — keep all three deferred items (run drill-in, live-tail, Retina note) but freshen wording. The current text "Currently the drill-in renders runs as a static list… your `runs/` directories are empty today" is now false (Phase 21 ships runs). Re-tag deferred items as v2.1 explicitly so it's clear they're not v2.0 gaps. ~10-15 line refactor; no items added or removed.

### console-godot/README.md rewrite (LOCKED)
- **D-08b:** `console-godot/README.md` is rewritten in place (not appended to) to match what actually shipped. Required content:
  - **Path contract:** `~/.copland/tasks/<repo-slug-safe>/<task_id>/{task.md, status.md, runs/<run-id>/{status.md, outcome.md}}` (the full schema, not just `task.md, status.md`).
  - **Slug normalization:** GitHub `owner/repo` → `owner__repo` (Phase 20 D-05); Asana → registered repo's basename (Phase 20 D-06).
  - **What counts as "real" data:** disk under `~/.copland/tasks/...`. The sample-fallback list in `TaskLoader.gd` is still relevant for visual iteration when no real runs exist — keep that callout.
  - **States vocabulary:** the 8 the writer emits (post-D-07 cleanup matches).
  - **Launch:** `copland console` from the CLI, not Godot's project manager (the prototype README's "From Godot's project manager: Import → ..." path still works but `copland console` is now the documented happy path).
  - **Divergences from the original prototype design that must be called out** (CONS-03 wording: "any divergence from the original prototype design"):
    - State `merged` was originally in STATES but never used by the writer — now removed in this phase.
    - `runs/<run-id>/outcome.md` is a NEW file (Phase 21) the original prototype didn't anticipate; document its frontmatter keys.
    - The "Real / Sample" data section's path is updated from `~/.copland/tasks/<repo>/<id>/{task.md, status.md}` to the full Phase-21 layout.
  - **Visual direction section** ("1930s machine-age orchestration console — Art Deco / Streamline Moderne") stays verbatim — it's owner-confirmed direction, not stale.

### Canonical-purpose split — tasks/ vs logs/runs.jsonl (LOCKED)
- **D-09:** Both surfaces are canonical, for **different** purposes. Document this explicitly in both READMEs (root in the "Where data lives" subsection, console-godot near the path contract):
  - `~/.copland/tasks/<repo>/<id>/...` — **live console state.** Human-readable markdown + YAML frontmatter. Mutates per lifecycle transition. The source of truth for what the Godot console renders. Ephemeral mid-run; terminal state pins.
  - `~/.copland/logs/runs.jsonl` — **append-only audit trail.** Machine-readable JSON, one record per `copland run` invocation. The source of truth for "what did the agent do last night", cost analytics, retrospective grepping. Never modified after append; not consumed by the console.
  - **Rule of thumb (for the reader):** "What's running right now?" → `tasks/`. "What happened over the last 30 nights?" → `runs.jsonl`. They overlap by design — the same run produces records in both — but the console is the wrong tool for retrospective queries, and grep on JSONL is the wrong tool for at-a-glance state.

### Plan decomposition (advisory — planner finalizes)
- **D-10:** Likely 2 plans, ordered so docs land *after* smoke evidence:
  - Plan 22-01 (Wave 1): The `merged` STATES.gd one-liner + the live smoke run + operator-checklist sign-off (CONS-01). Drives a real PR end-to-end against `binarygary/copland`. The plan's acceptance is the checklist in D-02 going green. No doc edits in this plan.
  - Plan 22-02 (Wave 2, depends on 22-01): Root README rewrite (D-05 + D-06 + D-09) + `console-godot/README.md` rewrite (D-08b + D-09) + `console-godot/TODO.md` light update (D-08). Docs reference paths/values that the Wave-1 smoke confirmed actually exist on disk.

  A planner could choose to combine into 1 plan if the STATES.gd edit and the doc edits are atomic enough, but the dependency arrow 22-01 → 22-02 is firm (docs should describe what was just observed, not what *should* be there).

### Claude's Discretion
- Exact placement of the new console section in root README (after "Commands" or after "Workflow") — both fine.
- Whether the operator checklist (D-02) is mirrored into the executor's PR body for paper trail, or stays only in CONTEXT.md.
- Wording of the "Where data lives" subsection — could use a small two-column table, a prose paragraph, or a fenced tree diagram.
- The exact agent-ready issue staged for the smoke — pick a small one (PHPStan/style tweak, doc typo, one-line refactor). Avoid anything that touches `console-godot/` or `app/Services/TaskDirectoryWriterService.php` to keep the smoke off-axis from the very code being tested.
- Whether to include a screenshot or stay text-only — text-only is the project default; if the user later adds one, that's its own follow-up.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 22: End-to-End Smoke + Documentation" — goal, 4 success criteria (the 4th lives only in roadmap prose: "relationship between `~/.copland/tasks/` and `~/.copland/logs/runs.jsonl` is documented")
- `.planning/REQUIREMENTS.md` §"Console Documentation" entries CONS-01, CONS-02, CONS-03 + §"Out of Scope" (especially: console is read-only, JSONL is canonical audit trail, no Windows)
- `.planning/PROJECT.md` §"Current Milestone: v2.0 Godot Console" — Phase 22 closes the milestone

### Schema contract (what the smoke must observe)
- `console-godot/scripts/TaskLoader.gd:20-30` — STATES array (the file that gets edited per D-07; pre-edit it still lists `merged`)
- `console-godot/scripts/TaskLoader.gd:218-256` — frontmatter parser (top-level scalars only, single quote strip). The writer output must stay inside these parser limits.
- `console-godot/scripts/TaskLoader.gd:277-299` — `load_runs()` — iterates `runs/<run-id>/` subdirs newest-first by mtime, reads each run's `status.md`

### Prior-phase locked contracts (do NOT re-litigate)
- `.planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md` — launch contract (macOS `open -a Godot`, `base_path()`, preflight); restored-verbatim README/TODO that this phase is now realigning (Phase 19 D-02)
- `.planning/phases/20-task-status-writer/20-CONTEXT.md` — schema contract (D-01), 8-state vocabulary (D-02), slug normalization (D-05/D-06), atomic-rename writes (D-09), `merged` is never written (D-02 + D-17)
- `.planning/phases/21-per-run-artifacts-test-coverage/21-CONTEXT.md` — run-id format (D-01: ISO timestamp colons→dashes), per-run dir layout (D-03), `outcome.md` 9-key frontmatter (D-05), JSONL untouched (D-15), orchestrator integration tests deferred to Phase 22 smoke (D-19)

### Docs to edit (and their current state)
- `README.md` — root README; gets a full new console section (D-05) + stale-bit fixes at lines 142, 167, 190, 158 (D-06)
- `console-godot/README.md` — rewritten in place to match shipped (D-08b)
- `console-godot/TODO.md` — light staleness pass (D-08)
- `console-godot/scripts/TaskLoader.gd` — one-line GDScript edit at the `STATES` constant (D-07)

### Existing docs the new console section will reference / mirror
- `docs/overnight-setup.md` — referenced from root README; verify exists at relative path
- `console-godot/README.md` (current restored-verbatim version) — its ASCII pane diagram and keyboard table are the source for the root-README inline walkthrough

### Codebase intel
- `.planning/codebase/STRUCTURE.md` — directory layout (already accurate)
- `.planning/codebase/CONVENTIONS.md` — doc-comment / code-style conventions (relevant for the one GDScript edit and the markdown-link conventions in the rewritten READMEs)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Existing ASCII pane layout** in `console-godot/README.md:14-24` — proven visual; copy verbatim into root README's new console section rather than re-drawing.
- **Existing keyboard table** in `console-godot/README.md:28-34` — same: copy verbatim into root README. Keeps the two READMEs in sync without dual maintenance — both point at the same content.
- **Existing "Visual direction" paragraph** in `console-godot/README.md:54-58` — owner-confirmed direction (1930s machine-age / Art Deco). Preserve verbatim during the console-godot README rewrite.
- **Sample-fallback callout** in `console-godot/README.md:38-43` (the `TaskLoader.gd` real-vs-sample split) — concept is still accurate; refresh the path string to match Phase 21 layout.

### Established Patterns
- **Operator-driven verification** — Phase 19 D-07 already established the pattern of "preflight + targeted error message + non-zero exit + operator runs it manually." Smoke run inherits this ethos: a real terminal invocation, a real Godot launch, a human eyeballs the result. No CI integration.
- **Strict additive-only at the data layer** — Phase 21 D-15 is the controlling precedent: do not modify `runs.jsonl`. Phase 22 inherits this — the docs must position JSONL as untouched-and-canonical, not deprecated.
- **Slug normalization rules** are public (Phase 20 D-05/D-06) — they appear in `task.md` frontmatter and the directory tree both. Worth surfacing in the console-godot README so an operator reading the file system understands why `binarygary/copland` becomes `binarygary__copland/`.

### Integration Points
- **No code surface changes** beyond the single-line `TaskLoader.gd` STATES edit (D-07). Every other deliverable is markdown.
- **Smoke run touches** `app/Services/RunOrchestratorService.php`, `TaskDirectoryWriterService`, `RunLogStore`, `ClaudeExecutorService`, etc. at runtime — but doesn't *modify* any of them. The smoke is an observation, not a refactor.
- **The agent-ready issue picked for the smoke** should NOT touch `console-godot/` or `app/Services/TaskDirectoryWriterService*` — that would conflate the test subject with the system under test.

</code_context>

<specifics>
## Specific Ideas

- The smoke must end in `pr_open` (success path), not `blocked` (failure path) for CONS-01 confidence — pick a small enough issue that the executor lands cleanly. If the first attempt blocks, retry with a different issue rather than papering over with a forced state.
- "Where data lives" is the deliverable for success criterion 4 (the only one that lives in roadmap prose, not in REQUIREMENTS.md as a CONS-XX line). Make it discoverable — both READMEs reference it.
- `console-godot/README.md`'s "Non-goals" section ("No editing, task creation, or workflow transitions… These belong in the CLI") is still accurate post-v2.0 — preserve it.
- The "1930s machine-age orchestration console" line is owner-tone, not boilerplate — Phase 19's CONTEXT.md flagged owner-confirmed decisions explicitly. Preserve verbatim.
- For the root README, place the console section AFTER `Commands` and BEFORE `Workflow` so the workflow narrative naturally references "the console" once the reader knows what it is.

</specifics>

<deferred>
## Deferred Ideas

- **Automated parser-level coverage of the schema contract** — a Pest test re-implementing `TaskLoader.gd`'s frontmatter parser constraints and asserting writer output against a fixture tree. Considered and explicitly chosen against (D-01). Would be a regression net useful in a maintenance milestone, not a v2.0 deliverable.
- **GDScript-side test harness** for `TaskLoader.gd` against a fixtures dir. Would stand up a Godot test framework from scratch; out of proportion for v2.0.
- **Snapshot of a sanitized real-run task tree** under `console-godot/sample-data/` as a fixture + doc artifact. Could replace the inline sample-fallback hardcoded in `TaskLoader.gd`. Considered, deferred (D-04).
- **Run drill-in selection in the Godot UI** — stays in `console-godot/TODO.md` as v2.1. Phase 22 does not ship it (already noted there pre-restore; not in the success criteria).
- **Live-tail of executing runs** — stays v2.1 per `console-godot/TODO.md`.
- **Retina UI scale note** — stays in `console-godot/TODO.md` (informational, not actionable in v2.0).
- **PR-merge polling to write `merged` state** — Phase 20 D-17 carried forward; would re-introduce the state we just removed from STATES.gd. Strictly v2.1+ territory.
- **Screenshot in the docs** — text-only is the project default; defer until a user actually needs visual reference.
- **Rewriting Asana / multi-provider sections of root README** — they're stale-ish (v1.1/v1.2 territory) but the user explicitly scope-guarded incidental fixes to the three stated bullets (D-06). If those sections need a refresh, that's a separate doc-pass phase.
- **Console write actions from Godot** — REQUIREMENTS §"Out of Scope"; read-only is the ceiling for v2.0 and beyond.

</deferred>

---

*Phase: 22-end-to-end-smoke-documentation*
*Context gathered: 2026-05-27*

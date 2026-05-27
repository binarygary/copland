# Phase 22: End-to-End Smoke + Documentation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in 22-CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-27
**Phase:** 22-end-to-end-smoke-documentation
**Areas discussed:** Smoke verification approach, Smoke target & artifact capture, Root README console section, console-godot/ rewrite + STATES drift

---

## Smoke Verification Approach

| Option | Description | Selected |
|--------|-------------|----------|
| Live run + operator sign-off only | Trigger a real `copland run`, open the console, eyeball it. No new automated test. | ✓ |
| Live run + Pest fixture test | Same plus a Pest test that re-implements TaskLoader.gd's frontmatter parser constraints and asserts writer output against a fixture tree. | |
| Live run + GDScript test in console-godot/ | Highest fidelity; would stand up a Godot test framework from scratch. | |

**User's choice:** Live run + operator sign-off only
**Notes:** Phase 21 D-19 already deferred orchestrator integration tests; this choice keeps the deferral consistent. A regression net is its own future phase.

### Sub-question: Operator checklist

| Option | Description | Selected |
|--------|-------------|----------|
| Explicit checklist in CONTEXT/plan | Pin a short, concrete list of what to attest. | ✓ |
| Leave it loose | Trust the operator to recognize problems. | |

**User's choice:** Explicit checklist in CONTEXT/plan
**Notes:** Captured as D-02 — a 10-item checklist covering writer output, parser-safe schema, pane rendering, and state vocabulary.

---

## Smoke Target & Artifact Capture

### Sub-question: Target repo

| Option | Description | Selected |
|--------|-------------|----------|
| Copland itself — manual `copland run` | Dogfood: stage an agent-ready issue on binarygary/copland, run manually. | ✓ |
| Whatever cron next fires | Wait for the nightly LaunchAgent to fire naturally. | |
| A sandbox/scratch repo with a synthetic issue | Spin up a throwaway repo. | |

**User's choice:** Copland itself — manual `copland run`
**Notes:** Issue selected should NOT touch `console-godot/` or `app/Services/TaskDirectoryWriterService*` to keep the smoke off-axis from the system under test.

### Sub-question: Snapshot

| Option | Description | Selected |
|--------|-------------|----------|
| No snapshot — verify and discard | Leave live tree on disk; commit nothing. | ✓ |
| Snapshot a sanitized sample tree under console-godot/sample-data/ | Capture one task dir + one run subdir into the repo. | |
| Embed a sample task.md/status.md block as fenced code in the docs | Inline sanitized example file contents in the console-godot README. | |

**User's choice:** No snapshot — verify and discard
**Notes:** Matches D-01 — no automated test → no fixture needed.

---

## Root README Console Section — Depth & Placement

### Sub-question: Depth

| Option | Description | Selected |
|--------|-------------|----------|
| Full inline walkthrough | New section: install Godot, launch, ASCII layout, three-pane explanation, keyboard, "Where data lives". ~40-60 lines. | ✓ |
| Short pointer to console-godot/README.md | One paragraph that links out. | |
| Middle ground — quick paragraph + ASCII layout + link | ~15-20 lines. | |

**User's choice:** Full inline walkthrough
**Notes:** Root README becomes the single discoverable entry point. Placement: after Commands, before Workflow.

### Sub-question: Incidental cleanup

| Option | Description | Selected |
|--------|-------------|----------|
| Fix the obvious stale bits too | `copland setup` → `copland automate`, fix broken absolute doc path, refresh `status` line. | ✓ |
| Strict scope — only add the console section | Leave stale references alone. | |
| Fix only the renames, leave the path / status note | Compromise. | |

**User's choice:** Fix the obvious stale bits too
**Notes:** Scope-guarded to the three stated bullets only. Asana / multi-provider sections stay out.

---

## console-godot/README Rewrite + STATES Drift

### Sub-question: TaskLoader.gd STATES drift

| Option | Description | Selected |
|--------|-------------|----------|
| Remove `merged` from TaskLoader.gd's STATES list | One-line GDScript change; eliminates the Phase 20 D-02 divergence. | ✓ |
| Leave STATES alone, document the divergence in README | Keep STATES permissive, add a doc note. | |
| Leave STATES alone, no doc note either | Tolerate the noise. | |

**User's choice:** Remove `merged` from TaskLoader.gd's STATES list
**Notes:** A doc-only phase legitimately covers this — the alternative is documenting the divergence forever.

### Sub-question: console-godot/TODO.md

| Option | Description | Selected |
|--------|-------------|----------|
| Light update — keep deferred items, refine wording | Freshen stale phrasing; re-tag as v2.1 explicitly. ~10-15 line refactor. | ✓ |
| Leave TODO.md untouched | Strict reading of CONS-03 (only names README.md). | |
| Rewrite TODO.md fully to reflect post-Phase-21 reality | Re-audit which items remain deferred. | |

**User's choice:** Light update
**Notes:** "Currently the drill-in renders runs as a static list… your `runs/` directories are empty today" is now false post-Phase-21 — that and similar staleness gets fixed; no items added or removed.

---

## Claude's Discretion

- Exact placement of the new console section in root README (after Commands or after Workflow).
- Whether the operator checklist is mirrored into the smoke PR body.
- Wording of the "Where data lives" subsection (prose / table / tree diagram).
- Exact agent-ready issue staged for the smoke (small, not touching console-godot/ or writer code).
- Whether to include a screenshot (defaulted to no per project convention).

## Deferred Ideas

- Automated Pest fixture test against TaskLoader parser constraints (regression net for future phase).
- GDScript-side test harness.
- Sanitized real-run task tree fixture under `console-godot/sample-data/`.
- Run drill-in selection in Godot UI (stays v2.1).
- Live-tail of executing runs (stays v2.1).
- Retina UI scale note (stays informational in TODO).
- PR-merge polling to write `merged` state (would reintroduce what we just removed; v2.1+).
- Screenshot in docs.
- Rewriting Asana / multi-provider sections of root README (scope-guarded out).

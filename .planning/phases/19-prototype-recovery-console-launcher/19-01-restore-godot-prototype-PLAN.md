---
phase: 19-prototype-recovery-console-launcher
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - console-godot/project.godot
  - console-godot/icon.svg
  - console-godot/README.md
  - console-godot/TODO.md
  - console-godot/scenes/Main.tscn
  - console-godot/scripts/Main.gd
  - console-godot/scripts/TaskLoader.gd
autonomous: false
requirements:
  - GODOT-01
  - GODOT-02
validation_strategy: not_applicable

must_haves:
  truths:
    - "console-godot/project.godot exists on main"
    - "console-godot/scenes/Main.tscn exists on main"
    - "console-godot/scripts/Main.gd exists on main"
    - "console-godot/scripts/TaskLoader.gd exists on main"
    - "console-godot/icon.svg exists on main"
    - "console-godot/README.md exists on main (verbatim from backup branch — D-02 forbids edits)"
    - "console-godot/TODO.md exists on main (verbatim from backup branch — D-02 forbids edits)"
    - "console-godot/assets/{fonts,textures,themes}/ subtrees are unchanged from their pre-restore state on main"
    - "Opening console-godot/project.godot in Godot 4.2+ and pressing F5 launches the Copland Console without errors (empty-state acceptable since ~/.copland/tasks/ may be empty)"
  artifacts:
    - path: "console-godot/project.godot"
      provides: "Godot 4.2 project config (viewport, input bindings)"
      contains: "config_version=5"
    - path: "console-godot/scenes/Main.tscn"
      provides: "Main UI scene for the Copland Console"
    - path: "console-godot/scripts/Main.gd"
      provides: "UI controller that reads HOME directly (no CLI args needed)"
    - path: "console-godot/scripts/TaskLoader.gd"
      provides: "Loads tasks from ~/.copland/tasks/ via OS.get_environment('HOME')"
    - path: "console-godot/icon.svg"
      provides: "Project icon for Godot editor"
    - path: "console-godot/README.md"
      provides: "Prototype run instructions (Godot 4.2+, F5 to run) — verbatim from backup"
    - path: "console-godot/TODO.md"
      provides: "Deferred items (v2.1) — verbatim from backup"
  key_links:
    - from: "console-godot/scripts/Main.gd"
      to: "console-godot/scripts/TaskLoader.gd"
      via: "preload/load (Godot scene wiring)"
      pattern: "TaskLoader"
    - from: "console-godot/scripts/TaskLoader.gd"
      to: "~/.copland/tasks/"
      via: "OS.get_environment(\"HOME\")"
      pattern: "OS\\.get_environment.*HOME"
---

<objective>
Restore the Godot 4.2+ prototype onto `main` from `backup/local-main-diverged-20260526` as a single checkout commit (per D-01). Restores exactly seven files (per D-03) while leaving the pre-existing `console-godot/assets/{fonts,textures,themes}/` subtrees untouched. Closes GODOT-01; sets up GODOT-02 verification (Godot F5 launch) as a manual checkpoint at the end of this plan.

Purpose: The Godot prototype currently exists only on `backup/local-main-diverged-20260526`. Phase 19 cannot proceed (and the `copland console` command in plan 19-02 has nothing to launch) without these files on `main`. The restore is intentionally framed as a static-asset adoption — not as a series of evolving changes — so a single commit is correct.

Output: Seven new tracked files under `console-godot/` on `main`, plus a single commit recording the restore. No PHP changes, no test changes, no doc rewrites (D-02 reserves doc alignment for Phase 22 / CONS-02/CONS-03).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/STATE.md
@.planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md
@.planning/phases/19-prototype-recovery-console-launcher/19-PATTERNS.md

<!-- No source files to inject as <interfaces>: the seven restored files are static assets/GDScript, not PHP code. The PHP side of the phase is owned by 19-02. -->
</context>

<tasks>

<task type="auto">
  <name>Task 1: Confirm pre-restore state of console-godot/</name>
  <read_first>
    - .planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md (D-01, D-02, D-03)
    - .planning/phases/19-prototype-recovery-console-launcher/19-PATTERNS.md ("Static restore note for planner" and "No Analog Found" sections)
  </read_first>
  <files>(read-only verification — no files modified in this task)</files>
  <action>
    Capture the pre-restore baseline so the restore commit is provably clean. Run, in order:

    1. `git status --porcelain` — working tree MUST be clean (no uncommitted changes). If not clean, stop and report — do NOT attempt the restore on a dirty tree.
    2. `git branch --contains backup/local-main-diverged-20260526 | head -5` and `git rev-parse backup/local-main-diverged-20260526` — confirm the backup branch is reachable locally.
    3. `git ls-tree -r --name-only HEAD -- console-godot/` — record the current `console-godot/` file list on `main`. The pre-restore tree MUST contain only the `console-godot/assets/{fonts,textures,themes}/` subtrees (no `project.godot`, `scenes/`, `scripts/`, etc.). Save the file list to scratch memory for the post-restore diff in Task 3.
    4. `git ls-tree -r --name-only backup/local-main-diverged-20260526 -- console-godot/` — record the backup-branch file list. The seven files from D-03 (`project.godot`, `icon.svg`, `README.md`, `TODO.md`, `scenes/Main.tscn`, `scripts/Main.gd`, `scripts/TaskLoader.gd`) MUST appear; the `assets/` subtree MUST also appear and MUST match `main`'s asset tree (see D-03 — "intentionally NOT re-restored").
    5. `git diff main..backup/local-main-diverged-20260526 -- console-godot/assets/ | head -5` — output MUST be empty. If non-empty, stop and report — D-03's assumption ("assets already match") is violated and the planner needs to know before proceeding.
  </action>
  <verify>
    <automated>
      git status --porcelain | wc -l | grep -v '^#' | tr -d ' ' | grep -qx '0' && \
      git rev-parse --verify backup/local-main-diverged-20260526 >/dev/null && \
      [ -z "$(git diff main..backup/local-main-diverged-20260526 -- console-godot/assets/)" ]
    </automated>
  </verify>
  <acceptance_criteria>
    - `git status --porcelain` returns zero lines (clean working tree).
    - `git rev-parse backup/local-main-diverged-20260526` resolves to a commit SHA (backup branch exists locally).
    - `git diff main..backup/local-main-diverged-20260526 -- console-godot/assets/` produces no output (D-03 assumption holds — assets identical between branches).
    - The seven D-03 files are listed in `git ls-tree -r --name-only backup/local-main-diverged-20260526 -- console-godot/`.
    - None of the seven D-03 files appear in `git ls-tree -r --name-only HEAD -- console-godot/` (they really are missing on `main`).
  </acceptance_criteria>
  <done>Pre-restore baseline confirmed: clean tree, backup branch reachable, assets identical, seven target files absent on main and present on backup.</done>
</task>

<task type="auto">
  <name>Task 2: Restore the seven files in a single checkout commit</name>
  <read_first>
    - .planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md (D-01 — single-commit framing; D-02 — verbatim, no edits; D-03 — exact file list)
    - .planning/phases/19-prototype-recovery-console-launcher/19-PATTERNS.md ("Planner action for all 7" — single git checkout invocation)
  </read_first>
  <files>
    console-godot/project.godot,
    console-godot/icon.svg,
    console-godot/README.md,
    console-godot/TODO.md,
    console-godot/scenes/Main.tscn,
    console-godot/scripts/Main.gd,
    console-godot/scripts/TaskLoader.gd
  </files>
  <action>
    Restore the prototype as a single atomic operation, per D-01 and the PATTERNS.md "single git checkout invocation" guidance.

    1. Run `git checkout backup/local-main-diverged-20260526 -- console-godot/` (note: the path is the directory, not a glob — git will materialize every tracked path under that directory from the backup branch's tree into the index and working tree; `console-godot/assets/*` already match per Task 1, so no asset content changes).
    2. Run `git status --porcelain` — the only entries MUST be `A` (added) lines for the seven D-03 files. If any other path is staged (e.g., an asset file showing modified), stop — D-03's assumption broke and Task 1's check missed it.
    3. Do NOT edit any of the restored files. D-02 explicitly forbids edits in this phase; README.md and TODO.md doc alignment is owned by Phase 22 (CONS-02/CONS-03).
    4. Stage exactly the seven files: `git add console-godot/project.godot console-godot/icon.svg console-godot/README.md console-godot/TODO.md console-godot/scenes/Main.tscn console-godot/scripts/Main.gd console-godot/scripts/TaskLoader.gd`. Do NOT use `git add -A` or `git add console-godot/` — explicit listing prevents accidentally adding anything Task 1 didn't account for.
    5. Commit with message: `restore Godot prototype from backup branch (Phase 19, GODOT-01)`. Include a second-line body referencing the backup branch SHA from Task 1 step 2 and noting "single-commit restore per D-01; assets/ untouched per D-03; README/TODO verbatim per D-02".
    6. Capture commit SHA via `git rev-parse HEAD` for the SUMMARY.

    Do NOT cherry-pick from the backup branch. Do NOT merge. Do NOT amend any prior commit. The restore is one new commit on `main`.
  </action>
  <verify>
    <automated>
      test -f console-godot/project.godot && \
      test -f console-godot/icon.svg && \
      test -f console-godot/README.md && \
      test -f console-godot/TODO.md && \
      test -f console-godot/scenes/Main.tscn && \
      test -f console-godot/scripts/Main.gd && \
      test -f console-godot/scripts/TaskLoader.gd && \
      grep -q 'config_version=5' console-godot/project.godot && \
      git log -1 --pretty=%s | grep -q 'restore Godot prototype' && \
      [ "$(git diff HEAD~1 HEAD --name-only | grep -v '^console-godot/' | wc -l | tr -d ' ')" = "0" ] && \
      [ "$(git diff HEAD~1 HEAD --name-only | wc -l | tr -d ' ')" = "7" ]
    </automated>
  </verify>
  <acceptance_criteria>
    - All seven files from D-03 exist on disk at the listed paths.
    - `console-godot/project.godot` contains the line `config_version=5` (Godot 4.x project marker — proves restore took, not a placeholder).
    - `console-godot/README.md` and `console-godot/TODO.md` contents byte-match the backup-branch versions: `git diff backup/local-main-diverged-20260526 HEAD -- console-godot/README.md console-godot/TODO.md` produces no output.
    - The new commit changes exactly seven files, all under `console-godot/`, and zero files under `console-godot/assets/` (verified by `git diff HEAD~1 HEAD --name-only`).
    - Commit message subject line starts with `restore Godot prototype`.
    - `git status --porcelain` is empty after commit (no leftover staged/unstaged changes).
  </acceptance_criteria>
  <done>Seven files restored in a single commit on main; assets untouched; README/TODO verbatim from backup; tree clean.</done>
</task>

<task type="auto">
  <name>Task 3: Post-restore tree audit (assets unchanged, file count exact)</name>
  <read_first>
    - .planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md (D-03 — exact file list and assets-preservation guarantee)
  </read_first>
  <files>(read-only verification — no files modified in this task)</files>
  <action>
    Cross-check the post-restore tree against the D-03 contract before handing off to the manual Godot F5 verification. Compare `console-godot/` on `HEAD` against `console-godot/` on `backup/local-main-diverged-20260526`:

    1. `git diff backup/local-main-diverged-20260526 HEAD -- console-godot/` — output MUST be empty. If non-empty, the restored content does not match the backup verbatim; investigate before the manual checkpoint.
    2. Confirm asset subtrees are present and unchanged versus the pre-restore baseline captured in Task 1: `ls console-godot/assets/fonts console-godot/assets/textures console-godot/assets/themes` MUST all succeed (assets dirs still exist), and no file under `console-godot/assets/` appears in `git log -1 --name-only` (the restore commit touched zero asset files).
    3. Confirm the seven restored files appear in `git log -1 --name-only` exactly once each.
  </action>
  <verify>
    <automated>
      [ -z "$(git diff backup/local-main-diverged-20260526 HEAD -- console-godot/)" ] && \
      ls console-godot/assets/fonts >/dev/null && \
      ls console-godot/assets/textures >/dev/null && \
      ls console-godot/assets/themes >/dev/null && \
      [ "$(git log -1 --name-only --pretty=format: | grep -c '^console-godot/assets/')" = "0" ] && \
      [ "$(git log -1 --name-only --pretty=format: | grep -v '^$' | sort -u | wc -l | tr -d ' ')" = "7" ]
    </automated>
  </verify>
  <acceptance_criteria>
    - `git diff backup/local-main-diverged-20260526 HEAD -- console-godot/` produces no output (restored tree matches backup exactly under `console-godot/`).
    - All three `console-godot/assets/{fonts,textures,themes}/` subdirectories still exist and are readable.
    - The restore commit (`HEAD`) touches zero files under `console-godot/assets/`.
    - The restore commit touches exactly seven files in total (the D-03 list).
  </acceptance_criteria>
  <done>Restored tree byte-identical to backup branch under console-godot/; assets preserved unchanged; commit scope exactly the D-03 seven.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 4: MANUAL — Open Godot 4.2+ and press F5 to verify Copland Console launches</name>
  <what-built>The seven Godot prototype files are restored to `console-godot/` on `main` as a single commit. The Godot project should now open in Godot 4.2+ and run via F5 without errors.</what-built>
  <how-to-verify>
    This step requires a human at the machine because the Godot editor is GUI-only — there is no headless equivalent for F5 in this prototype.

    1. Open Godot 4.2 or later on this machine (Godot.app from /Applications, or via Spotlight).
    2. In Godot's Project Manager, click `Import` and select `console-godot/project.godot` from the project root (full path: the value of `pwd`/`console-godot/project.godot`). Open the project.
    3. Once the editor loads, press F5 to run the main scene.
    4. Observe the running window:
       - Expected: the Copland Console window appears with the three panes (workflow states / task manifest / dossier). Empty-state rendering is acceptable since `~/.copland/tasks/` may not exist yet — empty panes or "no tasks" placeholders are fine.
       - Failure modes that BLOCK approval: Godot's bottom panel shows red errors, the editor refuses to load the project (missing/corrupt files), or F5 crashes Godot. Any of these means the restore is broken or Godot's version is wrong.
    5. Close Godot. There is nothing to clean up — read-only console, no files written.

    Per GODOT-02 in REQUIREMENTS.md, success criterion #2 in ROADMAP.md Phase 19, and must_haves.truths #9 in this plan's frontmatter: empty-state rendering counts as success. Only red-error console output or load failure should be reported as a problem.
  </how-to-verify>
  <resume-signal>Type "approved" if F5 launched without errors (empty-state is fine). If errors appeared, describe what Godot's output panel showed and which file references failed.</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| local git → working tree | Restoring file content from a stale branch (`backup/local-main-diverged-20260526`) into the active checkout. Source code is local, but the branch has not been integrity-reviewed since the divergence event (2026-05-26). |
| Godot.app → local filesystem | Once F5 runs the restored scripts, the Godot runtime calls `OS.get_environment("HOME")` and reads `~/.copland/tasks/` (GDScript in `scripts/TaskLoader.gd` and `scripts/Main.gd`). |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-19-01 | Tampering | `git checkout backup/local-main-diverged-20260526 -- console-godot/` | accept | The backup branch is a local-only ref created by the project owner on 2026-05-26; there is no remote pollution vector. Task 1 enforces a clean working tree before checkout; Task 3 audits the restored content matches the backup tree exactly. |
| T-19-02 | Information Disclosure | restored `console-godot/scripts/*.gd` reading `~/.copland/tasks/` | accept | Read-only access to data the user owns; no exfiltration vector (no network code in the prototype scripts per CONTEXT.md `code_context`). Re-evaluate in Phase 22 if the scripts gain network calls (none planned). |
| T-19-03 | Tampering | `console-godot/README.md` / `TODO.md` verbatim restore | mitigate | D-02 forbids edits in this phase. Task 2 stages only the D-03 seven files explicitly (no wildcards); Task 3 asserts `git diff backup..HEAD -- console-godot/README.md TODO.md` is empty. Any unauthorized edit would fail the diff assertion. |

(No supply-chain threat — this plan installs zero packages. The `T-{phase}-SC` row is intentionally omitted.)
</threat_model>

<verification>
Phase-level checks for this plan:

1. **File presence:** Seven D-03 files exist at the listed paths on disk.
2. **Verbatim match:** `git diff backup/local-main-diverged-20260526 HEAD -- console-godot/` is empty (no content drift).
3. **Asset preservation:** `console-godot/assets/{fonts,textures,themes}/` are still on disk and the restore commit touched zero asset files.
4. **Single-commit framing:** Exactly one new commit on `main` (per D-01); its subject starts with `restore Godot prototype`.
5. **Manual launch:** Human-verified in Task 4 that Godot 4.2+ opens the project and F5 runs without errors (empty-state acceptable).
</verification>

<success_criteria>
- GODOT-01 satisfied: `console-godot/` on `main` contains the seven D-03 files plus the pre-existing `assets/{fonts,textures,themes}/` subtrees.
- GODOT-02 satisfied: Opening `console-godot/project.godot` in Godot 4.2+ and pressing F5 launches the Copland Console without errors (human-confirmed in Task 4).
- D-01 honored: a single commit on `main` records the restore.
- D-02 honored: `README.md` and `TODO.md` byte-match the backup branch.
- D-03 honored: exactly the seven listed files restored; `assets/` untouched.
</success_criteria>

<output>
Create `.planning/phases/19-prototype-recovery-console-launcher/19-01-SUMMARY.md` when done, including:
- Restore commit SHA
- Output of `git diff backup/local-main-diverged-20260526 HEAD -- console-godot/` (should be empty — note this explicitly)
- Confirmation that Task 4's manual F5 check passed (or detailed failure notes if it did not)
- Any deviations from D-01/D-02/D-03 (expected: none)
</output>

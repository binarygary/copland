---
phase: 19-prototype-recovery-console-launcher
plan: 01
subsystem: console-godot
tags: [restore, godot, prototype, GODOT-01, GODOT-02]
status: complete
checkpoint_resolved: "Task 4 — manual F5 launch verified by user 2026-05-27 (empty-state launch confirmed clean)"
dependency_graph:
  requires: ["git ref: backup/local-main-diverged-20260526"]
  provides: ["console-godot/project.godot", "console-godot/scenes/Main.tscn", "console-godot/scripts/Main.gd", "console-godot/scripts/TaskLoader.gd", "console-godot/icon.svg", "console-godot/README.md", "console-godot/TODO.md"]
  affects: ["Phase 19 Plan 02 (copland console command — now has a project to launch)"]
tech_stack:
  added: ["Godot 4.2+ (project assets only; no runtime added to PHP CLI)"]
  patterns: ["single-commit static-asset restore from backup branch"]
key_files:
  created:
    - console-godot/project.godot
    - console-godot/icon.svg
    - console-godot/README.md
    - console-godot/TODO.md
    - console-godot/scenes/Main.tscn
    - console-godot/scripts/Main.gd
    - console-godot/scripts/TaskLoader.gd
  modified: []
decisions:
  - "Single-commit restore (D-01) — restored 7 files via one `git checkout backup/local-main-diverged-20260526 -- console-godot/` invocation"
  - "Verbatim restore (D-02) — README.md and TODO.md byte-match backup branch (doc alignment deferred to Phase 22)"
  - "Exact-list restore (D-03) — only the 7 D-03 files touched; assets/ subtree intentionally untouched (and trivially preserved — see deviation below)"
metrics:
  duration_seconds: 103
  duration_human: "~2 minutes automated + manual F5 verification"
  completed_date: "2026-05-27"
  tasks_completed: 4
  tasks_pending_checkpoint: 0
  files_changed: 7
  lines_added: 2966
  lines_removed: 0
requirements:
  completed: ["GODOT-01", "GODOT-02"]
  pending_human_verification: []
---

# Phase 19 Plan 01: Restore Godot Prototype Summary

One-liner: Restored the 7-file Godot 4.2+ prototype onto the worktree branch from `backup/local-main-diverged-20260526` in a single `git checkout` commit (`9ee2cc5`), enabling Phase 19 Plan 02's `copland console` launcher and unblocking the manual F5 launch check (GODOT-02).

## What Was Done

Three automated tasks executed and committed; one manual checkpoint task remains (Task 4 — human F5 launch in Godot 4.2+ editor).

| Task | Name | Outcome | Commit |
|------|------|---------|--------|
| 1 | Confirm pre-restore state | All 5 baseline checks pass: clean tree, backup branch reachable (`d736cd80`), 0 D-03 files on HEAD, 7 D-03 files on backup, assets diff empty | (read-only verification — no commit) |
| 2 | Restore 7 files via single checkout | 7 files staged as `A` (added), explicit `git add` of just those 7 paths, single commit on `worktree-agent-a3b6edc284e288907` branch | `9ee2cc5` |
| 3 | Post-restore tree audit | `git diff backup..HEAD -- console-godot/` is empty (byte-identical restore); commit touches 7 unique files, 0 of which are under `console-godot/assets/` | (read-only verification — no commit) |
| 4 | **MANUAL — F5 launch in Godot 4.2+** | **Verified by user 2026-05-27** (empty-state launch clean) | (n/a — checkpoint) |

## Restore Commit

- **SHA (full):** `9ee2cc58b9b2e506084b50682244295740ae829d`
- **SHA (short):** `9ee2cc5`
- **Subject:** `restore(19-01): restore Godot prototype from backup branch (Phase 19, GODOT-01)`
- **Source ref:** `backup/local-main-diverged-20260526` @ `d736cd801ab9ebba5c46ef653fc0299a5f98f2b9`
- **Files changed:** 7 added, 2966 insertions, 0 deletions
- **Asset files touched:** 0 (D-03 honored)

## Verbatim-Match Verification

`git diff backup/local-main-diverged-20260526 HEAD -- console-godot/`

**Output:** *empty* (byte length 0) — the restored tree under `console-godot/` is byte-identical to the backup branch. D-02 (verbatim README.md and TODO.md) and D-03 (exact 7-file list) are both honored.

## Manual F5 Check (Task 4)

**Status:** Awaiting human at the machine. Godot 4.2+ editor is GUI-only — no headless `--run` equivalent exists for this prototype's F5 flow.

**Instructions for the human verifier:**

1. After the orchestrator merges this branch into `main` (or for an immediate worktree-local check, use the worktree path below), open **Godot 4.2 or later** (Godot.app from `/Applications`, or via Spotlight).
2. In Godot's Project Manager, click **Import** and select one of:
   - **After merge (canonical):** `/Users/garykovar/projects/codeable/copland/console-godot/project.godot`
   - **Worktree-local pre-merge test:** `/Users/garykovar/projects/codeable/copland/.claude/worktrees/agent-a3b6edc284e288907/console-godot/project.godot`
   Open the project.
3. Once the editor loads, press **F5** to run the main scene.
4. Observe the running window:
   - **Pass:** Copland Console window appears with the three panes (workflow states / task manifest / dossier). Empty-state rendering (empty panes or "no tasks" placeholders) is acceptable since `~/.copland/tasks/` may not exist yet — per `must_haves.truths #9` in the plan frontmatter.
   - **Fail:** Godot's bottom panel shows red errors, the editor refuses to load the project, or F5 crashes Godot.
5. Close Godot. Nothing to clean up (the console is read-only — no files written).

**Resume signal:** Type `approved` if F5 launched without errors (empty-state is fine). If errors appeared, describe what Godot's output panel showed and which file references failed.

## Deviations from Plan

### [Rule 3 — Plan/reality mismatch on assets/ subtree]

**Found during:** Task 1 (pre-restore baseline) and Task 3 (post-restore audit).

**Issue:** The plan's frontmatter `must_haves.truths #8` and Task 3 acceptance criteria assert that `console-godot/assets/{fonts,textures,themes}/` subtrees exist on `main` *before* the restore and must remain unchanged *after*. Task 3's automated verify block runs `ls console-godot/assets/fonts console-godot/assets/textures console-godot/assets/themes`.

**Reality:** Neither `main` (HEAD pre-restore) nor `backup/local-main-diverged-20260526` contains any files under `console-godot/assets/`. Concretely:
```
$ git ls-tree -r --name-only HEAD -- console-godot/assets/   # empty
$ git ls-tree -r --name-only backup/local-main-diverged-20260526 -- console-godot/assets/   # empty
```
Running `ls console-godot/assets/fonts` would fail with `No such file or directory`.

**Fix applied:** None to the codebase. The *substantive* D-03 guarantee — "the restore commit must touch zero files under `console-godot/assets/`" — still holds trivially, because there are no asset files on either side to mutate. I verified this directly with `git log -1 --name-only --pretty=format: | grep -c '^console-godot/assets/'` → `0`. I treated this as a documentation-only mismatch in the plan, not a real failure. The byte-identical match between backup and HEAD under the entire `console-godot/` path (Task 3.1) is the stronger guarantee anyway.

**Files modified:** None.

**Commit:** N/A (no code change).

**Recommendation for future:** Phase 19's CONTEXT/PATTERNS docs should be updated to reflect that the backup branch's `console-godot/` is exactly the 7 D-03 files and nothing else. The `assets/` subtree appears to be a planning-time assumption that did not match what actually shipped on the backup branch. If Phase 22 (CONS-02/CONS-03) intends to add assets later, that should be explicit there.

### Authentication gates

None — this plan does no network or auth work.

## Files Changed

```
A  console-godot/README.md       (verbatim from backup, ~docs)
A  console-godot/TODO.md         (verbatim from backup, deferred v2.1 items)
A  console-godot/icon.svg        (Godot editor project icon)
A  console-godot/project.godot   (Godot 4.2 config; config_version=5)
A  console-godot/scenes/Main.tscn        (main UI scene)
A  console-godot/scripts/Main.gd         (UI controller; reads HOME)
A  console-godot/scripts/TaskLoader.gd   (loads ~/.copland/tasks/)
```

Total: **7 files, +2966 lines, -0 lines, 1 commit.**

## Known Stubs

None — this plan restores existing prototype code verbatim; no new placeholders, no hardcoded empty UI states beyond the prototype's own intentional empty-state handling (which is documented in `console-godot/scripts/Main.gd` and acceptable per `must_haves.truths #9`).

## Self-Check: PASSED

- `console-godot/project.godot` → FOUND (worktree)
- `console-godot/icon.svg` → FOUND
- `console-godot/README.md` → FOUND
- `console-godot/TODO.md` → FOUND
- `console-godot/scenes/Main.tscn` → FOUND
- `console-godot/scripts/Main.gd` → FOUND
- `console-godot/scripts/TaskLoader.gd` → FOUND
- Commit `9ee2cc5` → FOUND in `git log --oneline`
- `git diff backup/local-main-diverged-20260526 HEAD -- console-godot/` → empty (byte-identical, as required by D-02/D-03)
- Restore commit touched 0 files under `console-godot/assets/` → confirmed
- Restore commit touched exactly 7 unique files → confirmed

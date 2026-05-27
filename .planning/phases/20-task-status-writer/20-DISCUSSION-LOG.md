# Phase 20 Discussion Log — Task & Status Writer

**Date:** 2026-05-27
**Mode:** discuss (interactive)
**Areas covered:** Lifecycle states • Repo-slug transform • Asana slug + crash recovery • status.md format + atomic writes

---

## Area 1: Lifecycle State Vocabulary

**Question 1.1:** TaskLoader.gd's STATES has 9 values; ROADMAP example lists 6 (with different names like 'reviewing', 'complete'). Which vocabulary does the orchestrator write to status.md?

**Options presented:**
- TaskLoader's 9 states verbatim (Recommended)
- ROADMAP's 6 states verbatim
- Hybrid: TaskLoader vocabulary, rename 'verifying'→'reviewing' and 'pr_open'→'complete'

**User selected:** TaskLoader's 9 states verbatim
→ **Locked as D-02.** Treat ROADMAP's wording as informal; fix prose in Phase 22.

**Question 1.2:** Which transitions does the orchestrator commit to writing?

**Options presented:**
- All 7 observable + blocked (Recommended)
- Coarser 5: new → planning → executing → verifying → pr_open + blocked
- Minimal 3: new → executing → pr_open + blocked

**User selected:** All 7 observable: new → selected → planning → planned → executing → verifying → pr_open (+ blocked)
→ **Locked as D-03.** Eight write points across `RunOrchestratorService::run()`.

---

## Area 2: Repo-Slug → Directory Transform

**Question 2.1:** Which filesystem transform for owner/repo → directory name?

**Options presented:**
- Slash → double-underscore: `binarygary__copland` (Recommended)
- Slash → dash: `binarygary-copland`
- Nested two-level dirs (changes TaskLoader)

**User selected:** Slash → double-underscore
→ **Locked as D-05.** Display value (with the slash) lives in task.md `repo_slug` frontmatter; directory name is the transformed version.

---

## Area 3: Asana Repo-Slug + Crash Recovery

**Question 3.1:** What goes in the directory name AND in task.md's `repo_slug` for Asana-sourced tasks?

**Options presented:**
- Registered repo's local-path basename (Recommended)
- `asana/<project-name>` (sanitized)
- `asana/<project-gid>`

**User selected:** Registered repo's local-path basename
→ **Locked as D-06.** `/Users/gary/projects/copland` + `task_source: asana` produces dir `copland`, `repo_slug: copland`. Symmetric with the GitHub case.

**Question 3.2:** Where does the crash → `blocked` transition get written?

**Options presented:**
- Existing try/catch/finally + partialPayload path (Recommended)
- Add `register_shutdown_function` belt-and-suspenders
- Only on caught exceptions (accept stale states for hard kills)

**User selected:** Existing try/catch/finally + partialPayload path
→ **Locked as D-11.** Hard-SIGKILL / PHP-fatal limitation acknowledged and accepted for v2.0.

---

## Area 4: status.md Format + Atomic Writes

**Question 4.1:** Should status.md keep a transition history below the closing `---`?

**Options presented:**
- Frontmatter + append-only transition log below (Recommended)
- Pure frontmatter, overwritten each transition
- Separate transitions.log sibling file

**User selected:** Frontmatter + append-only transition log
→ **Locked as D-08.** Each write rewrites frontmatter and appends one row to the Transitions table. Godot reads only frontmatter; the log is human/audit-only.

**Question 4.2:** Atomic write strategy for status.md updates?

**Options presented:**
- Atomic write via tmp file + rename (Recommended)
- Best-effort `file_put_contents`

**User selected:** Atomic write via tmp file + rename
→ **Locked as D-09.** Tmp + fsync + POSIX rename. Same pattern for `task.md` on creation.

---

## Confirmation Question

**Question:** Anything else to lock down before writing CONTEXT.md, or proceed with sensible defaults for the remaining bits (task.md body content placement, writer class location, $runner injection seam)?

**User selected:** Write CONTEXT.md now
→ Defaults applied as **D-08** (body rendered both as frontmatter `body` AND as document body below `---`), **D-12** (`app/Services/TaskDirectoryWriterService.php`), **D-13** (`$clock` + `$homeOverride` injection seams matching `GitService` pattern).

---

## Scope Creep Captured

- None raised during this session — discussion stayed within the writer's surface.

## Deferred Ideas Surfaced

- `merged` state writes via PR-status polling — D-17 → v2.1 if a real need appears.
- `blocked_reason` exception text in status.md frontmatter — Claude's discretion in planning whether to include in Phase 20 or punt.

## Notes

- The mismatch between ROADMAP's prose state list (`new → planning → executing → reviewing → complete | blocked`) and TaskLoader.gd's `STATES` constant was surfaced and resolved by treating TaskLoader as the authoritative source. Phase 22 owns the prose-doc fix.
- The user demonstrated strong preference for matching established patterns (HomeDirectory reuse, $runner-style injection seams, atomic-write idiom) — this carried over from Phase 19's CONTEXT depth.

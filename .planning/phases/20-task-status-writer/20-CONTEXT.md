# Phase 20: Task & Status Writer - Context

**Gathered:** 2026-05-27
**Status:** Ready for planning

<domain>
## Phase Boundary

When `RunOrchestratorService::run()` picks a task, it materializes `~/.copland/tasks/<repo-dir>/<task_id>/task.md` once and updates `~/.copland/tasks/<repo-dir>/<task_id>/status.md` on every lifecycle transition the orchestrator can observe in-process. Both files use YAML frontmatter that exactly matches what `console-godot/scripts/TaskLoader.gd` reads — the schema is a one-way contract from the writer to the console. Works for both GitHub issues (integer `number`) and Asana tasks (string GID). Crash mid-run leaves `status.md` in `blocked` rather than a stale intermediate state. Out of scope: per-run subdirectories (`runs/<run-id>/`) — Phase 21. README/docs alignment — Phase 22.

</domain>

<decisions>
## Implementation Decisions

### Schema contract with Godot (LOCKED — must not drift)
- **D-01:** The writer's output schema is dictated by `console-godot/scripts/TaskLoader.gd`. `task.md` frontmatter MUST contain at minimum the keys it reads: `id`, `title`, `repo_slug`, `repo_path`, `created_at`. `status.md` frontmatter MUST contain at minimum: `state`, `updated_at`. Additional keys are allowed (TaskLoader is permissive about unknowns) but the listed keys are non-negotiable. CONS-01 (Phase 22) verifies no drift.
- **D-02:** State vocabulary uses TaskLoader's `STATES` array verbatim: `[new, selected, planning, planned, executing, verifying, pr_open, merged, blocked]`. ROADMAP's example wording (`reviewing`, `complete`) is treated as informal — Phase 22 fixes the docs, not this phase. `merged` is NOT written by Phase 20 (would require PR-status polling, out of scope); `new`, `selected`, `planning`, `planned`, `executing`, `verifying`, `pr_open`, `blocked` are the 8 states this phase emits.

### Lifecycle transitions written by Phase 20
- **D-03:** All 7 forward transitions plus `blocked` on any failure:
  1. `new` — written on task-directory creation (task.md write) immediately after selector returns and the task is confirmed in the issue list (the very first thing the orchestrator does after `selectedIssue` is set).
  2. `selected` — written immediately after `new` once the task is committed to (paired with `new` in the same pre-planning block; emitting both gives the console a clean "row appeared, then turned blue" affordance).
  3. `planning` — written before `ClaudePlannerService::planTask()` is invoked.
  4. `planned` — written after planner returns AND `PlanValidatorService` accepts the plan.
  5. `executing` — written before `ClaudeExecutorService` enters its agentic loop.
  6. `verifying` — written before `VerificationService` runs against the worktree diff.
  7. `pr_open` — written after `gh pr create` returns successfully with the PR URL.
  8. `blocked` — written from the orchestrator's existing `catch + finally` block whenever an exception escapes any of the above steps OR SIGINT fires before `pr_open` is reached (see D-09).

### Path layout and slug normalization
- **D-04:** Filesystem layout: `~/.copland/tasks/<repo-dir>/<task_id>/{task.md, status.md}`. One repo dir per registered repo; one task dir per selected task. `runs/<run-id>/` is created by Phase 21, not this phase.
- **D-05:** GitHub repo slug `owner/repo` collapses to a single directory name via **slash → double-underscore**: `binarygary/copland` → `binarygary__copland`. The display value (`binarygary/copland`, with the slash) lives in `task.md`'s `repo_slug` frontmatter key — that's what the Godot console renders. The directory name is purely a filesystem identifier; collisions are effectively impossible since `__` is exceedingly rare in real GitHub owner/repo names.
- **D-06:** Asana tasks use the **registered repo's local-path basename** as both the directory name and the `repo_slug` value. Example: a `.copland.yml` entry with `repo_path: /Users/gary/projects/copland` and `task_source: asana` produces `~/.copland/tasks/copland/<asana-gid>/...` with `repo_slug: copland` in task.md. Symmetric with the GitHub case: the slug always identifies the working repo, regardless of where the task originated.
- **D-07:** Task IDs are written **stringified, untruncated**: GitHub `int` (e.g., `42`) becomes `"42"`; Asana GID (e.g., `"1209876543210"`) is used verbatim. Both ID forms are POSIX-safe directory names — no further escaping needed. The `id` field in task.md frontmatter is also stringified for consistency.

### File format
- **D-08:** `status.md` is **YAML frontmatter + append-only transition log** below the closing `---`. Each transition rewrites the frontmatter (current `state` and `updated_at`) and appends a one-line entry to the transitions table. Godot reads only the frontmatter; the transition log is for human/audit consumption. Format below the `---`:
  ```markdown
  ---
  state: executing
  updated_at: "2026-05-27T08:14:33Z"
  ---

  ## Transitions

  | Timestamp (UTC)        | State     |
  |------------------------|-----------|
  | 2026-05-27T08:14:01Z   | new       |
  | 2026-05-27T08:14:02Z   | selected  |
  | 2026-05-27T08:14:05Z   | planning  |
  | 2026-05-27T08:14:18Z   | planned   |
  | 2026-05-27T08:14:33Z   | executing |
  ```
  `task.md` is written **once** on creation and never rewritten in Phase 20 (per success criterion 1 — "writes task.md containing…" is singular). Its frontmatter contains the 5 TaskLoader-required keys plus `body`, `source_url`. The issue/task body text is also rendered as the markdown document body below `---` (so a human opening the file sees the task description in full prose, not just as a frontmatter scalar).

### Atomic writes
- **D-09:** All writes use the **tmp-file + atomic rename** pattern: write to `status.md.tmp`, `fsync` it, then `rename()` to `status.md`. POSIX guarantees rename atomicity on the same filesystem. Godot polls and will never see a partial/invalid YAML file. Same pattern for `task.md` on creation. Implemented as a small private helper inside the writer service (~10 lines, no dependency).
- **D-10:** Directory creation is recursive (`mkdir -p` semantics via `mkdir($path, 0755, true)`) and idempotent. The repo dir is created on demand by the first task write for that repo.

### Crash recovery (TASK-04)
- **D-11:** The `blocked` write lives inside `RunOrchestratorService::run()`'s existing `try/catch/finally` block — the same code path that already produces `partialPayload` for SIGINT and exceptions. Inside the `finally`: if the most recent state written for this task is anything other than a terminal state (`pr_open` or `blocked`), write `blocked` before returning. This reuses an already-tested cleanup path; cost is one additional method call. Accepted limitation: a hard SIGKILL or PHP fatal-error (OOM, segfault) bypasses `finally` and leaves the previous state stale — out of scope for v2.0.

### Where the writer lives
- **D-12:** New service class: `app/Services/TaskDirectoryWriterService.php`. Constructor-injected into `RunOrchestratorService` (one new dependency added to its already-long constructor). Public surface: `writeNewTask(repoSlug, taskId, title, body, repoPath, sourceUrl): void`, `writeStatus(repoSlug, taskId, state): void`, `writeBlockedIfNotTerminal(repoSlug, taskId): void`. Internal: tracks the last-written state per (`repoSlug`, `taskId`) tuple in-process for the `writeBlockedIfNotTerminal` check (no disk re-read needed during a single run).
- **D-13:** Testability seam: constructor accepts an optional `?callable $clock = null` (defaults to `fn() => gmdate('Y-m-d\TH:i:s\Z')`) so Pest tests can pin timestamps, AND an optional `?string $homeOverride = null` (defaults to `HomeDirectory::resolve()`) so tests can write into a `tmp` dir without touching the developer's real `~/.copland/`. Mirrors the `$runner`-injection pattern established by `GitService`/`AutomateCommand`/`ConsoleCommand`.

### Out of scope (explicit)
- **D-14:** Per-run subdirectories `runs/<run-id>/` — Phase 21 (TASK-03).
- **D-15:** Pest tests for the writer — Phase 21 (TASK-05). Phase 20 ships the writer; Phase 21 ships the test suite. Phase 20 MAY include smoke-level tests if cheap to write alongside the implementation, but the comprehensive coverage with temporary `HOME` is Phase 21's deliverable.
- **D-16:** Touching `~/.copland/logs/runs.jsonl` — additive only, the existing JSONL writer stays exactly as-is (TASK-04 / REQUIREMENTS § "Out of Scope").
- **D-17:** PR-merge polling to write `merged` state — would require new GitHub-API calls during run completion or a separate poller; defer to v2.1 if anyone needs it.

### Claude's Discretion
- Exact PHP signature of the writer's public methods (parameter ordering, type hints) — match Copland's existing service-class style.
- Whether to use `Symfony\Component\Filesystem\Filesystem` (already a transitive dep via Laravel) for the rename or a direct `rename()` call — both acceptable.
- Whether to add Phase 20's smoke tests inline or defer all tests to Phase 21 — per D-15, smoke tests are optional.
- Exact log message wording when each transition is written (probably none — keep the writer silent and let the orchestrator's existing `pushLog` handle progress narration).
- Whether `writeBlockedIfNotTerminal` accepts an optional "reason" string (e.g., the exception message) and stores it in frontmatter as `blocked_reason` — additive, helpful for the console; pick what reads cleanly.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Schema contract (the writer's output MUST match this read model)
- `console-godot/scripts/TaskLoader.gd` lines 1–230 — exact frontmatter keys, STATES list, iteration depth, frontmatter parser limitations (top-level scalars only, no nesting). The single most important canonical ref for this phase.

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 20: Task & Status Writer" — goal, success criteria, requirement IDs (TASK-01, TASK-02)
- `.planning/REQUIREMENTS.md` §"Backend Persistence" — full text of TASK-01..05 (Phase 20 owns 01/02; 03/04/05 belong to Phase 21) + §"Out of Scope" (additive-only constraint)
- `.planning/PROJECT.md` §"Current Milestone: v2.0 Godot Console" — milestone framing (read-only console, additive-only PHP backend)

### Code touchpoints
- `app/Services/RunOrchestratorService.php` — the entire `run()` method (the lifecycle this phase instruments); especially the `try/catch/finally` block around line 290-320 and `partialPayload()` around line 355. This is where every state write is inserted.
- `app/Data/SelectionResult.php`, `app/Data/RunResult.php` — `selectedTaskId` is `string|int`; `selectedIssueTitle` is `?string`. The writer accepts both ID forms.
- `app/Services/GitHubService.php`, `app/Services/AsanaService.php`, `app/Services/AsanaTaskSource.php` — task-source surfaces; the orchestrator already normalizes to `$selectedIssue['number']` (int for GH) and `$selectedIssue['title']`.
- `app/Support/HomeDirectory.php` — canonical HOME resolver; the writer reuses this exactly.
- `app/Support/RunLogStore.php` — the established pattern for "write into `~/.copland/<subdir>/`"; the new writer follows the same shape (ensureDirectoryExists, atomic write, no logging framework).
- `app/Services/GitService.php` — `$runner` callable-injection seam reference for testability.

### Phase-19 artifacts to align with
- `.planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md` — the Godot prototype design owner (D-04 launch mechanism). Phase 20's writer drops files exactly where `copland console` (shipped in Phase 19) points Godot.
- `console-godot/README.md` and `console-godot/TODO.md` — the prototype's own documentation of what live data should look like (run drill-in, live-tail listed as v2.1, NOT this phase).

### Codebase intel
- `.planning/codebase/STRUCTURE.md` — `app/Services/`, `app/Support/`, `app/Data/` layout
- `.planning/codebase/CONVENTIONS.md` — naming patterns (PascalCase service classes, snake_case for command-line methods), error-handling (`RuntimeException` for operational failures), Symfony Process argv-array form, constructor-property promotion
- `.planning/codebase/TESTING.md` — Pest patterns; the temporary-HOME pattern needed for Phase 21's tests should be designed-for in Phase 20's seams (D-13)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`HomeDirectory::resolve()`** — already exists, already used by `RunLogStore`. No reason to re-implement. New writer reads `HomeDirectory::resolve() . '/.copland/tasks'`.
- **`RunLogStore` pattern** (`app/Support/RunLogStore.php`) — closest structural analog for "write into `~/.copland/<subdir>/`". Look at its `ensureDirectoryExists` and JSON normalization for the shape the new writer should take (without the JSONL append behavior).
- **`RunOrchestratorService::run()`'s try/catch/finally + `partialPayload`** — already a battle-tested cleanup path that handles SIGINT and exceptions. D-11's `writeBlockedIfNotTerminal` slots into the `finally` arm with one new call. Do NOT add a parallel error-handling path.
- **Constructor injection of optional callable seams** — `GitService($runner)`, `ConsoleCommand($runner, $pathResolver)`, etc. The new writer follows the same pattern with `$clock` and `$homeOverride` (D-13).
- **`gmdate('Y-m-d\TH:i:s\Z')`** — the existing project already uses ISO-8601 UTC timestamps in `runs.jsonl`. Keep the same format for `created_at`/`updated_at` so the console and the log align.

### Established Patterns
- PascalCase service classes named `<Noun>Service.php` (`GitService`, `RunOrchestratorService`, `PlanArtifactStore`). New file: `TaskDirectoryWriterService.php`.
- Throw `RuntimeException` with descriptive messages for filesystem failures (directory creation, rename, write failures). Match `RunLogStore`'s exception text style.
- Type hints everywhere; explicit `void` returns on writer methods.
- No comments unless a non-obvious invariant needs preserving (e.g., the atomic-rename pattern deserves a single-line explanation).

### Integration Points
- New file: `app/Services/TaskDirectoryWriterService.php`
- Modified file: `app/Services/RunOrchestratorService.php` — constructor adds one dependency; `run()` gets 8 new method-call sites (one per state transition + one in finally). Net change should be ~30-40 lines of orchestrator additions.
- No changes to `RunResult`, `SelectionResult`, `ExecutionResult`, `VerificationResult`, or any data classes.
- No changes to config classes, `RunLogStore`, `GitService`, `GitHubService`, `AsanaService`, or any other service.
- No changes to the Godot side (`console-godot/scripts/TaskLoader.gd` is the contract — phase 20 conforms to it, not the other way around).
- No changes to existing tests; new tests are Phase 21's deliverable per D-15.

### Cross-task-source compatibility
- The orchestrator already normalizes both task sources into the `$selectedIssue` array shape with `number` (int|string), `title` (string), and other keys. The writer accepts these directly — no per-source branching needed inside the writer.
- For `repo_path` (the local checkout directory): present in `.copland.yml` per-repo config; needs to be threaded into the writer call. Currently `RunOrchestratorService::run()` receives `array $repoProfile` — `$repoProfile['path']` or equivalent is the value. Confirm exact key during planning.

</code_context>

<specifics>
## Specific Ideas

- The `STATES` constant in `TaskLoader.gd` (lines 20-30) is the authoritative state list. Treat any discrepancy with prose documentation (ROADMAP, README) as the prose being wrong.
- TaskLoader's frontmatter parser (line 218+) handles only top-level scalar pairs (`key: value` and `key: 'value'`). Do NOT use nested YAML structures (lists, maps, multi-line scalars with `|` or `>`) in frontmatter. Plain `key: "string"` only.
- The Godot console polls the filesystem on F5 (it does NOT have file-watching) — meaning atomic writes prevent the narrow window where a poll could read a half-written file. Without atomic writes, the operator would occasionally see a row vanish for one F5 cycle if their timing was unlucky.
- ISO-8601 UTC with the literal `Z` suffix (`2026-05-27T08:14:33Z`) is the timestamp format. Matches `runs.jsonl` and is unambiguous in YAML scalar form.
- The writer is **silent** — it does NOT call `progressCallback` or emit log lines. The orchestrator already narrates progress; double-narration would clutter overnight log output.

</specifics>

<deferred>
## Deferred Ideas

- **Per-run subdirectories `runs/<run-id>/`** — owned by Phase 21 (TASK-03). The directory layout already anticipates them (TaskLoader iterates `runs/` per task), but Phase 20 does not create them.
- **Comprehensive Pest test suite with temporary `HOME`** — owned by Phase 21 (TASK-05). Phase 20 designs the seams (D-13) so Phase 21 can test cleanly.
- **`merged` state writes** — would require post-PR-creation polling against GitHub for PR merge status. Adds a new long-running concern outside the single-run lifecycle. Defer to v2.1 if a real need arises.
- **File-watching in the Godot console** (so the operator sees changes without F5) — listed in `console-godot/TODO.md` as v2.1.
- **Live-tail of executing runs (NDJSON stream or unix socket)** — listed in `console-godot/TODO.md` as v2.1.
- **Run drill-in selection in the Godot UI** — v2.1 per `console-godot/TODO.md`.
- **`blocked_reason` exception text in status.md frontmatter** — small additive enhancement to D-11; Claude's discretion whether to include in Phase 20 or punt.
- **Console write actions from Godot** — explicitly out-of-scope per REQUIREMENTS § "Out of Scope" (read-only ceiling for v2.0 and beyond).

</deferred>

---

*Phase: 20-task-status-writer*
*Context gathered: 2026-05-27*

---
phase: 20-task-status-writer
verified: 2026-05-27T13:15:00Z
status: passed
score: 4/4 success criteria verified
overrides_applied: 0
---

# Phase 20: Task & Status Writer — Verification Report

**Phase Goal:** When the orchestrator selects a task, it materializes `~/.copland/tasks/<repo>/<id>/task.md` once and updates `status.md` on every lifecycle transition so the console can read real run state.

**Verified:** 2026-05-27T13:15:00Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Success Criteria (from ROADMAP §"Phase 20")

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | On task selection, RunOrchestratorService writes `~/.copland/tasks/<repo>/<id>/task.md` with title, body, repo_slug, repo_path, source_url, created_at | VERIFIED | `RunOrchestratorService.php:117` calls `$this->taskWriter?->writeNewTask($writerRepoSlug, $selectedIssue['number'], $selectedIssue['title'] ?? '', $selectedIssue['body'] ?? '', $repoProfile['repo_path'], $selectedIssue['html_url'] ?? '')` right after `$selectedIssue` is confirmed (line 109). Behavioral probe confirms task.md frontmatter contains all 6 keys (id, title, repo_slug, repo_path, source_url, created_at) with values populated from the inputs. Body text is rendered below the closing `---`. |
| 2 | On every lifecycle transition (new → planning → executing → reviewing → complete \| blocked) status.md is written/updated with current state + per-transition timestamp | VERIFIED | 7 happy-path `writeStatus(...)` calls at lines 118 (new), 119 (selected), 123 (planning), 176 (planned), 188 (executing), 228 (verifying), 279 (pr_open); plus `writeBlockedIfNotTerminal` at line 328 in finally. Behavioral probe confirms frontmatter rewrites on each call and the transitions table appends without truncating prior rows. D-02 mapping documents the alignment with ROADMAP's informal vocabulary. |
| 3 | The writer works for both GitHub issues (integer ID) and Asana tasks (string GID) without truncation or path collisions | VERIFIED | Single source-discrimination point at `RunOrchestratorService.php:113` (`$this->taskSource instanceof AsanaTaskSource ? basename($repoProfile['repo_path']) : $repo`). Behavioral probe ran both shapes: GitHub `binarygary/copland` + int 42 → `~/.copland/tasks/binarygary__copland/42/`; Asana `copland` + string `1209876543210` → `~/.copland/tasks/copland/1209876543210/`. String GID written verbatim and untruncated. No on-disk collision (different repo dirs). Writer is source-agnostic — taskId cast to string in `taskDir()`. |
| 4 | A run that crashes mid-execution leaves status.md in a terminal state (blocked or equivalent) rather than stale intermediate | VERIFIED | `RunOrchestratorService.php:316-332` — `finally` block contains `if ($this->taskWriter !== null && $selectedIssue !== null) { try { $this->taskWriter->writeBlockedIfNotTerminal($writerRepoSlug, $selectedIssue['number']); } catch (\Throwable $e) { ... } }`. `writeBlockedIfNotTerminal` (writer line 71-80) returns early if last-state is null, pr_open, or blocked; otherwise writes `blocked`. Behavioral probe confirms: task last-set to `executing` is upgraded to `blocked` when the method is called. Wrapped in own try/catch so writer errors never mask the original exception. Accepted limitation (D-11): SIGKILL or PHP fatal-error bypasses `finally`. |

**Score:** 4/4 success criteria verified.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/TaskDirectoryWriterService.php` | 3 public methods, atomic writes, hand-rolled YAML, HomeDirectory + slug collapse | VERIFIED | 161 lines. Methods `writeNewTask`, `writeStatus`, `writeBlockedIfNotTerminal` present with correct signatures. Hand-rolled `renderFrontmatter()` (no symfony/yaml). `atomicWrite()` uses tmp+rename with tmp in destination dir. `taskDir()` uses `str_replace('/', '__', $repoSlug)` (D-05) and gates on `$homeOverride ?? HomeDirectory::resolve()` (D-13). |
| `tests/Feature/TaskDirectoryWriterServiceTest.php` | One smoke test against temp HOME | VERIFIED | 53 lines, 1 test, 15 assertions. Swaps `$_SERVER['HOME']`, uses `clock:` named-argument seam, asserts the D-05 disk-layout collapse AND display-form preservation in frontmatter, exercises writeNewTask + writeStatus×2 + writeBlockedIfNotTerminal in one happy-path flow. |
| `app/Services/RunOrchestratorService.php` | Constructor param + $writerRepoSlug derivation + 9 call sites + finally guard | VERIFIED | New trailing optional param `?TaskDirectoryWriterService $taskWriter = null` at line 31. `$writerRepoSlug` derivation at lines 113-115. 9 call sites all using `$writerRepoSlug`: 1 writeNewTask, 7 writeStatus, 1 writeBlockedIfNotTerminal. Finally guard `$selectedIssue !== null` wraps the writer call in its own try/catch. |
| `app/Commands/RunCommand.php` | Composition-root wiring | VERIFIED | `use App\Services\TaskDirectoryWriterService;` at line 20. `taskWriter: new TaskDirectoryWriterService,` at line 300 inside the `new RunOrchestratorService(...)` named-argument call. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `RunCommand.php` | `RunOrchestratorService.php` | `taskWriter: new TaskDirectoryWriterService` | WIRED | Line 300 — single named-argument addition; no other RunOrchestratorService construction exists in the codebase. |
| `RunOrchestratorService.php` | `TaskDirectoryWriterService.php` | 9 call sites with `$writerRepoSlug` | WIRED | Grep count: `$writerRepoSlug` = 10 (1 derivation + 9 usages). All call sites use `$writerRepoSlug`, never raw `$repo`. |
| `RunOrchestratorService.php` | `AsanaTaskSource.php` | `instanceof AsanaTaskSource` (single source-discrimination point) | WIRED | Exactly 1 occurrence at line 113. Same `App\Services` namespace — no new `use` statement needed. |
| `TaskDirectoryWriterService.php` | `App\Support\HomeDirectory` | `HomeDirectory::resolve()` gated by `$homeOverride` seam | WIRED | Line 93 inside `taskDir()`. Test injects `$homeOverride` to skip this branch; production wiring (RunCommand) lets it default. |
| Writer output | `console-godot/scripts/TaskLoader.gd` | YAML frontmatter contract (id, title, repo_slug, repo_path, created_at; state, updated_at) | WIRED | Schema contract verified line-by-line: TaskLoader reads `id` (line 186), `title` (187), `repo_slug` (188), `repo_path` (189), `created_at` (190), `state` (191), `updated_at` (192). Writer emits all 7 keys plus additive `source_url` and `body` (TaskLoader ignores unknowns). Frontmatter parser handles top-level scalars only; writer emits only top-level scalars. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `task.md` `id`/`title`/`body` | `$selectedIssue['number'/'title'/'body']` | `$prefiltered->accepted` from `TaskSource::fetchTasks()` — GitHubService `getIssues` returns raw GitHub API issue array (includes `number`, `title`, `body`, `html_url`); AsanaService `getOpenTasks` normalizes to `['number'=>gid, 'title'=>name, 'body'=>notes, 'labels'=>...]` | YES (both source types) | FLOWING |
| `task.md` `repo_path` | `$repoProfile['repo_path']` | `RunCommand.php:287` `'repo_path' => $path` where `$path` is the resolved repo checkout path | YES | FLOWING |
| `task.md` `source_url` | `$selectedIssue['html_url'] ?? ''` | GitHub: native `html_url` field. Asana: not present → empty string (intentional per RESEARCH Pitfall 6 / D-decision Q1) | YES (GH) / EMPTY-OK (Asana) | FLOWING |
| `task.md` `created_at` / `status.md` `updated_at` | `$this->now()` | `($this->clock)()` if injected, else `gmdate('Y-m-d\TH:i:s\Z')` | YES | FLOWING |
| `status.md` transitions table | Existing file body via `extractBody()` | `file_get_contents($statusPath)` parsed via `extractBody()` which strips frontmatter and preserves prior rows | YES — behavioral probe confirms 3 rows survive (new, executing, blocked) | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Writer emits TaskLoader-conformant task.md for GitHub-shaped task | `php -r '...writeNewTask("binarygary/copland", 42, ...)'` | task.md contains all 5 required keys + `source_url` + body | PASS |
| Writer emits TaskLoader-conformant task.md for Asana-shaped task | `php -r '...writeNewTask("copland", "1209876543210", ...)'` | task.md contains `id: "1209876543210"` (untruncated GID) + `repo_slug: "copland"` (no slash) + `source_url: ""` | PASS |
| status.md appends transitions on each writeStatus | `php -r '...writeStatus(new); writeStatus(executing); ...'` | All transition rows survive in table; frontmatter shows latest state | PASS |
| writeBlockedIfNotTerminal upgrades non-terminal to blocked | After `writeStatus(executing)` then `writeBlockedIfNotTerminal` | `state: "blocked"` + new `blocked` row appended | PASS |
| writeBlockedIfNotTerminal is no-op for `pr_open` / null | Smoke test asserts no-op before writeStatus; live behavior per code review at writer:75-77 | Early return when `$current === null || 'pr_open' || 'blocked'` | PASS (verified by code + smoke test) |
| No on-disk collision between GitHub and Asana source types | Both probed paths exist with different repo-dir names | `binarygary__copland/42/` vs `copland/1209876543210/` — independent trees | PASS |
| Atomic-rename invariant | Code review: `atomicWrite()` writes to `$path.'.tmp'` (same dir) then `rename()` | tmp file lives in destination dir per RESEARCH Pitfall 2; rename atomic on same filesystem | PASS |
| Full Pest suite green | `./vendor/bin/pest` | `Tests: 138 passed (458 assertions)` in 1.21s | PASS |

### Decision Coverage (D-01..D-17)

| Decision | Description | Status |
|----------|-------------|--------|
| D-01 | task.md frontmatter contains 5 TaskLoader-required keys | COVERED — `renderFrontmatter` emits id, title, repo_slug, repo_path, source_url, created_at (5 required + source_url) |
| D-02 | State vocabulary uses TaskLoader STATES; `merged` not emitted by Phase 20 | COVERED — grep `'merged'` = 0; 7 happy-path states + `blocked` |
| D-03 | All 7 forward transitions + `blocked` on failure | COVERED — 7 writeStatus calls + writeBlockedIfNotTerminal in finally |
| D-04 | Filesystem layout `~/.copland/tasks/<repo>/<id>/{task.md,status.md}` | COVERED — `taskDir()` builds `{home}/.copland/tasks/{repoDir}/{taskId}` |
| D-05 | GitHub `owner/repo` → `owner__repo` on disk; display form in frontmatter | COVERED — `str_replace('/', '__', $repoSlug)` in `taskDir()`; frontmatter writes raw `$repoSlug` |
| D-06 | Asana uses `basename($repoProfile['repo_path'])` for repo_slug; instanceof check | COVERED — line 113-115 single source-discrimination point; behavioral probe confirms |
| D-07 | Task IDs stringified, untruncated; both forms POSIX-safe | COVERED — `(string) $taskId` in `taskDir()` + `renderFrontmatter`; behavioral probe confirms 13-digit Asana GID survives |
| D-08 | status.md = frontmatter + append-only transitions log; task.md singular | COVERED — `extractBody()` preserves prior rows; writeNewTask called exactly once (grep == 1) |
| D-09 | Atomic tmp+rename writes | COVERED — `atomicWrite()` writes to `$path.'.tmp'` then `rename()` |
| D-10 | Recursive idempotent mkdir | COVERED — `ensureDirectoryExists()` uses `mkdir($dir, 0755, true)` with is_dir guards |
| D-11 | `blocked` written from finally, guarded on $selectedIssue !== null | COVERED — lines 326-332 |
| D-12 | Writer is silent (no pushLog/progressCallback) | COVERED — grep in writer file = 0 |
| D-13 | Testability seams: `?callable $clock` + `?string $homeOverride` | COVERED — constructor lines 12-15; smoke test uses `clock:` named-arg |
| D-14 | `runs/<run-id>/` subdirs — Phase 21 | DEFERRED — not in Phase 20 scope |
| D-15 | Comprehensive Pest tests deferred to Phase 21; smoke test acceptable | COVERED — 1 smoke test in Phase 20 (53 lines, 15 assertions); orchestrator tests unchanged |
| D-16 | `runs.jsonl` untouched | COVERED — `RunLogStore.append()` call in finally still present at line 339; `partialPayload()` unmodified at lines 379-409 |
| D-17 | No `merged` state writes | COVERED — grep `'merged'` = 0 in orchestrator and writer |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| TASK-01 | 20-01, 20-02 | Orchestrator writes `~/.copland/tasks/<repo>/<id>/task.md` containing task title, body, repo slug, repo path, source URL, created_at on selection | SATISFIED | writeNewTask call at orchestrator line 117; behavioral probe confirms file contents |
| TASK-02 | 20-01, 20-02 | Orchestrator writes/updates status.md on every lifecycle transition with per-transition timestamp | SATISFIED | 7 writeStatus calls + 1 writeBlockedIfNotTerminal; behavioral probe confirms append-only transitions table with timestamps |

### Anti-Patterns Found

None.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| _none_ | — | — | — | grep for TBD/FIXME/XXX/TODO/HACK/PLACEHOLDER returned zero matches across all 4 modified/created files |

### Backward Compatibility

`tests/Unit/RunOrchestratorServiceTest.php` (7 tests, 87 assertions) continues to pass without test-side modifications — proves the new `?TaskDirectoryWriterService $taskWriter = null` trailing optional parameter is backward-compatible. Nullsafe `?->` operator at all 8 happy-path call sites means a null writer is a silent no-op (existing tests pass null and never instantiate the writer).

### Gaps Summary

No gaps. All 4 ROADMAP success criteria are verified by file inspection, grep audit, schema cross-reference against `TaskLoader.gd`, full Pest suite pass (138/138), and an end-to-end behavioral probe that wrote and inspected real task.md/status.md files for both GitHub and Asana task shapes.

The only acknowledged limitation is documented in D-11: a hard SIGKILL or PHP fatal-error (OOM, segfault) bypasses the `finally` arm and leaves the previous state stale. This is an explicit Phase-20-accepted limitation, not a gap.

---

_Verified: 2026-05-27T13:15:00Z_
_Verifier: Claude (gsd-verifier)_

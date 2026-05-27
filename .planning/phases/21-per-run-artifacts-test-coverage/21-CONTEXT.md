# Phase 21: Per-Run Artifacts & Test Coverage - Context

**Gathered:** 2026-05-27
**Status:** Ready for planning

<domain>
## Phase Boundary

When the Phase 20 orchestrator selects a task and begins a run, it materializes a per-run subdirectory `~/.copland/tasks/<repo-dir>/<task_id>/runs/<run-id>/` containing two files: a `status.md` that mirrors the task-level state machine (all 8 transitions) and an `outcome.md` written once at run completion with PR URL, cost summary, and failure reason. The existing `~/.copland/logs/runs.jsonl` JSONL log is **not touched** — additive only. `TaskDirectoryWriterService` (shipped by Phase 20) is extended with three new public methods (`writeRunStatus`, `writeRunOutcome`, `writeRunBlockedIfNotTerminal`); the existing four methods are unchanged. The full writer surface is covered by Pest tests against a temporary `HOME` so no developer-machine state is touched. PHPStan level 5 is brought to zero errors (6 pre-existing errors fixed) and stays clean.

Out of scope: any modification to `RunLogStore`, JSONL schema, or the canonical `~/.copland/logs/runs.jsonl` path. TaskLoader extension to render `outcome.md` (deferred to Phase 22 if needed — Phase 21 ships only the writer side; TaskLoader already reads each run's `status.md` per `load_runs()` at lines 277–299). Orchestrator integration tests (writer-only test scope per D-19).

</domain>

<decisions>
## Implementation Decisions

### Run-ID format and lifetime (LOCKED)
- **D-01:** Run ID is an ISO-8601 UTC timestamp with colons replaced by dashes for POSIX safety: `2026-05-27T19-15-22Z`. Generated **exactly once per `RunOrchestratorService::run()` call**. Format derives from the existing clock seam already in `TaskDirectoryWriterService` — call the clock once at the start of `run()`, transform the colons, store as `$runId`. Lexicographic sorting = newest-last by name, matching `gmdate('Y-m-d\TH:i:s\Z')` already used for `created_at`/`updated_at`. TaskLoader's mtime-sort remains the tiebreaker.
- **D-02:** Run ID is generated in the **orchestrator**, not the writer. The writer accepts `$runId` as a parameter on every per-run method so tests can pin run IDs deterministically (same pattern as the Phase 20 clock seam). This means the writer remains pure — no time-based identifier generation hidden inside it.

### Run-dir file layout (LOCKED)
- **D-03:** Each `runs/<run-id>/` contains exactly two files: `status.md` and `outcome.md`. Both are YAML frontmatter + optional markdown body, consistent with Phase 20's `task.md` and `status.md`. The Godot TaskLoader's frontmatter parser (top-level scalars only) governs both files — same parser limits apply.
- **D-04:** `status.md` (per-run) schema mirrors task-level `status.md` exactly:
  ```
  ---
  state: executing
  updated_at: "2026-05-27T19:15:22Z"
  ---

  ## Transitions

  | Timestamp (UTC)        | State     |
  |------------------------|-----------|
  | 2026-05-27T19:15:22Z   | new       |
  ...
  ```
  Same `STATES` vocabulary as Phase 20 D-02 (`[new, selected, planning, planned, executing, verifying, pr_open, blocked]` — `merged` is NOT written).
- **D-05:** `outcome.md` is written **once at terminal state** (after `pr_open` for success, or alongside `blocked` for failure). Frontmatter contains keys (some may be null/empty):
  - `run_id: "2026-05-27T19-15-22Z"`
  - `status: "pr_open" | "blocked" | "crashed"` (one of the terminal payload `status` values from `payloadFromResult` / `partialPayload`)
  - `pr_number: 42 | null`
  - `pr_url: "https://github.com/..." | ""`
  - `cost_usd: 0.184` (numeric, total across all stages)
  - `started_at: "2026-05-27T19:15:22Z"`
  - `finished_at: "2026-05-27T19:18:47Z"`
  - `failure_reason: "" | "..."`
  - `partial: false | true` (mirrors the JSONL `partial` flag for run-was-interrupted)

  Body below `---` MAY include a per-stage usage table (selector / planner / executor model + tokens + cost) — Claude's discretion, useful for human grep, but not parsed by TaskLoader.

### Per-run lifecycle write coverage (LOCKED)
- **D-06:** **All 8 lifecycle transitions** are written per-run, mirroring the task-level writes. Every existing task-level `writeStatus($writerRepoSlug, $taskId, $state)` call site in `RunOrchestratorService` gets a **paired per-run call** `writeRunStatus($writerRepoSlug, $taskId, $runId, $state)` immediately adjacent. Same ordering, same `$writerRepoSlug` derivation, same nullsafe `?->` guard. TaskLoader's `load_runs()` (lines 277–299) thus sees live per-run state for in-flight runs, not just terminal-state snapshots.
- **D-07:** The writer's in-memory `$lastState` map gains a new keying: per-run state is keyed by the tuple `(repoSlug, taskId, runId)` — distinct from task-level which keys on `(repoSlug, taskId)`. Both maps coexist on the writer instance.
- **D-08:** The finally-arm `writeBlockedIfNotTerminal` gets a paired per-run call: `writeRunBlockedIfNotTerminal($writerRepoSlug, $taskId, $runId)`. Guard order: if `$selectedIssue !== null` AND `$runId !== null` (the latter is set inside `run()` before any catch can fire, so the only false case is a crash before run-id derivation). Wrapped in its own `try/catch` so writer failures never mask the original exception.

### outcome.md write timing (LOCKED)
- **D-09:** `outcome.md` is written from the orchestrator's existing terminal-finally block at the same point `RunLogStore::append()` is currently called (around line 339 in `RunOrchestratorService.php`). The payload that produces the JSONL row is reshaped into outcome.md frontmatter via a small private mapper helper in `RunOrchestratorService` (e.g., `outcomePayload(?RunResult $result, partialPayload $partial): array`). Single write per run, atomic tmp+rename.
- **D-10:** If the writer dependency is `null` (existing optional-dep contract from Phase 20), the orchestrator skips `outcome.md` writes silently — same `?->` nullsafe pattern as Phase 20 D-11.
- **D-11:** `outcome.md` write failures are caught and logged via `pushLog` ("Warning: outcome write failed: …"), matching the existing pattern around the JSONL log write (lines 339–343). The orchestrator does not re-throw; original exceptions take precedence.

### Writer extension surface (LOCKED)
- **D-12:** `TaskDirectoryWriterService` gains exactly three new public methods, **without touching** the existing four (`writeNewTask`, `writeStatus`, `writeBlockedIfNotTerminal`, plus the private `atomicWrite`):
  - `writeRunStatus(string $repoSlug, int|string $taskId, string $runId, string $state): void`
  - `writeRunOutcome(string $repoSlug, int|string $taskId, string $runId, array $outcome): void`
  - `writeRunBlockedIfNotTerminal(string $repoSlug, int|string $taskId, string $runId): void`
- **D-13:** New private helpers as needed (e.g., a small `runDir($repoSlug, $taskId, $runId)` path resolver paralleling the existing `taskDir` helper). Atomic-rename via the existing private `atomicWrite()` — no new write primitive.
- **D-14:** Writer remains **silent** (D-12 from Phase 20) — no `pushLog`/`progressCallback` calls inside the writer. The orchestrator narrates progress; the writer just writes.

### JSONL log untouched (LOCKED)
- **D-15:** `RunLogStore::append()` and the JSONL path (`~/.copland/logs/runs.jsonl`) are **not modified**. Phase 21 adds run-dir writes alongside; it does not replace, mirror, or remove the JSONL log. The two outputs are independent. Test: `git diff` for Phase 21 MUST NOT touch `app/Support/RunLogStore.php`.

### PHPStan cleanup (LOCKED)
- **D-16:** The 6 pre-existing PHPStan level-5 errors are fixed in this phase, as a dedicated plan ordered before the main writer/test work so the comprehensive test plan can assert "PHPStan reports 0 errors" as part of its acceptance criteria. The errors are skimmed in the planning phase to confirm they are mechanical (type annotations, null checks); if any are structural (>2 hours scope), they get carved out to a follow-up phase and the SC4 interpretation is amended via a CONTEXT note rather than letting scope balloon.
- **D-17:** PHPStan's child-process OOM (observed at 128M default during context gathering) is addressed by bumping `parallel.maximumNumberOfProcesses` down or setting `phpstan.tmpDir` differently, OR — simpler — by adding `--memory-limit=512M` to a documented `composer phpstan` script (or `Makefile` target) so CI and humans both invoke it correctly. Claude's discretion which approach lands. The bare `./vendor/bin/phpstan analyse` invocation should not OOM.

### Test scope (LOCKED)
- **D-18:** **Writer-only comprehensive coverage.** All seven public writer methods (4 existing + 3 new) exercised against a temp `HOME` via the existing `$homeOverride` seam. Coverage axes:
  - Both ID forms: integer (GitHub) and 13-digit string GID (Asana), in both task-level and per-run contexts
  - All 8 lifecycle states for task-level `writeStatus`
  - All 8 lifecycle states for per-run `writeRunStatus` (paired with task-level)
  - `writeBlockedIfNotTerminal` and `writeRunBlockedIfNotTerminal` — early-return on terminal states (`pr_open`/`blocked`) verified
  - `writeNewTask` exact frontmatter key assertion vs TaskLoader contract (all 7 keys: 5 required + `body` + `source_url`)
  - Asana `source_url: ""` invariant (Research Q1 from Phase 20)
  - `writeRunOutcome` frontmatter key coverage (all 9 keys per D-05)
  - Atomic-rename correctness: write into a fixture path, assert no `.tmp` files remain after a successful write
  - Idempotent directory creation: write twice into the same task/run dir, no errors
  - Transitions-table append-only: 3 sequential `writeStatus` calls produce a 3-row table (not overwritten)
  - In-memory `$lastState` per-tuple isolation: two concurrent `(repoSlug, taskId)` keys do not cross-pollute
- **D-19:** **No orchestrator integration tests in Phase 21.** The existing Phase 20 nullsafe-injection pattern means the writer can be tested standalone; integration is implicitly covered by the Phase 22 E2E smoke. Phase 20's smoke test (`tests/Feature/TaskDirectoryWriterServiceTest.php`) is absorbed into the new comprehensive suite — same file path, expanded contents — so the test count goes from 1 to ~12–18.

### Plan decomposition guidance (advisory — planner finalizes)
- **D-20:** Likely a 3-plan phase:
  - Plan 21-01 (Wave 1): PHPStan cleanup — fix the 6 existing level-5 errors + add memory limit. Atomic; no dependencies; can run first so the new test plan can assert level 5 stays clean.
  - Plan 21-02 (Wave 2): Extend `TaskDirectoryWriterService` with the 3 new methods + extend the orchestrator's `run()` and `RunCommand::handle()` composition to thread `$runId` and call the new writer methods at the existing call sites + finally arm.
  - Plan 21-03 (Wave 3): Comprehensive Pest suite (writer-only, all 7 public methods, both ID forms, all 8 states, etc.). Depends on 21-02.
  Planner may choose 2 plans if 21-01 is small enough to combine with 21-02, but the dependency arrow 21-02 → 21-03 is fixed.

### Claude's Discretion
- Exact PHPStan invocation choice (composer script vs Makefile vs `phpstan.neon` config tweak).
- Whether `outcome.md`'s body includes a per-stage usage table or stays frontmatter-only.
- Whether `$runId` is passed as a separate parameter to existing methods or stored as orchestrator-side state — D-12 fixes the API choice (separate parameter on new methods only); existing method signatures are unchanged.
- Whether to introduce a `RunArtifactPayload` data class for `writeRunOutcome`'s array argument or keep it as an `array` (the existing pattern is `array $payload` per `RunLogStore::append`).
- Test organization: one big `TaskDirectoryWriterServiceTest.php` file vs split (`writer-task`, `writer-run`, `writer-blocked`) — both acceptable.
- Optional `blocked_reason` frontmatter key in per-run status.md when finally arm fires from a caught exception (parallels the deferred enhancement noted in Phase 20 CONTEXT.md). Same recommendation: yes, ~3 lines, future-proofs the console.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Schema contract (the writer's output MUST match these read models)
- `console-godot/scripts/TaskLoader.gd` lines 277–299 (`load_runs()`) — exact read model for each `runs/<run-id>/` directory; expects `status.md` with `state` + `updated_at` frontmatter; iterates run subdirs newest-first by mtime.
- `console-godot/scripts/TaskLoader.gd` lines 218–256 (frontmatter parser) — top-level scalars only, single quote-pair strip; same parser runs for both `status.md` and `outcome.md` per-run. Phase 21 stays inside these parser limits.

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 21: Per-Run Artifacts & Test Coverage" — goal, 4 success criteria (incl. SC4 "PHPStan level 5 stays clean", which D-16 addresses)
- `.planning/REQUIREMENTS.md` §"Backend Persistence" — full text of TASK-03, TASK-04, TASK-05 + §"Out of Scope" (no JSONL replacement, no console writes)
- `.planning/PROJECT.md` §"Current Milestone: v2.0 Godot Console" — milestone framing

### Phase 20 (direct dependency — Phase 21 EXTENDS, does not rewrite)
- `.planning/phases/20-task-status-writer/20-CONTEXT.md` — locked decisions D-01..D-17 inherited verbatim (state vocabulary, slug normalization, atomic-rename, hand-rolled YAML, $writerRepoSlug derivation, testability seams, silent-writer rule)
- `.planning/phases/20-task-status-writer/20-RESEARCH.md` — line-numbered insertion points in `run()` still apply; the per-run paired writes go immediately adjacent
- `.planning/phases/20-task-status-writer/20-PATTERNS.md` — analog code excerpts (RunLogStore directory-ensure, GitService $runner seam) — same patterns extend
- `.planning/phases/20-task-status-writer/20-VERIFICATION.md` — verified line numbers (call sites at 117, 118, 119, 123, 176, 188, 228, 279; finally arm at 326–332)

### Code touchpoints
- `app/Services/TaskDirectoryWriterService.php` (161 lines) — EXTEND with 3 new methods; existing 4 methods + `atomicWrite` unchanged
- `app/Services/RunOrchestratorService.php` — `run()` method around lines 109–280: derive `$runId` once after `$writerRepoSlug` derivation (line 113); add per-run paired writes adjacent to each of the 7 existing `writeStatus` calls; add `writeRunBlockedIfNotTerminal` adjacent to the finally-arm `writeBlockedIfNotTerminal` at lines 326–332. Add `writeRunOutcome` call inside the terminal-finally JSONL-append block at lines 334–343.
- `app/Services/RunOrchestratorService.php` lines 347–405 — `payloadFromResult` and `partialPayload` provide the source data for `outcome.md`; a new private mapper helper distills them into the 9-key `outcome.md` frontmatter shape per D-05.
- `app/Commands/RunCommand.php` — composition root unchanged (writer dep already wired in Phase 20; new methods are on the same instance).
- `app/Support/RunLogStore.php` — MUST NOT be modified (TASK-04, D-15)
- `app/Support/HomeDirectory.php` — reused unchanged
- `tests/Feature/TaskDirectoryWriterServiceTest.php` — Phase 20 smoke test; absorbed and expanded by Phase 21's comprehensive suite (same file path, ~12–18 tests instead of 1)

### Testing infrastructure
- `.planning/codebase/TESTING.md` — Pest 3.x/4.x conventions, `$runner` callable-injection seam pattern, `tests/Feature/` vs `tests/Unit/` placement
- `tests/Pest.php`, `tests/TestCase.php` — base test setup if needed
- `phpstan.neon` — level 5 config; gets memory-limit fix per D-17

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`TaskDirectoryWriterService` from Phase 20** (lines 1–161) — atomic-rename helper, `taskDir()` path resolver, hand-rolled YAML frontmatter renderer, `$lastState` per-tuple map, clock + homeOverride seams. Every Phase 21 extension reuses these primitives. The new `runDir($repoSlug, $taskId, $runId)` is one line: `taskDir() . "/runs/$runId"`.
- **`RunOrchestratorService::run()` `$writerRepoSlug` derivation** (line 113) — `$runId` derivation goes immediately after, computed once via the writer's clock seam, threaded through all per-run call sites in this `run()` invocation.
- **`payloadFromResult` / `partialPayload`** (lines 347–405) — the data source for `outcome.md`. Mapping is mechanical: same keys, renamed where appropriate (`pr.number`/`pr.url` → flat `pr_number`/`pr_url`; `usage.total.estimated_cost_usd` → flat `cost_usd`).
- **Existing terminal-finally JSONL-append block** (lines 334–343) — already wraps `runLogStore->append()` in try/catch with a `pushLog` warning. The `outcome.md` write slots in adjacent with the same try/catch shape — copy the pattern verbatim.

### Established Patterns
- **Nullsafe writer access** — Every writer call uses `?->` so the orchestrator works with or without the dep. Phase 21 keeps this; new call sites all use `?->` too.
- **Atomic tmp+rename** — Sole write primitive. Both new `status.md` (per-run) and `outcome.md` use the existing private `atomicWrite()`.
- **Hand-rolled YAML over symfony/yaml** — Phase 20 RESEARCH Q6 settled this: the writer's renderer emits top-level scalars only, matching TaskLoader's parser. Same renderer extends for `outcome.md` (no new code; reuse the existing key-by-key emitter).
- **`gmdate('Y-m-d\TH:i:s\Z')` ISO timestamps** — used for `created_at`, `updated_at`; `$runId` is the same string with colons → dashes.
- **In-memory `$lastState`** — extended to a second map keyed by `(repoSlug, taskId, runId)`; lookups/inserts mirror the existing pattern.
- **`requirements:` frontmatter on every plan** — TASK-03, TASK-04, TASK-05 must appear in some plan's `requirements`. TASK-04 (JSONL untouched) is satisfied by a negative assertion in the test suite plan: `git diff` does not touch `RunLogStore.php`.

### Integration Points
- New private helper in `RunOrchestratorService`: `outcomePayload(?RunResult $result, ?array $partialPayload): array` mapping the existing JSONL payload shape into outcome.md frontmatter keys.
- New private helper in `TaskDirectoryWriterService`: `runDir($repoSlug, $taskId, $runId)` (one-liner).
- No new dependencies. No new namespaces. No new classes. The phase is pure extension.
- No changes to `RunResult`, `SelectionResult`, `ExecutionResult`, `VerificationResult`, any data classes.
- No changes to `RunCommand.php` (composition root already wires the writer from Phase 20).
- No changes to Godot side — `TaskLoader.gd` already reads each run's `status.md`; `outcome.md` is for human/audit consumption now and Phase 22 may extend TaskLoader to render it.

</code_context>

<specifics>
## Specific Ideas

- Run ID format mirrors existing timestamp usage: `gmdate('Y-m-d\TH:i:s\Z')` produces `2026-05-27T19:15:22Z`; `str_replace(':', '-', …)` produces `2026-05-27T19-15-22Z`. No new utility needed.
- `outcome.md` frontmatter keys come directly from the existing JSONL payload — same data, renamed for flatness. Keep names in `snake_case` to match the JSONL convention (`pr_number` not `prNumber`, `cost_usd` not `costUsd`).
- Per-run `status.md` is byte-for-byte the same schema as the task-level `status.md`. Reusing the same writer code path eliminates schema drift between the two surfaces (the Godot reader uses the same parser for both).
- The Phase 20 smoke test in `tests/Feature/TaskDirectoryWriterServiceTest.php` is the seed for the comprehensive suite — same file path, expanded contents.

</specifics>

<deferred>
## Deferred Ideas

- **TaskLoader extension to render `outcome.md`** — Phase 22 territory (or a v2.1 enhancement). Phase 21 ships only the writer side; TaskLoader continues to read only `status.md` from each run dir via `load_runs()`.
- **Orchestrator-level integration tests** — explicitly deferred per D-19. The Phase 22 E2E smoke covers integration implicitly.
- **`merged` state writes** — still out of scope (Phase 20 D-17 carried forward).
- **PR-merge polling** — still out of scope (Phase 20 D-17 carried forward).
- **Optional `blocked_reason` exception text in per-run status.md frontmatter** — small additive enhancement; Claude's discretion whether to include in Phase 21 implementation.
- **Stale run-dir cleanup / TTL** — over time `~/.copland/tasks/<repo>/<id>/runs/` accumulates entries. Not in scope; deferred to a future "operator UX" phase.
- **Concurrent-run safety** — two parallel `copland run` invocations against the same task ID would generate distinct run IDs (timestamp + per-process clock) and thus distinct run subdirs; atomic rename prevents file-level corruption. PID-locking deferred (Phase 20 threat-model entry carried forward).
- **Console write actions from Godot** — still out of scope per REQUIREMENTS § "Out of Scope" (read-only ceiling for v2.0 and beyond).

</deferred>

---

*Phase: 21-per-run-artifacts-test-coverage*
*Context gathered: 2026-05-27*

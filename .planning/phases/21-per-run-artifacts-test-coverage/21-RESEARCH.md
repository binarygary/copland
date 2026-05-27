# Phase 21: Per-Run Artifacts & Test Coverage - Research

**Researched:** 2026-05-27
**Domain:** PHP/Laravel Zero — additive writer extension + Pest comprehensive coverage + PHPStan level-5 cleanup
**Confidence:** HIGH

## Summary

This is a focused research pass. CONTEXT.md locks 20 decisions; this document confirms the three deliverables the planner needs:

1. **The 6 PHPStan level-5 errors are entirely mechanical** — 2 unused-nullable-return-types in Claude services, 1 obviously-true `!== null` check on a string (line 317 of `RunOrchestratorService.php`), 2 PHP variable-may-not-be-defined errors that resolve identically (initialize the variable at the top of `run()`), and 1 trivially-removable `isset()` on a non-nullable array offset in `HomeDirectory`. Combined fix surface: ~12-15 lines across 4 files. Zero structural risk. No carve-out needed.

2. **The 9 outcome.md keys map cleanly to existing payload shapes** with two minor caveats: `cost_usd` is sourced from `usage.total.estimated_cost_usd` (a nullable `ModelUsage` field — must default to `0.0` when null), and `partial` already exists in both `payloadFromResult` (hardcoded `false`) and `partialPayload` (hardcoded `true`). `pr_number` / `pr_url` flatten from the nested `pr.number` / `pr.url`. Every other key (`run_id`, `status`, `started_at`, `finished_at`, `failure_reason`) reads through verbatim.

3. **The Pest temp-HOME idiom is firmly established** — every test in `tests/` that writes to a HOME-rooted path uses the same 4-line pattern: save `$_SERVER['HOME']`, mint `sys_get_temp_dir() . '/copland-<slug>-' . uniqid()`, `mkdir` it, restore at end of test. No `beforeEach` / `tearDown` pattern is in use anywhere for HOME swapping. The Phase 20 smoke test follows this exactly.

**Primary recommendation:** Plan 21-01 fixes PHPStan first (small, atomic, ~15 lines across 4 files). Plan 21-02 extends `TaskDirectoryWriterService` + threads `$runId` and the outcome.md mapper into the orchestrator. Plan 21-03 lands the comprehensive Pest suite using the established `$_SERVER['HOME']` swap idiom. All three deliverables verified against source — no open questions block planning.

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Run-ID format and lifetime:**
- **D-01:** Run ID = ISO-8601 UTC with colons → dashes: `2026-05-27T19-15-22Z`. Generated once per `run()`. Lexicographic-sort = chronological.
- **D-02:** Run ID generated in orchestrator, not writer. Writer accepts `$runId` as a parameter on each per-run method.

**Run-dir file layout:**
- **D-03:** Each `runs/<run-id>/` contains exactly `status.md` and `outcome.md`. YAML frontmatter + optional body, top-level scalars only.
- **D-04:** Per-run `status.md` schema mirrors task-level exactly. Same 8 STATES vocabulary (no `merged`).
- **D-05:** `outcome.md` written once at terminal state. Frontmatter keys: `run_id`, `status`, `pr_number`, `pr_url`, `cost_usd`, `started_at`, `finished_at`, `failure_reason`, `partial`. Body MAY contain per-stage usage table (Claude's discretion).

**Per-run lifecycle write coverage:**
- **D-06:** All 8 lifecycle transitions get paired per-run writes adjacent to the existing task-level writes.
- **D-07:** Writer's `$lastState` gains a second per-tuple keying: `(repoSlug, taskId, runId)`. Both maps coexist.
- **D-08:** Finally-arm `writeBlockedIfNotTerminal` paired with `writeRunBlockedIfNotTerminal`. Guard order: `$selectedIssue !== null && $runId !== null`. Own try/catch — never mask original exception.

**outcome.md write timing:**
- **D-09:** Written from the existing terminal-finally JSONL-append block (line 339) via a new private mapper helper `outcomePayload()`.
- **D-10:** Nullsafe (`?->`) — silent skip if writer dependency is null.
- **D-11:** Failures caught + logged via `pushLog` — never re-thrown.

**Writer extension surface:**
- **D-12:** Three new public methods on `TaskDirectoryWriterService`: `writeRunStatus`, `writeRunOutcome`, `writeRunBlockedIfNotTerminal`. Existing 4 methods unchanged.
- **D-13:** New private `runDir($repoSlug, $taskId, $runId)` helper. Reuse existing `atomicWrite()` primitive.
- **D-14:** Writer remains silent — no `pushLog`/`progressCallback` inside.

**JSONL log untouched:**
- **D-15:** `app/Support/RunLogStore.php` MUST NOT be modified. Verified by `git diff` negative-assertion in test plan.

**PHPStan cleanup:**
- **D-16:** Fix the 6 pre-existing errors in this phase, plan-ordered before the writer/test work. If any one error is structural (>2h), carve out to follow-up. Per this research: **none are structural** — all 6 are mechanical.
- **D-17:** PHPStan child-process OOM solved via `--memory-limit=512M` flag in a documented composer script or Makefile target.

**Test scope:**
- **D-18:** Writer-only comprehensive coverage. 11+ axes documented in CONTEXT.md. Test file: `tests/Feature/TaskDirectoryWriterServiceTest.php` (expanded from Phase 20's 1-test smoke).
- **D-19:** No orchestrator integration tests in Phase 21. Phase 22 E2E covers integration.

**Plan decomposition guidance (advisory):**
- **D-20:** Likely 3-plan phase. Dependency arrow `21-02 → 21-03` is fixed. Planner may merge 21-01 into 21-02 if small enough.

### Claude's Discretion

- Exact PHPStan invocation choice (composer script vs Makefile vs `phpstan.neon` config tweak)
- Whether `outcome.md`'s body includes a per-stage usage table or stays frontmatter-only
- Whether to introduce a `RunArtifactPayload` data class for `writeRunOutcome`'s array argument or keep `array $payload`
- Test organization: one big `TaskDirectoryWriterServiceTest.php` file vs split — both acceptable
- Optional `blocked_reason` frontmatter key in per-run `status.md` when finally arm fires from a caught exception

### Deferred Ideas (OUT OF SCOPE)

- TaskLoader extension to render `outcome.md` — Phase 22 / v2.1
- Orchestrator-level integration tests — Phase 22 E2E
- `merged` state writes — v2.1 (would require PR-merge polling)
- PR-merge polling — v2.1
- Stale run-dir cleanup / TTL — future operator UX phase
- Concurrent-run safety (PID locking) — atomic rename + distinct timestamp run IDs is sufficient for v2.0
- Console write actions from Godot — out-of-scope ceiling

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TASK-03 | Each run writes a per-run subdirectory `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` with PR URL (or structured failure reason) and final cost summary | Verified at §Outcome.md Mapping Table — all 9 frontmatter keys map cleanly from the existing `payloadFromResult` / `partialPayload` shapes. Writer surface defined per D-12. |
| TASK-04 | Existing `~/.copland/logs/runs.jsonl` keeps working unchanged | Negative assertion — `app/Support/RunLogStore.php` is not in the planner's modified-files list. No code in Phase 21 touches the JSONL writer. CI assertion: `git diff phase/main -- app/Support/RunLogStore.php` returns empty. |
| TASK-05 | Pest tests use a temporary `HOME` so no developer-machine state is touched | Verified at §Pest Temp-HOME Pattern — established 4-line idiom is used by every existing HOME-writing test (`RunLogStoreTest`, `PlanArtifactStoreTest`, `GlobalConfigTest`, the Phase 20 smoke test itself). Phase 21 extends the pattern. |

## Project Constraints (from CLAUDE.md)

- **Tech stack locked:** PHP 8.2+ / Laravel Zero — no language migration.
- **Auth:** Use `gh` CLI — not touched by Phase 21.
- **Safety:** All executor tool calls policy-validated — not touched.
- **Scope cap:** Max 3 files / 250 lines changed per issue (this is the per-PR ceiling Copland enforces on its OWN agents, not on humans planning Phase 21; the plan-ordered breakdown D-20 keeps each plan within the cap anyway).
- **Conventions:** PascalCase service classes (`TaskDirectoryWriterService` already conforms). RuntimeException for filesystem failures. Constructor injection for dependencies. Explicit `void` returns. Type hints everywhere. Throw with descriptive messages.
- **Tool:** Laravel Pint for code style. Run `./vendor/bin/pint` before commit.
- **GSD Workflow Enforcement:** All Phase 21 edits go through `/gsd:execute-phase`.

## PHPStan Error Catalog

PHPStan invocation (verified): `./vendor/bin/phpstan analyse --memory-limit=512M --no-progress`. Default `--memory-limit=128M` runs out of memory in a parallel child process; CONTEXT.md D-17 addresses this. Exit count: **6 errors**, **0 warnings**.

| # | File | Line | Rule ID | Error | Scope | Remediation |
|---|------|------|---------|-------|-------|-------------|
| 1 | `app/Services/ClaudePlannerService.php` | 102 | `return.unusedType` | `usageFromResponse()` declares `?ModelUsage` but never returns null. | Mechanical — 1 line | Change return type from `?ModelUsage` to `ModelUsage`. Inspected `AnthropicCostEstimator::forModel()` (`app/Support/AnthropicCostEstimator.php:9`) — signature `: ModelUsage` is non-nullable. Caller's nullable annotation is incorrect. |
| 2 | `app/Services/ClaudeSelectorService.php` | 82 | `return.unusedType` | Same as #1 — `usageFromResponse()` declares `?ModelUsage` but `AnthropicCostEstimator::forModel()` always returns `ModelUsage`. | Mechanical — 1 line | Same fix as #1: drop the `?`. The two services share an identical helper pattern. |
| 3 | `app/Services/RunOrchestratorService.php` | 317 | `notIdentical.alwaysTrue` | `if (isset($workspacePath) && $workspacePath !== null)` — when `isset()` is true, the string-typed variable can't be null. | Mechanical — 1 line | Drop the redundant `!== null` clause. New form: `if (isset($workspacePath))`. `$workspacePath` is assigned from `$this->workspace->create(...)` at line 183 which returns `string`. The `isset()` check alone is sufficient (it short-circuits when the `try` block crashed before line 180). |
| 4 | `app/Services/RunOrchestratorService.php` | 319 | `variable.undefined` | `$repoPath` may not be defined when execution reaches the `finally`-arm cleanup. | Mechanical — 1 line | Initialize `$repoPath = null;` at the top of `run()` (alongside the existing `$result = null;` / `$selectedIssue = null;` / `$caught = null;` initializers at lines 39-42). Then the existing `isset($workspacePath)` guard (after fix #3) already implies `$repoPath` was set, since `$repoPath` is assigned on line 179 — BEFORE `$workspacePath` is assigned on line 183. After fix #3, the line-317 guard is sound. Alternative: add a defensive `$repoPath = $repoPath ?? ($repoProfile['repo_path'] ?? getcwd());` before line 319. **Recommended: top-of-function initialization** — matches the existing pattern at lines 39-42 and keeps the finally arm readable. |
| 5 | `app/Services/RunOrchestratorService.php` | 328 | `variable.undefined` | `$writerRepoSlug` may not be defined when the `finally`-arm reaches the blocked-write call. | Mechanical — 1 line | Same fix-shape as #4: initialize `$writerRepoSlug = null;` at the top of `run()`. Then update the line-326 guard to: `if ($this->taskWriter !== null && $selectedIssue !== null && $writerRepoSlug !== null)`. The third clause is logically redundant after Phase 20 (because `$writerRepoSlug` is set on line 113 BEFORE `$selectedIssue` is set into the writer at line 117, AND `$selectedIssue` is set on line 82 — so `$selectedIssue !== null` already implies the surrounding code ran past line 82, but NOT past line 113 if the foreach failed; however `$writerRepoSlug` is set at line 113 only AFTER the `$selectedIssue === null` early-return at line 87). PHPStan sees the loop-set-then-finally flow as potentially-undefined and is correct from a strict-narrowing perspective. Add the explicit guard. |
| 6 | `app/Support/HomeDirectory.php` | 31 | `isset.offset` | `isset($pwinfo['dir'])` — `dir` is a guaranteed offset on the array shape returned by `posix_getpwuid()`. | Mechanical — 1 line | Drop the `isset()` clause; keep only the `$pwinfo['dir'] !== ''` check (and ensure `$pwinfo` itself is verified `is_array`, which the surrounding `if (is_array($pwinfo) && ...)` already does). New form: `if (is_array($pwinfo) && $pwinfo['dir'] !== '')`. PHPStan trusts PHP's stub that `posix_getpwuid()` returns the canonical 7-key array on success (`false` on failure, which the `is_array()` check filters). |

**Total fix surface:** ~6-8 lines (3 in `RunOrchestratorService.php`, 1 each in the two Claude services, 1 in `HomeDirectory.php`, plus ~2-3 lines of variable initialization at the top of `run()`).

**Scope classification:** **All 6 are mechanical.** None require structural changes. Largest blast radius is fix #5 which adds an extra clause to one `if` statement. **No carve-out to a follow-up phase needed.** SC4 ("PHPStan level 5 stays clean") is fully achievable in Plan 21-01.

**Verification post-fix:** `./vendor/bin/phpstan analyse --memory-limit=512M --no-progress` should report `[OK] No errors`. The new test plan (21-03) should include this command in the documented per-wave verification command set (parallel to `./vendor/bin/pest`).

**Per D-17, OOM fix:** Add `composer phpstan` script entry pointing at `vendor/bin/phpstan analyse --memory-limit=512M` (simplest; matches `composer test` style if present) OR set `parameters.tmpDir` or lower `parameters.parallel.maximumNumberOfProcesses` in `phpstan.neon`. **Recommended: composer script.** Existing `phpstan.neon` is minimal (5 lines, two `parameters`), and a composer script entry is the most discoverable for humans + CI.

## Outcome.md Mapping Table

The orchestrator already has two private mappers (lines 347-405 of `RunOrchestratorService.php`):

- `payloadFromResult(string $repo, RunResult $result): array` — produces the JSONL row for successful runs (`status` ∈ `{succeeded, failed, skipped}`, `partial: false`).
- `partialPayload(string $repo, ?array $selectedIssue, ?RunProgressSnapshot $snapshot, string $startedAt, ?Throwable $caught): array` — produces the JSONL row for crashes (`status: 'crashed'`, `partial: true`).

CONTEXT.md D-09 says a new `outcomePayload()` helper distills these into the 9-key outcome.md frontmatter shape. Mapping per key:

| outcome.md key | Source field (successful run / `payloadFromResult`) | Source field (crashed run / `partialPayload`) | Notes |
|----------------|------------------------------------------------------|------------------------------------------------|-------|
| `run_id` | NEW — passed in by orchestrator from the `$runId` local | Same | Not present in either existing payload. Orchestrator passes it directly into `outcomePayload($runId, ...)` or attaches to result. |
| `status` | `$result->status` (line 355: `'status' => $result->status`) → values: `'succeeded' \| 'failed' \| 'skipped'` | `'crashed'` (hardcoded line 387) | Per D-05, valid values are `pr_open \| blocked \| crashed`. **Need a small mapping**: `succeeded` → `pr_open`, `failed`/`skipped` → `blocked`, `crashed` → `crashed`. Keep the mapping inside `outcomePayload()`. |
| `pr_number` | `$result->prNumber` (line 361, nested `pr.number`) | `null` (hardcoded line 393) | Flatten `pr.number` → `pr_number`. Already `?int`. |
| `pr_url` | `$result->prUrl` (line 362, nested `pr.url`) | `null` (hardcoded line 394) | Flatten `pr.url` → `pr_url`. Already `?string`. D-05 specifies `""` when absent (matches the Phase 20 source_url empty-string invariant). Cast: `(string) ($prUrl ?? '')`. |
| `cost_usd` | `usage.total.estimated_cost_usd` via `AnthropicCostEstimator::combine($result->selectorUsage, $result->plannerUsage, $result->executorUsage)?->estimatedCostUsd` (line 369-373) | Same combine, on `$snapshot?->selectorUsage` / `plannerUsage` / `executorUsage` (line 401-405) | `combine(...)` returns `?ModelUsage` (nullable when all inputs are null — e.g., a crash before any stage ran). **Default to `0.0`** when the result is null. Type: `float`. Per D-05 it's numeric (not stringified). |
| `started_at` | `$result->startedAt` (line 357) — ISO via `date(DATE_ATOM)` set at line 38 | `$startedAt` parameter, also ISO via `date(DATE_ATOM)` | Both are `DATE_ATOM`-format (`2026-05-27T19:15:22+00:00`). **Format mismatch alert**: this is `DATE_ATOM` (with offset), not the writer's `gmdate('Y-m-d\TH:i:s\Z')` (with literal `Z`). D-05 example shows `Z` form. **Resolution**: inside `outcomePayload()`, normalize to the `Z` form via `gmdate('Y-m-d\TH:i:s\Z', strtotime($startedAt))` (parses any RFC3339, emits Z-form). |
| `finished_at` | `$result->finishedAt` (line 358) — `DATE_ATOM` set at lines 73, 97, 140, 167, 216, 251, 302 | `date(DATE_ATOM)` at the moment the finally arm runs (line 390) | Same normalization concern as `started_at`. Same `gmdate(...)` round-trip. |
| `failure_reason` | `$result->failureReason` (line 359) — `?string` | `$caught?->getMessage()` (line 391) — `?string` | Cast to `string` (empty when null), matching D-05's `""` empty-string default. |
| `partial` | `false` (hardcoded line 356) | `true` (hardcoded line 388) | Already present in both payload shapes; `partial: true|false` maps **cleanly** — no transformation needed. |

**Body section (Claude's discretion per D-05):** A small markdown table below `---` rendering per-stage `model + tokens + cost` from `usage.selector`, `usage.planner`, `usage.executor`. Recommended: include it. Useful for human grep; TaskLoader's parser ignores everything below the closing `---`.

**Mapper signature recommendation:**

```php
private function outcomePayload(
    string $runId,
    ?RunResult $result,
    ?array $partialPayload,           // already-built partialPayload(...) or null
    string $startedAt,
    ?Throwable $caught,
): array
```

In the orchestrator's finally-arm (line 334-343), call sequence becomes:

```php
$payload = $result instanceof RunResult
    ? $this->payloadFromResult($repo, $result)
    : $this->partialPayload($repo, $selectedIssue, $snapshot, $startedAt, $caught);

$path = $runLogStore->append($payload);  // unchanged — TASK-04 invariant
$this->pushLog("      Appended run log to {$path}");

// NEW: outcome.md write (D-09, D-10, D-11)
if ($this->taskWriter !== null && $selectedIssue !== null && $writerRepoSlug !== null && $runId !== null) {
    try {
        $outcome = $this->outcomePayload($runId, $result, $payload, $startedAt, $caught);
        $this->taskWriter->writeRunOutcome($writerRepoSlug, $selectedIssue['number'], $runId, $outcome);
    } catch (Throwable $e) {
        $this->pushLog("      Warning: outcome write failed: {$e->getMessage()}");
    }
}
```

**Ambiguity flag:** the `partial` flag is *always* available in the existing payload — pass `$payload['partial']` into the outcome.md frontmatter rather than re-deriving from `$result instanceof RunResult`. This collapses two source-of-truth paths into one.

**No new data class needed.** The `array` shape is internal to `RunOrchestratorService` + `TaskDirectoryWriterService::writeRunOutcome($repoSlug, $taskId, $runId, array $outcome)`. Matches the existing `RunLogStore::append(array $payload)` convention.

## Pest Temp-HOME Pattern

**Verified by reading 7 existing test files** (`RunLogStoreTest`, `PlanArtifactStoreTest`, `GlobalConfigTest`, `ClaudeServicesTest`, `AutomateCommandTest`, the Phase 20 `TaskDirectoryWriterServiceTest`). The pattern is unambiguous:

### Canonical idiom (4 lines top + 1 line tail)

```php
it('writes <thing> under a temporary HOME', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-<test-slug>-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    // ... arrange / act / assert ...

    $_SERVER['HOME'] = $originalHome;
});
```

### Convention details

| Element | Convention | Source |
|---------|------------|--------|
| Temp-dir prefix | `sys_get_temp_dir() . '/copland-<test-slug>-'` | RunLogStoreTest:8, PlanArtifactStoreTest:8/51, TaskDirectoryWriterServiceTest:7, all 11 GlobalConfigTest cases |
| Uniqueness | `uniqid()` (no `more_entropy` flag) | All HOME-using tests |
| Slug separator | hyphen-delimited descriptive string (`copland-task-writer-`, `copland-run-log-`, `copland-plan-artifacts-`) | Every existing case |
| `mkdir` mode | `0755`, recursive `true` | All cases |
| Restore | Inline at end of `it()` closure | All cases |
| `beforeEach`/`afterEach` | **NOT USED for HOME-swapping.** Only `RunOrchestratorServiceTest` and `AsanaTaskSourceTest`/`GitHubTaskSourceTest` use `afterEach` — for `Mockery::close()`, not HOME cleanup. | tests/Unit/RunOrchestratorServiceTest:24-26 |
| TestCase trait | `tests/Pest.php:16` binds `TestCase` to `Feature/` only. `Unit/` uses bare PHPUnit. The temp-HOME pattern works identically in both. | tests/Pest.php, tests/TestCase.php |
| Directory cleanup at test end | **Not done.** Tests leave the temp dir in `sys_get_temp_dir()` — OS-level cleanup handles it. No `rmdir` / recursive-rm at tail. | All cases |

### Per-test-file structure recommendation

CONTEXT.md D-18 lists 11 axes. Two organizational options:

1. **One big file** (`tests/Feature/TaskDirectoryWriterServiceTest.php`, expanded from 1 test to ~12-18): each `it()` is independent, each opens its own temp HOME. **Idiomatic with existing patterns.** GlobalConfigTest already has 11 `it()` cases in one file using exactly this idiom. Recommended.
2. **Split files** (`writer-task-test.php`, `writer-run-test.php`, `writer-blocked-test.php`): cleaner index, slightly more boilerplate. Acceptable per CONTEXT.md.

**Recommendation: one big file.** Mirrors GlobalConfigTest's 268-line organization (11 independent `it()` cases, each opens its own temp HOME).

### Helper extraction (optional)

If the boilerplate becomes repetitive, a small top-of-file helper:

```php
function makeTempHome(string $slug): string {
    $home = sys_get_temp_dir().'/copland-'.$slug.'-'.uniqid();
    mkdir($home, 0755, true);
    return $home;
}
```

…and use:

```php
it('...', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $home = makeTempHome('task-writer-status');
    // ...
    $_SERVER['HOME'] = $originalHome;
});
```

**Recommendation: skip the helper.** The 4-line boilerplate is fast to read, fast to write, and consistent with the rest of the test suite. Don't introduce a helper for ~12 tests.

### Clock seam usage (verified)

Phase 20's writer accepts `?callable $clock = null` (`app/Services/TaskDirectoryWriterService.php:13`). The Phase 20 smoke test uses it:

```php
$writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T08:14:01Z');
```

For Phase 21 tests that need to assert distinct timestamps (e.g., the transitions-table append-only test), increment a counter inside the closure:

```php
$times = ['2026-05-27T08:14:01Z', '2026-05-27T08:14:02Z', '2026-05-27T08:14:03Z'];
$i = 0;
$writer = new TaskDirectoryWriterService(clock: function () use (&$times, &$i) {
    return $times[$i++] ?? end($times);
});
```

This pattern is sufficient for all D-18 axes — no need to introduce Carbon test-time freezing or Mockery clock mocks.

### `$homeOverride` constructor seam (alternative)

The writer also accepts `?string $homeOverride = null` (`app/Services/TaskDirectoryWriterService.php:14`). For tests that prefer not to mutate `$_SERVER['HOME']`:

```php
$home = sys_get_temp_dir().'/copland-task-writer-'.uniqid();
mkdir($home, 0755, true);
$writer = new TaskDirectoryWriterService(homeOverride: $home);
// ... no $_SERVER mutation, no restore at tail ...
```

**Both patterns are acceptable per D-13.** The existing Phase 20 smoke test uses `$_SERVER['HOME']` for consistency with `RunLogStoreTest`. Phase 21's expanded suite can use either; the `$_SERVER['HOME']` form pairs better with the existing convention.

## Confirmation Checks

### Secondary check 1: timestamp format
Verified by running `php -r 'echo gmdate("Y-m-d\TH:i:s\Z") . PHP_EOL;'`:

```
2026-05-27T12:18:18Z
```

After `str_replace(':', '-', ...)`:

```
2026-05-27T12-18-18Z
```

Matches D-01 exactly: `2026-05-27T19-15-22Z` is the same shape. **No format ambiguity.** Lexicographic sort = chronological (digits and dashes only, fixed width).

### Secondary check 2: writer accepts `?callable $clock`
Verified at `app/Services/TaskDirectoryWriterService.php:12-15`:

```php
public function __construct(
    private $clock = null,
    private ?string $homeOverride = null,
) {}
```

Note: `private $clock = null` has no type hint (matches `GitService($runner)` pattern). PHPDoc-style or actual `?callable` would be tighter but the existing form is consistent. **Acceptable for `$runId` deterministic derivation.** Test pattern:

```php
$writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T19:15:22Z');
// Orchestrator can call writer's clock to derive runId — but per D-02 the
// orchestrator does the str_replace itself; tests injecting orchestrator-side
// must mock either the writer's clock OR pass $runId explicitly.
```

For Phase 21 testing the **writer-only** surface, `$runId` is a parameter on each new method (D-12) — tests pass it directly: `$writer->writeRunStatus('owner/repo', 42, '2026-05-27T19-15-22Z', 'planning')`. No clock-coordination needed in the test layer.

### Secondary check 3: orchestrator control flow path (line 113 → line 279)

Verified by reading `app/Services/RunOrchestratorService.php` lines 113-279. **Pathway is clean:**

- Line 113-115: `$writerRepoSlug` derived once (`AsanaTaskSource` branch). **Note:** Per the PHPStan finding #5, `$writerRepoSlug` IS set inside the `try` block, AFTER `$selectedIssue` is non-null. If exception fires before line 113, `$writerRepoSlug` is undefined — PHPStan correctly flags this for the finally arm.
- Line 117-119: First three writer calls — `writeNewTask`, two `writeStatus`. Nullsafe `?->`.
- Line 123: `writeStatus('planning')`.
- Line 130-146: Early return for `decline` decision. Returns `$result`. Falls through to finally arm — `$writerRepoSlug` IS set in this path.
- Line 154-173: Early return for validation failure. Returns `$result`. Same.
- Line 176: `writeStatus('planned')`.
- Line 188: `writeStatus('executing')`.
- Line 200-224: Early return for executor failure. Returns `$result`. Same.
- Line 228: `writeStatus('verifying')`.
- Line 231-258: Early return for verification failure. Returns `$result`. Same.
- Line 279: `writeStatus('pr_open')` — final state write.

**Early-return findings:** Every `return $result;` in the success-flow `try` block lands in the same finally arm (lines 316-344). The new `$runId` (derived once at line ~113, immediately after or before `$writerRepoSlug`) is in-scope for every path. **No early returns skip the per-run writes** — they're inserted at exactly the same call sites as the existing task-level writes. The pattern is "paired adjacent" per D-06.

### Secondary check 4: terminal-finally block structure

Verified by reading `app/Services/RunOrchestratorService.php` lines 311-345. **Exactly one `try/catch/finally` covers the entire run.** Structure:

```
try { ... entire run body, lines 48-309 ... }
catch (Throwable $e) { $caught = $e; throw; }  // lines 311-315
finally {                                       // lines 316-344
    // (1) workspace cleanup       lines 317-324
    // (2) blocked write           lines 326-332  ← Phase 20 added
    // (3) JSONL append            lines 334-343  ← D-09 outcome.md write goes adjacent
}
```

**JSONL append at line 339 IS inside the finally block.** D-09 says `outcome.md` is written "from the orchestrator's existing terminal-finally block at the same point `RunLogStore::append()` is currently called." The insertion point is unambiguous: between line 340 (after `pushLog("Appended run log to ...")`) and line 341 (the catch). Or co-located inside the same try/catch as a sibling write. **Recommended: separate try/catch** so a writer failure doesn't mask a JSONL failure or vice-versa — matches the existing pattern of three independent try/catch arms.

## Open Questions

None. All three primary deliverables (PHPStan catalog, outcome mapping, Pest pattern) and all four secondary checks are verified against source with HIGH confidence.

Minor design discretion remaining for the planner:

1. Whether to thread `$runId` as a `run()` parameter (testable) or derive it in-method via the writer's clock seam (less plumbing). Per D-02 — orchestrator-owned, but the orchestrator can call `gmdate(...)` directly, no writer involvement needed for `$runId` derivation. Recommended: derive inline via `gmdate('Y-m-d\TH:i:s\Z')` + `str_replace(':', '-', ...)` at line ~113. Tests can use Mockery to mock the orchestrator's runtime if needed — but Phase 21 D-19 forbids orchestrator integration tests, so this is moot.

2. Whether `outcomePayload()` accepts the already-built `$payload` array or rebuilds from `$result` + `$snapshot` + `$caught`. **Recommended: accept `$payload`** to keep the JSONL row as single source of truth for cross-cutting fields (`status`, `partial`, `started_at`, `finished_at`, `failure_reason`).

## Environment Availability

> Phase 21 has no external dependencies. Section included for completeness.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All code | ✓ | `^8.2` per composer.json | none — hard requirement |
| PHPStan | Lint gate (SC4) | ✓ (already in dev deps) | per `composer.lock` | none |
| Pest | Test suite (TASK-05) | ✓ (already in tree) | per `composer.lock` | none |
| `--memory-limit=512M` PHPStan flag | OOM avoidance per D-17 | ✓ (PHPStan CLI flag) | builtin | composer-script wrapping |
| `App\Services\TaskDirectoryWriterService` | Phase 21 extension target | ✓ | in-tree from Phase 20 | n/a |
| `App\Support\HomeDirectory` | Test pattern | ✓ | in-tree | n/a |

**Missing dependencies with no fallback:** None.
**Missing dependencies with fallback:** None.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 3.x/4.x (PHPUnit-based) — already in tree |
| Config file | `tests/Pest.php` (uses `TestCase` in Feature only), `phpunit.xml` (Laravel Zero) |
| Quick run command | `./vendor/bin/pest --filter=TaskDirectoryWriter` (< 2s) |
| Full suite command | `./vendor/bin/pest` (full suite; 132+ tests baseline) |
| PHPStan command | `./vendor/bin/phpstan analyse --memory-limit=512M --no-progress` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TASK-03 | `writeRunStatus()` + `writeRunOutcome()` + `writeRunBlockedIfNotTerminal()` produce correct files under `~/.copland/tasks/<repo>/<id>/runs/<run-id>/` | unit (Feature) | `./vendor/bin/pest tests/Feature/TaskDirectoryWriterServiceTest.php` | ✅ exists (1 smoke test); expand to 12-18 |
| TASK-04 | `RunLogStore::append()` unchanged | negative-assertion | `git diff main -- app/Support/RunLogStore.php` returns empty | n/a — phase-gate verification |
| TASK-05 | Pest tests use temporary HOME (no developer-machine state touched) | structural review | code review of Plan 21-03 implementation | n/a — design constraint |
| SC4 | PHPStan level 5 reports 0 errors | lint gate | `./vendor/bin/phpstan analyse --memory-limit=512M --no-progress` exits 0 | ✅ executable today, currently 6 errors |

### Sampling Rate
- **Per task commit:** `./vendor/bin/pest --filter=TaskDirectoryWriter` (writer-focused, < 2s)
- **Per wave merge:** `./vendor/bin/pest` (full suite) AND `./vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
- **Phase gate:** Full suite green; PHPStan level 5 = 0 errors; `git diff` does not touch `app/Support/RunLogStore.php`

### Wave 0 Gaps
- [ ] `tests/Feature/TaskDirectoryWriterServiceTest.php` — extend from 1 smoke test to 12-18 tests covering all 11 D-18 axes
- [ ] `composer.json` script entry `"phpstan": "vendor/bin/phpstan analyse --memory-limit=512M"` (or Makefile target) per D-17
- [ ] *(nothing else missing — Pest + PHPStan are already installed; the temp-HOME pattern is established)*

## Security Domain

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — (writer is offline) |
| V3 Session Management | no | — |
| V4 Access Control | partially | Per-run dirs inherit Phase 20's `0755` directory mode. Same recommendation: consider `0700` for `tasks/` root if outcome.md is judged to contain sensitive info (PR URLs and cost data are not strongly sensitive). Defer to Phase 22 / v2.1. |
| V5 Input Validation | yes | `$runId` is orchestrator-derived via `gmdate(...) + str_replace`; the only injection vector is the title/body content captured into `outcome.md`'s `failure_reason` (caught exception messages). Same hand-rolled-YAML escaping (`\\` and `\"`) already in the writer at line 131 of `TaskDirectoryWriterService.php` covers this. |
| V6 Cryptography | no | — |

### Known Threat Patterns for PHP filesystem writes (Phase 21 deltas vs. Phase 20)

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Path traversal via `$runId` | Tampering | `$runId` shape is fully constrained by `gmdate('Y-m-d\TH:i:s\Z')` + `str_replace(':', '-', …)`. No user input enters `$runId`. The format is fixed: 20 digits/dashes/T/Z. No traversal risk. |
| `outcome.md` writes race with `status.md` writes | Tampering | Both use the existing `atomicWrite()` primitive (D-13). Same-directory tmp+rename is POSIX-atomic. Reader (TaskLoader) never observes partial state. |
| Outcome.md failure-reason text injection | Tampering | The hand-rolled YAML renderer at `TaskDirectoryWriterService.php:126-136` escapes `\\` and `\"` in scalar values. Exception messages with embedded quotes / backslashes are safe. |
| Long-running-process file descriptor leak | DoS | Phase 21 writes one new file per run (`outcome.md`) plus `status.md` updates. Atomic write closes the fd before rename. No leak risk. |

No new ASVS exposure beyond Phase 20.

## Sources

### Primary (HIGH confidence)
- `/Users/garykovar/projects/codeable/copland/.planning/phases/21-per-run-artifacts-test-coverage/21-CONTEXT.md` — 20 locked decisions
- `/Users/garykovar/projects/codeable/copland/.planning/phases/20-task-status-writer/20-CONTEXT.md` — inherited Phase 20 decisions D-01..D-17
- `/Users/garykovar/projects/codeable/copland/.planning/phases/20-task-status-writer/20-RESEARCH.md` — verified line-numbered insertion points (still applicable post-Phase-20 implementation; new line numbers verified by direct read)
- `/Users/garykovar/projects/codeable/copland/.planning/REQUIREMENTS.md` — TASK-03, TASK-04, TASK-05 full text + Out of Scope
- `/Users/garykovar/projects/codeable/copland/.planning/ROADMAP.md` — Phase 21 goal + 4 success criteria (esp. SC4)
- `/Users/garykovar/projects/codeable/copland/app/Services/RunOrchestratorService.php` — full read (419 lines): constructor at lines 19-32 (writer dep already wired); `run()` at lines 34-345 (8 writer call sites verified at 117/118/119/123/176/188/228/279; finally block verified at 316-344; JSONL append at 339); `payloadFromResult` at 347-377; `partialPayload` at 379-409
- `/Users/garykovar/projects/codeable/copland/app/Services/TaskDirectoryWriterService.php` — full read (161 lines): all 4 existing methods + `atomicWrite()` private; clock + homeOverride seams verified at lines 12-15
- `/Users/garykovar/projects/codeable/copland/app/Support/RunLogStore.php` — full read (66 lines); `append()` at lines 10-25, `normalizeValue()` at 43-65; path at line 29: `'/.copland/logs/runs.jsonl'`
- `/Users/garykovar/projects/codeable/copland/app/Support/HomeDirectory.php` — line 31 `isset()` redundancy verified
- `/Users/garykovar/projects/codeable/copland/app/Services/ClaudePlannerService.php:102` — `?ModelUsage` annotation verified vs. `AnthropicCostEstimator::forModel(): ModelUsage` non-nullable return
- `/Users/garykovar/projects/codeable/copland/app/Services/ClaudeSelectorService.php:82` — same pattern as above
- `/Users/garykovar/projects/codeable/copland/app/Support/AnthropicCostEstimator.php:9` — `forModel(): ModelUsage` non-nullable confirmed
- `/Users/garykovar/projects/codeable/copland/tests/Feature/TaskDirectoryWriterServiceTest.php` — 53 lines, the Phase 20 smoke test
- `/Users/garykovar/projects/codeable/copland/tests/Unit/RunLogStoreTest.php` — 55 lines, canonical temp-HOME pattern reference
- `/Users/garykovar/projects/codeable/copland/tests/Unit/PlanArtifactStoreTest.php` — 87 lines, two-test temp-HOME pattern
- `/Users/garykovar/projects/codeable/copland/tests/Unit/GlobalConfigTest.php` — 11 `it()` cases with same idiom (verified via grep)
- `/Users/garykovar/projects/codeable/copland/tests/Pest.php`, `tests/TestCase.php` — base setup minimal (no global beforeEach/afterEach for HOME)
- `/Users/garykovar/projects/codeable/copland/phpstan.neon` — level 5, paths `app`, exclude `vendor`
- PHPStan invocation: `./vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — exit 1, 6 errors with rule IDs verified
- Timestamp verification: `php -r 'echo gmdate("Y-m-d\TH:i:s\Z");'` returns `2026-05-27T12:18:18Z`

### Secondary (MEDIUM confidence)
- None — all assertions in this research are HIGH-confidence reads of source.

### Tertiary (LOW confidence)
- None.

## Confidence Assessment

| Area | Level | Reason |
|------|-------|--------|
| PHPStan error catalog | HIGH | All 6 errors enumerated directly from PHPStan output. Remediation verified by reading the affected source lines + verifying the underlying type contract (`AnthropicCostEstimator::forModel(): ModelUsage` non-nullable, `posix_getpwuid()` PHP stub guarantees `dir` offset). Zero estimated risk of carve-out. |
| Outcome.md mapping | HIGH | Both `payloadFromResult` and `partialPayload` read in full; every D-05 key mapped to a specific source line. Two minor transformations identified explicitly (timestamp normalize from `DATE_ATOM` to `Z`-form; null-coalesce `cost_usd` to `0.0`). Status-value mapping (`succeeded` → `pr_open`, etc.) is a 4-line switch inside `outcomePayload()` — trivial. |
| Pest temp-HOME pattern | HIGH | 7 distinct test files exhibit the exact same 4-line idiom. No competing pattern. Phase 20 already follows it. |
| Secondary checks (timestamp, control flow, finally structure, clock seam) | HIGH | All verified via direct source read and one `php -r` execution. |

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; PHP-builtin + PHPStan + Pest already in tree.
- Architecture: HIGH — Phase 20 wired the writer dep; Phase 21 just extends the existing surface and threads `$runId` through. No structural changes.
- Pitfalls: HIGH — Phase 20's pitfalls (Asana `html_url` absent, GitHub null `body`, frontmatter delimiter discipline, cross-mount rename) all apply unchanged. No new pitfalls surfaced by per-run extension.

**Research date:** 2026-05-27
**Valid until:** 2026-06-26 (~30 days). Risk of invalidation: a Phase 22 refactor of the orchestrator finally block would invalidate the line numbers but not the design.

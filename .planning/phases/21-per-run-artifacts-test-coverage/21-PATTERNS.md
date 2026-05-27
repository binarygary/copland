# Phase 21: Per-Run Artifacts & Test Coverage - Pattern Map

**Mapped:** 2026-05-27
**Files analyzed:** 4 (3 modified, 0 new files; 1 config tweak)
**Analogs found:** 4 / 4 (every analog is in-tree — Phase 21 is pure extension of Phase 20)

## File Classification

| Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---------------|------|-----------|----------------|---------------|
| `app/Services/TaskDirectoryWriterService.php` (EXTEND with 3 new methods) | service | file-I/O (write to `~/.copland/.../runs/<run-id>/`) | itself, lines 42-80 (existing `writeStatus` + `writeBlockedIfNotTerminal`) | exact self-analog |
| `app/Services/RunOrchestratorService.php` (thread `$runId` + paired writes + outcome.md) | orchestrator | request-response with try/catch/finally | itself, lines 113-119 (existing `$writerRepoSlug` derivation + paired writes) and lines 326-343 (existing finally arms) | exact self-analog |
| `tests/Feature/TaskDirectoryWriterServiceTest.php` (expand from 1 to ~12-18 it-blocks) | test | temp-HOME smoke + comprehensive coverage | itself (Phase 20 single-it shape) + `tests/Unit/GlobalConfigTest.php` (11-case organization analog) | exact |
| `phpstan.neon` (no shape change; memory-limit fix lands in `composer.json` script per D-17) | config | static-analysis gate | itself (verified shape: 5 lines, `level: 5`, `paths: app`) | no analog needed |

---

## Pattern Assignments

### `app/Services/TaskDirectoryWriterService.php` — three new public methods

The writer already has all primitives (`taskDir`, `atomicWrite`, `ensureDirectoryExists`, `renderFrontmatter`, `now`, `extractBody`, `$lastState` map). The new methods reuse them verbatim.

#### Method 1 — `writeRunStatus(...)` — analog is the existing `writeStatus()`

**Analog excerpt** (verbatim from `app/Services/TaskDirectoryWriterService.php:42-69`):

```php
public function writeStatus(string $repoSlug, string|int $taskId, string $state): void
{
    $dir = $this->taskDir($repoSlug, $taskId);
    $this->ensureDirectoryExists($dir);

    $statusPath = $dir.'/status.md';
    $now = $this->now();

    $frontmatter = $this->renderFrontmatter([
        'state' => $state,
        'updated_at' => $now,
    ]);

    $newRow = "| {$now} | {$state} |\n";

    if (is_file($statusPath)) {
        $existing = (string) file_get_contents($statusPath);
        $body = $this->extractBody($existing).$newRow;
    } else {
        $body = "## Transitions\n\n| Timestamp (UTC)        | State     |\n|------------------------|-----------|\n".$newRow;
    }

    $content = "---\n{$frontmatter}---\n\n{$body}";

    $this->atomicWrite($statusPath, $content);

    $this->lastState["{$repoSlug}/{$taskId}"] = $state;
}
```

**Shape for `writeRunStatus`** (copy verbatim; the only deltas are signature, `$dir` resolver, and `$lastState` key):

```php
public function writeRunStatus(string $repoSlug, string|int $taskId, string $runId, string $state): void
{
    $dir = $this->runDir($repoSlug, $taskId, $runId);          // NEW helper (D-13)
    $this->ensureDirectoryExists($dir);

    $statusPath = $dir.'/status.md';
    $now = $this->now();

    $frontmatter = $this->renderFrontmatter([
        'state' => $state,
        'updated_at' => $now,
    ]);

    $newRow = "| {$now} | {$state} |\n";

    if (is_file($statusPath)) {
        $existing = (string) file_get_contents($statusPath);
        $body = $this->extractBody($existing).$newRow;
    } else {
        $body = "## Transitions\n\n| Timestamp (UTC)        | State     |\n|------------------------|-----------|\n".$newRow;
    }

    $content = "---\n{$frontmatter}---\n\n{$body}";

    $this->atomicWrite($statusPath, $content);

    $this->lastState["{$repoSlug}/{$taskId}/runs/{$runId}"] = $state;   // D-07: tuple key
}
```

**Required new private helper** (one-liner, paralleling `taskDir` at lines 91-97):

```php
private function runDir(string $repoSlug, string|int $taskId, string $runId): string
{
    return $this->taskDir($repoSlug, $taskId)."/runs/{$runId}";
}
```

#### Method 2 — `writeRunBlockedIfNotTerminal(...)` — analog is the existing `writeBlockedIfNotTerminal()`

**Analog excerpt** (verbatim from `app/Services/TaskDirectoryWriterService.php:71-80`):

```php
public function writeBlockedIfNotTerminal(string $repoSlug, string|int $taskId): void
{
    $current = $this->lastState["{$repoSlug}/{$taskId}"] ?? null;

    if ($current === null || $current === 'pr_open' || $current === 'blocked') {
        return;
    }

    $this->writeStatus($repoSlug, $taskId, 'blocked');
}
```

**Shape for `writeRunBlockedIfNotTerminal`** (D-07 keys the per-run lookup on the 3-tuple; delegates to `writeRunStatus`):

```php
public function writeRunBlockedIfNotTerminal(string $repoSlug, string|int $taskId, string $runId): void
{
    $current = $this->lastState["{$repoSlug}/{$taskId}/runs/{$runId}"] ?? null;

    if ($current === null || $current === 'pr_open' || $current === 'blocked') {
        return;
    }

    $this->writeRunStatus($repoSlug, $taskId, $runId, 'blocked');
}
```

#### Method 3 — `writeRunOutcome(...)` — analog is the existing `writeNewTask()` (single one-shot write at terminal state, no append)

**Analog excerpt** (verbatim from `app/Services/TaskDirectoryWriterService.php:17-40`):

```php
public function writeNewTask(
    string $repoSlug,
    string|int $taskId,
    ?string $title,
    ?string $body,
    string $repoPath,
    ?string $sourceUrl,
): void {
    $dir = $this->taskDir($repoSlug, $taskId);
    $this->ensureDirectoryExists($dir);

    $frontmatter = $this->renderFrontmatter([
        'id' => (string) $taskId,
        'title' => (string) ($title ?? ''),
        'repo_slug' => $repoSlug,
        'repo_path' => $repoPath,
        'source_url' => (string) ($sourceUrl ?? ''),
        'created_at' => $this->now(),
    ]);

    $content = "---\n{$frontmatter}---\n\n".((string) ($body ?? ''))."\n";

    $this->atomicWrite($dir.'/task.md', $content);
}
```

**Shape for `writeRunOutcome`** (D-05: 9 keys; body optional; one atomic write; signature per D-12):

```php
public function writeRunOutcome(string $repoSlug, string|int $taskId, string $runId, array $outcome): void
{
    $dir = $this->runDir($repoSlug, $taskId, $runId);
    $this->ensureDirectoryExists($dir);

    // The 9 D-05 keys (caller pre-builds via outcomePayload helper):
    //   run_id, status, pr_number, pr_url, cost_usd, started_at, finished_at, failure_reason, partial
    $frontmatter = $this->renderFrontmatter($outcome);

    $body = isset($outcome['_body']) ? (string) $outcome['_body'] : '';   // optional per-stage usage table
    unset($outcome['_body']);

    $content = "---\n{$frontmatter}---\n\n{$body}";

    $this->atomicWrite($dir.'/outcome.md', $content);
}
```

> Note: `renderFrontmatter` (lines 126-136) already coerces all values via `(string) $value` so numeric `cost_usd` / `pr_number` / boolean `partial` serialize correctly as quoted scalars — matches the existing TaskLoader.gd parser contract (top-level quoted scalars only).

#### Pattern reused — `renderFrontmatter` (verbatim, lines 126-136)

```php
private function renderFrontmatter(array $pairs): string
{
    $rendered = '';

    foreach ($pairs as $key => $value) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
        $rendered .= "{$key}: \"{$escaped}\"\n";
    }

    return $rendered;
}
```

**Applies to:** `writeRunStatus` (2 keys), `writeRunOutcome` (9 keys). No new renderer. RESEARCH §Outcome.md Mapping Table specifies the exact coercion: cast floats/ints/bools to string, escape `\\` and `"`.

#### Pattern reused — `atomicWrite` (verbatim, lines 113-124)

```php
private function atomicWrite(string $path, string $content): void
{
    $tmp = $path.'.tmp';

    if (file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("Failed to write {$tmp}");
    }

    if (! rename($tmp, $path)) {
        throw new RuntimeException("Failed to rename {$tmp} to {$path}");
    }
}
```

**Applies to:** both `writeRunStatus` and `writeRunOutcome` — no new write primitive (D-13).

---

### `app/Services/RunOrchestratorService.php` — `$runId` threading + paired writes + outcome.md

#### Pattern A — `$runId` derivation (analog: `$writerRepoSlug` derivation, lines 113-115)

**Analog excerpt** (verbatim from `app/Services/RunOrchestratorService.php:111-115`):

```php
// Per D-06: Asana sources use basename(repo_path) so the on-disk directory and
// the frontmatter repo_slug agree (no GH-style slash, no __ collapse needed).
$writerRepoSlug = $this->taskSource instanceof AsanaTaskSource
    ? basename($repoProfile['repo_path'])
    : $repo;
```

**Shape for `$runId`** (immediately adjacent — per CONTEXT.md §Code Touchpoints "derive `$runId` once after `$writerRepoSlug` derivation"):

```php
// Per D-01: Run ID is ISO-8601 UTC with colons -> dashes for POSIX safety.
// Generated exactly once per run() call. Lexicographic sort == chronological.
$runId = str_replace(':', '-', gmdate('Y-m-d\TH:i:s\Z'));
```

> Per RESEARCH §Open Questions item 1: derive inline via `gmdate(...)` + `str_replace(...)`. No writer-clock involvement at this site (writer's clock seam is for test-time mocking of the writer's own `now()` for `updated_at` / `created_at` — `$runId` is orchestrator-owned per D-02).

#### Pattern B — Initialize defensive nulls at top of `run()` (analog: existing init block, lines 39-42)

**Analog excerpt** (verbatim from `app/Services/RunOrchestratorService.php:36-42`):

```php
$this->log = [];
$this->progressCallback = $progressCallback;
$startedAt = date(DATE_ATOM);
$result = null;
$selectedIssue = null;
$runLogStore = $this->runLogStore ?? new RunLogStore;
$caught = null;
```

**Shape for added inits** (per RESEARCH PHPStan fix #4, #5 and Phase 21 `$runId` finally-arm guard):

```php
$result = null;
$selectedIssue = null;
$runLogStore = $this->runLogStore ?? new RunLogStore;
$caught = null;
$repoPath = null;                  // PHPStan fix #4
$workspacePath = null;             // already implicit via isset() guard at line 317; declare explicitly for fix #3
$writerRepoSlug = null;            // PHPStan fix #5
$runId = null;                     // NEW — needed by per-run finally arms when crash fires before line 113
```

#### Pattern C — Paired per-run writes adjacent to each existing task-level call (analog: lines 117-119, 123, 176, 188, 228, 279)

**Analog excerpt** (verbatim from `app/Services/RunOrchestratorService.php:117-123`):

```php
$this->taskWriter?->writeNewTask($writerRepoSlug, $selectedIssue['number'], $selectedIssue['title'] ?? '', $selectedIssue['body'] ?? '', $repoProfile['repo_path'], $selectedIssue['html_url'] ?? '');
$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'new');
$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'selected');

// Step 3: Claude plan
$this->pushLog('[3/8] Running Claude planner');
$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'planning');
```

**Shape after Phase 21** (paired adjacent per D-06; D-04 states the per-run schema mirrors task-level exactly; no `writeRunNewTask` exists — only the 8 lifecycle states get paired):

```php
$this->taskWriter?->writeNewTask($writerRepoSlug, $selectedIssue['number'], $selectedIssue['title'] ?? '', $selectedIssue['body'] ?? '', $repoProfile['repo_path'], $selectedIssue['html_url'] ?? '');
$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'new');
$this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'new');
$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'selected');
$this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'selected');

// Step 3: Claude plan
$this->pushLog('[3/8] Running Claude planner');
$this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'planning');
$this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'planning');
```

**Verified call sites that need paired per-run writes** (read from `app/Services/RunOrchestratorService.php`):

| # | State | Existing line | Existing call |
|---|-------|---------------|---------------|
| 1 | `new` | 118 | `writeStatus(..., 'new')` |
| 2 | `selected` | 119 | `writeStatus(..., 'selected')` |
| 3 | `planning` | 123 | `writeStatus(..., 'planning')` |
| 4 | `planned` | 176 | `writeStatus(..., 'planned')` |
| 5 | `executing` | 188 | `writeStatus(..., 'executing')` |
| 6 | `verifying` | 228 | `writeStatus(..., 'verifying')` |
| 7 | `pr_open` | 279 | `writeStatus(..., 'pr_open')` |
| 8 | `blocked` | 328 (finally arm) | `writeBlockedIfNotTerminal(...)` |

#### Pattern D — Finally-arm paired call (analog: existing `writeBlockedIfNotTerminal` arm at lines 326-332)

**Analog excerpt** (verbatim from `app/Services/RunOrchestratorService.php:326-332`):

```php
if ($this->taskWriter !== null && $selectedIssue !== null) {
    try {
        $this->taskWriter->writeBlockedIfNotTerminal($writerRepoSlug, $selectedIssue['number']);
    } catch (Throwable $e) {
        $this->pushLog("      Warning: blocked-state write failed: {$e->getMessage()}");
    }
}
```

**Shape after Phase 21** (D-08: guard order `$selectedIssue !== null && $runId !== null`; own try/catch never masks original exception):

```php
if ($this->taskWriter !== null && $selectedIssue !== null) {
    try {
        $this->taskWriter->writeBlockedIfNotTerminal($writerRepoSlug, $selectedIssue['number']);
    } catch (Throwable $e) {
        $this->pushLog("      Warning: blocked-state write failed: {$e->getMessage()}");
    }
}

if ($this->taskWriter !== null && $selectedIssue !== null && $runId !== null && $writerRepoSlug !== null) {
    try {
        $this->taskWriter->writeRunBlockedIfNotTerminal($writerRepoSlug, $selectedIssue['number'], $runId);
    } catch (Throwable $e) {
        $this->pushLog("      Warning: per-run blocked-state write failed: {$e->getMessage()}");
    }
}
```

#### Pattern E — Outcome.md write adjacent to JSONL append (analog: existing JSONL append arm at lines 334-343)

**Analog excerpt** (verbatim from `app/Services/RunOrchestratorService.php:334-343`):

```php
try {
    $payload = $result instanceof RunResult
        ? $this->payloadFromResult($repo, $result)
        : $this->partialPayload($repo, $selectedIssue, $snapshot, $startedAt, $caught);

    $path = $runLogStore->append($payload);
    $this->pushLog("      Appended run log to {$path}");
} catch (Throwable $e) {
    $this->pushLog("      Warning: run log write failed: {$e->getMessage()}");
}
```

**Shape after Phase 21** — extract `$payload` into a local accessible by the next arm, then add a sibling try/catch (D-09, D-10, D-11; matches RESEARCH §Outcome.md Mapping recommended sequence):

```php
$payload = null;
try {
    $payload = $result instanceof RunResult
        ? $this->payloadFromResult($repo, $result)
        : $this->partialPayload($repo, $selectedIssue, $snapshot, $startedAt, $caught);

    $path = $runLogStore->append($payload);
    $this->pushLog("      Appended run log to {$path}");
} catch (Throwable $e) {
    $this->pushLog("      Warning: run log write failed: {$e->getMessage()}");
}

// D-09: outcome.md write — same finally arm, sibling try/catch so writer failure
// never masks JSONL failure or vice-versa. D-15: JSONL write above is untouched.
if ($this->taskWriter !== null && $selectedIssue !== null && $runId !== null && $writerRepoSlug !== null && $payload !== null) {
    try {
        $outcome = $this->outcomePayload($runId, $result, $payload, $startedAt, $caught);
        $this->taskWriter->writeRunOutcome($writerRepoSlug, $selectedIssue['number'], $runId, $outcome);
    } catch (Throwable $e) {
        $this->pushLog("      Warning: outcome write failed: {$e->getMessage()}");
    }
}
```

#### Pattern F — `outcomePayload()` private mapper (analog: existing `payloadFromResult` and `partialPayload`, lines 347-409)

**Analog excerpt 1** (verbatim from `app/Services/RunOrchestratorService.php:347-377` — pulls flat keys from `RunResult`):

```php
private function payloadFromResult(string $repo, RunResult $result): array
{
    return [
        'repo' => $repo,
        'issue' => [
            'number' => $result->selectedTaskId,
            'title' => $result->selectedIssueTitle,
        ],
        'status' => $result->status,
        'partial' => false,
        'started_at' => $result->startedAt,
        'finished_at' => $result->finishedAt,
        'failure_reason' => $result->failureReason,
        'pr' => [
            'number' => $result->prNumber,
            'url' => $result->prUrl,
        ],
        'decision_path' => $this->log,
        'usage' => [
            'selector' => $result->selectorUsage,
            'planner' => $result->plannerUsage,
            'executor' => $result->executorUsage,
            'total' => AnthropicCostEstimator::combine(
                $result->selectorUsage,
                $result->plannerUsage,
                $result->executorUsage,
            ),
        ],
        'executor_duration_seconds' => $result->executorDurationSeconds,
    ];
}
```

**Shape for `outcomePayload`** (RESEARCH §Outcome.md Mapping Table — accept the already-built `$payload` to keep JSONL as single source of truth; map the 9 D-05 keys):

```php
private function outcomePayload(string $runId, ?RunResult $result, array $payload, string $startedAt, ?Throwable $caught): array
{
    // RESEARCH-mapped status transform: succeeded -> pr_open, failed/skipped -> blocked, crashed -> crashed
    $rawStatus = (string) ($payload['status'] ?? 'crashed');
    $status = match ($rawStatus) {
        'succeeded' => 'pr_open',
        'crashed' => 'crashed',
        default => 'blocked',          // 'failed' | 'skipped' -> blocked
    };

    // Normalize DATE_ATOM -> Z-form to match writer's gmdate('Y-m-d\TH:i:s\Z') convention
    $startedAtZ = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) ($payload['started_at'] ?? $startedAt)));
    $finishedAtZ = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) ($payload['finished_at'] ?? date(DATE_ATOM))));

    $totalCost = $payload['usage']['total']?->estimatedCostUsd ?? 0.0;

    return [
        'run_id' => $runId,
        'status' => $status,
        'pr_number' => $payload['pr']['number'] ?? '',
        'pr_url' => (string) ($payload['pr']['url'] ?? ''),
        'cost_usd' => (string) $totalCost,                    // renderFrontmatter coerces; explicit cast for clarity
        'started_at' => $startedAtZ,
        'finished_at' => $finishedAtZ,
        'failure_reason' => (string) ($payload['failure_reason'] ?? ''),
        'partial' => $payload['partial'] ? 'true' : 'false',
    ];
}
```

---

### `tests/Feature/TaskDirectoryWriterServiceTest.php` — expand from 1 to ~12-18 it-blocks

#### Pattern G — Phase 20 smoke test (analog: itself, the entire current file, 53 lines)

**Analog excerpt** (verbatim from `tests/Feature/TaskDirectoryWriterServiceTest.php:1-21`):

```php
<?php

use App\Services\TaskDirectoryWriterService;

it('writes task.md and status.md under a temporary HOME for a GitHub-shaped task', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-task-writer-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T08:14:01Z');

    $writer->writeNewTask(
        'binarygary/copland',
        42,
        'Add console launcher',
        'Body of the issue.',
        '/Users/gary/projects/copland',
        'https://github.com/binarygary/copland/issues/42',
    );
    // ...
```

**4-line temp-HOME idiom** (per RESEARCH §Pest Temp-HOME Pattern — apply to every new `it()` block):

```php
$originalHome = $_SERVER['HOME'] ?? null;
$home = sys_get_temp_dir().'/copland-<slug>-'.uniqid();
mkdir($home, 0755, true);
$_SERVER['HOME'] = $home;
// ... arrange / act / assert ...
$_SERVER['HOME'] = $originalHome;
```

#### Pattern H — Multi-case Pest file organization (analog: `tests/Unit/GlobalConfigTest.php`, 11 `it()` blocks)

**Analog excerpt** (verbatim from `tests/Unit/GlobalConfigTest.php:1-44` — first two of 11 cases):

```php
<?php

use App\Config\GlobalConfig;

it('bootstraps a default home config file at ~/.copland.yml', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-global-config-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $config = new GlobalConfig;

    // ... arrange / act / many `expect()` lines ...

    $_SERVER['HOME'] = $originalHome;
});

it('returns default retry config values when api.retry is not in config', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-global-config-retry-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $config = new GlobalConfig;

    expect($config->retryMaxAttempts())->toBe(3);
    expect($config->retryBaseDelaySeconds())->toBe(1);

    $_SERVER['HOME'] = $originalHome;
});
```

**Apply to Phase 21**: one file (`tests/Feature/TaskDirectoryWriterServiceTest.php`), ~12-18 independent `it()` cases, each opens its own temp HOME, no `beforeEach` / `afterEach`. Each case targets one D-18 axis:

| # | Axis | Suggested it-block title |
|---|------|--------------------------|
| 1 | writeNewTask + writeStatus(new) (existing) | `'writes task.md and status.md under a temporary HOME for a GitHub-shaped task'` |
| 2 | writeNewTask Asana 13-digit GID | `'writes task.md for an Asana-shaped task with empty source_url and string GID'` |
| 3 | writeNewTask exact key assertion | `'writes task.md frontmatter with all 7 keys matching the TaskLoader contract'` |
| 4 | all 8 task-level states | `'writeStatus produces an 8-row transitions table across the full lifecycle'` |
| 5 | writeBlockedIfNotTerminal early-return at pr_open | `'writeBlockedIfNotTerminal is a no-op after pr_open'` |
| 6 | writeBlockedIfNotTerminal early-return at blocked | `'writeBlockedIfNotTerminal is a no-op after blocked'` |
| 7 | writeBlockedIfNotTerminal transitions to blocked | `'writeBlockedIfNotTerminal transitions executing -> blocked'` |
| 8 | writeRunStatus all 8 states | `'writeRunStatus produces a per-run transitions table for the full lifecycle'` |
| 9 | writeRunStatus Asana GID | `'writeRunStatus accepts a 13-digit string task id and a Z-form run id'` |
| 10 | writeRunBlockedIfNotTerminal terminal-state guard | `'writeRunBlockedIfNotTerminal respects per-run pr_open as terminal'` |
| 11 | writeRunBlockedIfNotTerminal transitions to blocked | `'writeRunBlockedIfNotTerminal transitions verifying -> blocked'` |
| 12 | writeRunOutcome all 9 keys | `'writeRunOutcome emits all 9 D-05 frontmatter keys'` |
| 13 | writeRunOutcome with body section | `'writeRunOutcome accepts an optional body with a per-stage usage table'` |
| 14 | atomic-rename leaves no .tmp | `'atomic write leaves no .tmp residue after a successful write'` |
| 15 | idempotent directory creation | `'writing twice into the same task/run dir is idempotent'` |
| 16 | transitions-table append-only | `'three sequential writeStatus calls produce a 3-row table not an overwrite'` |
| 17 | $lastState per-tuple isolation | `'lastState map keeps task-level and per-run tuples isolated'` |
| 18 | writeRunStatus + writeStatus coexist | `'writeStatus and writeRunStatus on the same task do not cross-pollute lastState'` |

#### Pattern I — Clock seam with counter for multi-timestamp assertions (analog: RESEARCH §Clock seam usage)

**Excerpt from RESEARCH** (apply when a test needs distinct sequential timestamps in the transitions table):

```php
$times = ['2026-05-27T08:14:01Z', '2026-05-27T08:14:02Z', '2026-05-27T08:14:03Z'];
$i = 0;
$writer = new TaskDirectoryWriterService(clock: function () use (&$times, &$i) {
    return $times[$i++] ?? end($times);
});
```

**Apply to:** axis #16 (transitions-table append-only — must show 3 distinct timestamps), axis #4 (all 8 task-level states — show 8 distinct rows).

---

### `phpstan.neon` — confirm current shape (no source edits beyond verifying composer script)

**Verified current shape** (verbatim, 5 lines):

```yaml
parameters:
    level: 5
    paths:
        - app
    excludePaths:
        - vendor
```

**Per D-17 / RESEARCH §PHPStan Error Catalog recommendation**: do NOT edit `phpstan.neon` — instead add a `composer.json` script entry. Confirm the file is left untouched in Plan 21-01's git diff.

**RESEARCH-verified PHPStan errors to fix** (6 errors, ~6-8 lines total surface, all mechanical):

| # | File | Line | Fix |
|---|------|------|-----|
| 1 | `app/Services/ClaudePlannerService.php` | 102 | `?ModelUsage` → `ModelUsage` (drop the `?`) |
| 2 | `app/Services/ClaudeSelectorService.php` | 82 | `?ModelUsage` → `ModelUsage` (drop the `?`) |
| 3 | `app/Services/RunOrchestratorService.php` | 317 | `if (isset($workspacePath) && $workspacePath !== null)` → `if (isset($workspacePath))` |
| 4 | `app/Services/RunOrchestratorService.php` | 39-42 | add `$repoPath = null;` to the init block |
| 5 | `app/Services/RunOrchestratorService.php` | 326 | add `&& $writerRepoSlug !== null` clause AND init `$writerRepoSlug = null;` |
| 6 | `app/Support/HomeDirectory.php` | 31 | drop `isset($pwinfo['dir'])` from the `&&` chain (line 31: `if (is_array($pwinfo) && isset($pwinfo['dir']) && $pwinfo['dir'] !== '')`) |

**Verified excerpt for fix #1 / fix #2** (verbatim from `app/Services/ClaudePlannerService.php:102-110`):

```php
private function usageFromResponse(LlmResponse $response): ?ModelUsage
{
    return AnthropicCostEstimator::forModel(
        $this->model,
        $response->usage->inputTokens,
        $response->usage->outputTokens,
        $response->usage->cacheWriteTokens,
        $response->usage->cacheReadTokens,
        // ...
    );
}
```

Fix: change `: ?ModelUsage` → `: ModelUsage`. Identical signature at `app/Services/ClaudeSelectorService.php:82`. `AnthropicCostEstimator::forModel(): ModelUsage` is non-nullable (verified at `app/Support/AnthropicCostEstimator.php:9`).

**Verified excerpt for fix #6** (verbatim from `app/Support/HomeDirectory.php:29-34`):

```php
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pwinfo = posix_getpwuid(posix_geteuid());
    if (is_array($pwinfo) && isset($pwinfo['dir']) && $pwinfo['dir'] !== '') {
        return rtrim($pwinfo['dir'], '/');
    }
}
```

Fix: drop `isset($pwinfo['dir']) && ` → `if (is_array($pwinfo) && $pwinfo['dir'] !== '')`.

**Composer script entry** (D-17 — add to `composer.json` `scripts` block):

```json
"phpstan": "vendor/bin/phpstan analyse --memory-limit=512M --no-progress"
```

---

## Shared Patterns

### Pattern: Nullsafe writer access (`?->`)
**Source:** Every Phase 20 writer call site in `RunOrchestratorService.php` (lines 117, 118, 119, 123, 176, 188, 228, 279) plus finally-arm guards (lines 326-332)
**Apply to:** All new `writeRunStatus`, `writeRunOutcome`, `writeRunBlockedIfNotTerminal` call sites
**Form:** `$this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'planning');`

### Pattern: Finally-arm own try/catch (never mask original exception)
**Source:** `app/Services/RunOrchestratorService.php:317-324` (workspace cleanup), `326-332` (blocked write), `334-343` (JSONL append)
**Apply to:** New per-run blocked-state arm AND new outcome.md write arm. Three sibling try/catch arms become five.
**Form:**
```php
if (<guards>) {
    try {
        $this->taskWriter-><method>(...);
    } catch (Throwable $e) {
        $this->pushLog("      Warning: <name> write failed: {$e->getMessage()}");
    }
}
```

### Pattern: ISO-8601 UTC with literal `Z`
**Source:** `app/Services/TaskDirectoryWriterService.php:88` — `gmdate('Y-m-d\TH:i:s\Z')`
**Apply to:**
- `$runId` derivation in orchestrator: `str_replace(':', '-', gmdate('Y-m-d\TH:i:s\Z'))` → produces `2026-05-27T19-15-22Z`
- `outcomePayload` timestamp normalization (DATE_ATOM → Z-form): `gmdate('Y-m-d\TH:i:s\Z', strtotime($atomFormatted))`

### Pattern: Test temp-HOME idiom (4-line top, 1-line tail)
**Source:** RESEARCH §Pest Temp-HOME Pattern (verified across 7 test files)
**Apply to:** Every new `it()` block in `tests/Feature/TaskDirectoryWriterServiceTest.php`
**Form:**
```php
$originalHome = $_SERVER['HOME'] ?? null;
$home = sys_get_temp_dir().'/copland-<slug>-'.uniqid();
mkdir($home, 0755, true);
$_SERVER['HOME'] = $home;
// ... body ...
$_SERVER['HOME'] = $originalHome;
```

### Pattern: Writer clock seam (deterministic timestamps)
**Source:** `app/Services/TaskDirectoryWriterService.php:13` constructor + lines 82-89 `now()` method
**Apply to:** All test cases that assert against timestamp strings. Single fixed timestamp: `clock: fn () => '2026-05-27T08:14:01Z'`. Sequential timestamps: counter pattern (see Pattern I above).

### Pattern: D-15 JSONL invariant — negative assertion
**Source:** REQUIREMENTS §TASK-04, CONTEXT.md §D-15
**Apply to:** Plan 21-03 acceptance check. Verification:
```bash
git diff main -- app/Support/RunLogStore.php
```
Must produce empty output. This is a wave-merge gate, not an `it()` block — verifier runs it directly.

---

## No Analog Found

| File / Concept | Reason |
|----------------|--------|
| Status-value mapping (`succeeded` → `pr_open`, etc.) | Phase 21 introduces this 4-line `match` expression. RESEARCH §Outcome.md Mapping Table specifies the exact mapping. Inline in `outcomePayload()`. |
| DATE_ATOM → Z-form timestamp normalization | New transformation for `outcomePayload()`. Inline as two `gmdate(strtotime(...))` calls. No existing analog because the writer's other timestamps are written by the writer itself (already Z-form); only `outcomePayload` ingests external DATE_ATOM strings. |
| `composer.json` `phpstan` script entry | Trivial JSON edit. No code analog needed — D-17 spec is explicit. |

---

## Metadata

**Analog search scope:** `app/Services/`, `app/Support/`, `tests/Feature/`, `tests/Unit/`, `phpstan.neon`
**Files scanned:** 9 (TaskDirectoryWriterService.php, RunOrchestratorService.php, TaskDirectoryWriterServiceTest.php, GlobalConfigTest.php, PlanArtifactStoreTest.php, RunLogStoreTest.php, ClaudePlannerService.php, ClaudeSelectorService.php, HomeDirectory.php, phpstan.neon)
**Pattern extraction date:** 2026-05-27

**Key line-number anchors for the planner's `<read_first>` fields:**

| Anchor | Why the planner needs it |
|--------|--------------------------|
| `app/Services/TaskDirectoryWriterService.php:42-69` | `writeStatus` shape — directly copied for `writeRunStatus` |
| `app/Services/TaskDirectoryWriterService.php:71-80` | `writeBlockedIfNotTerminal` shape — directly copied for `writeRunBlockedIfNotTerminal` |
| `app/Services/TaskDirectoryWriterService.php:17-40` | `writeNewTask` shape — analog for `writeRunOutcome` (one-shot write) |
| `app/Services/TaskDirectoryWriterService.php:91-97` | `taskDir` helper — analog for new `runDir` one-liner |
| `app/Services/TaskDirectoryWriterService.php:113-124` | `atomicWrite` — reused verbatim by both new write methods |
| `app/Services/TaskDirectoryWriterService.php:126-136` | `renderFrontmatter` — reused verbatim |
| `app/Services/RunOrchestratorService.php:36-42` | init block — where defensive nulls and `$runId = null` go |
| `app/Services/RunOrchestratorService.php:111-115` | `$writerRepoSlug` derivation — `$runId` derivation goes immediately after |
| `app/Services/RunOrchestratorService.php:117-123` | first paired-write block — shows the adjacency convention |
| `app/Services/RunOrchestratorService.php:176, 188, 228, 279` | the other 4 paired-write sites |
| `app/Services/RunOrchestratorService.php:326-332` | finally-arm `writeBlockedIfNotTerminal` — analog for new `writeRunBlockedIfNotTerminal` arm |
| `app/Services/RunOrchestratorService.php:334-343` | finally-arm JSONL append — outcome.md write slots in adjacent (Pattern E) |
| `app/Services/RunOrchestratorService.php:347-377` | `payloadFromResult` — analog for `outcomePayload` private mapper |
| `app/Services/RunOrchestratorService.php:379-409` | `partialPayload` — same data shape as `payloadFromResult`, used when `$result === null` |
| `tests/Feature/TaskDirectoryWriterServiceTest.php:1-53` | Phase 20 smoke test — first `it()` block of the expanded suite |
| `tests/Unit/GlobalConfigTest.php:1-44` | 11-case multi-it Pest file — organizational analog for the expanded suite |
| `app/Services/ClaudePlannerService.php:102` | PHPStan fix #1 — single-character edit (drop `?`) |
| `app/Services/ClaudeSelectorService.php:82` | PHPStan fix #2 — same single-character edit |
| `app/Support/HomeDirectory.php:31` | PHPStan fix #6 — drop the `isset()` clause |
| `phpstan.neon:1-7` | Untouched per D-17; composer.json gets the script entry instead |

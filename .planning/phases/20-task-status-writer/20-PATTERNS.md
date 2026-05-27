# Phase 20: Task & Status Writer - Pattern Map

**Mapped:** 2026-05-27
**Files analyzed:** 3 (1 new service, 1 modified orchestrator, 1 modified composition root, optional test)
**Analogs found:** 3 / 3 (smoke-test analog also identified)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Services/TaskDirectoryWriterService.php` (NEW) | service | file-I/O (write to `~/.copland/...`) | `app/Support/RunLogStore.php` | exact (same dirname under `~/.copland/`, same `HomeDirectory::resolve()` + `mkdir(...,0755,true)` + `RuntimeException` shape; differs only in atomic `rename()` vs `FILE_APPEND`) |
| `app/Services/TaskDirectoryWriterService.php` (NEW — testability seam) | service | constructor-injection seam | `app/Services/GitService.php` | exact (same `private $runner = null` / `private $clock = null` callable-injection idiom) |
| `app/Services/RunOrchestratorService.php` (MODIFIED) | orchestrator | request-response (with try/catch/finally + partialPayload) | itself (insertion-points within existing `run()`) | self-analog — must thread one new constructor dep + 9 call sites |
| `app/Commands/RunCommand.php` (MODIFIED — composition root) | command | composition-root wiring | `app/Commands/RunCommand.php:289-299` (existing orchestrator construction block) | self-analog — append one new dep to the named-argument list |
| `tests/Unit/TaskDirectoryWriterServiceTest.php` (OPTIONAL, per D-15) | test | temp-HOME smoke test | `tests/Unit/RunLogStoreTest.php` | exact ($_SERVER['HOME'] swap idiom is identical to what Phase 21 will need) |

---

## Pattern Assignments

### `app/Services/TaskDirectoryWriterService.php` (NEW — service, file-I/O)

**Primary analog:** `app/Support/RunLogStore.php` (entire file, 66 lines)
**Secondary analog (for constructor seam):** `app/Services/GitService.php:10`

#### Pattern 1 — File header / imports / class shape

Copy from `app/Support/RunLogStore.php:1-9`:

```php
<?php

namespace App\Support;

use App\Data\ModelUsage;
use RuntimeException;

class RunLogStore
{
```

**For Phase 20** (note: writer lives in `app/Services` per D-12, not `app/Support` — namespace and location both shift to match `GitService` / `PlanArtifactStore`):

```php
<?php

namespace App\Services;

use App\Support\HomeDirectory;
use RuntimeException;

class TaskDirectoryWriterService
{
```

#### Pattern 2 — Constructor seam (callable clock + optional home override)

Copy seam pattern from `app/Services/GitService.php:10`:

```php
public function __construct(private $runner = null) {}
```

**For Phase 20** (mirrors the `$runner` seam, two parameters per D-13):

```php
public function __construct(
    private $clock = null,                // ?callable — defaults to gmdate('Y-m-d\TH:i:s\Z')
    private ?string $homeOverride = null, // ?string  — defaults to HomeDirectory::resolve()
) {}
```

> Constructor-property promotion is the project convention (also seen in `RunOrchestratorService.php:19-31`). Match the leading-comma + trailing-comma multi-line shape used there.

#### Pattern 3 — HOME resolution + path construction

Copy from `app/Support/RunLogStore.php:27-30`:

```php
private function path(): string
{
    return HomeDirectory::resolve().'/.copland/logs/runs.jsonl';
}
```

**For Phase 20** (parameterized over `repoSlug` + `taskId`, with `$homeOverride` seam):

```php
private function taskDir(string $repoSlug, string|int $taskId): string
{
    $home = $this->homeOverride ?? HomeDirectory::resolve();
    $repoDir = str_replace('/', '__', $repoSlug);   // D-05: "owner/repo" -> "owner__repo"
    return "{$home}/.copland/tasks/{$repoDir}/" . (string) $taskId;
}
```

#### Pattern 4 — Recursive idempotent directory creation

Copy verbatim from `app/Support/RunLogStore.php:32-41` (this is the canonical idiom; do not invent a new one):

```php
private function ensureDirectoryExists(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        throw new RuntimeException("Failed to create run log directory at {$directory}");
    }
}
```

**For Phase 20** — same shape, just change the exception text to match (`"Failed to create task directory at {$directory}"`).

#### Pattern 5 — Atomic write idiom (tmp + rename), with `RuntimeException` style

`RunLogStore` uses `file_put_contents(..., FILE_APPEND)` which is NOT atomic. Phase 20 must NOT copy that part. Instead, match `RunLogStore`'s exception-message style while implementing the rename pattern:

Reference exception text from `app/Support/RunLogStore.php:20-22`:

```php
if (file_put_contents($path, $json.PHP_EOL, FILE_APPEND) === false) {
    throw new RuntimeException("Failed to append run log to {$path}");
}
```

**For Phase 20** (~10-line private helper, per D-09):

```php
private function atomicWrite(string $path, string $content): void
{
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("Failed to write {$tmp}");
    }
    if (! rename($tmp, $path)) {
        throw new RuntimeException("Failed to rename {$tmp} to {$path}");
    }
}
```

> Keep tmp file IN the destination directory (per Pitfall 2 — same-filesystem rename guarantee). Do not use `sys_get_temp_dir()`.

#### Pattern 6 — Timestamp formatting (clock seam)

The project already uses ISO-8601 UTC with literal `Z` in `runs.jsonl`. For Phase 20 use:

```php
private function now(): string
{
    return $this->clock !== null
        ? (string) ($this->clock)()
        : gmdate('Y-m-d\TH:i:s\Z');
}
```

> The escape sequence `\T` and `\Z` literalize the `T` and `Z` characters inside `gmdate`'s format string. This exact format is mandated by CONTEXT.md Specifics and matches `runs.jsonl`'s timestamp shape.

#### Pattern 7 — Frontmatter rendering (hand-rolled, NOT symfony/yaml)

No direct analog in the codebase — RESEARCH.md Don't-Hand-Roll section establishes this. Hand-roll 5–7 key/value pairs as quoted scalars with backslash + double-quote escaping:

```php
private function renderFrontmatter(array $pairs): string
{
    $out = '';
    foreach ($pairs as $k => $v) {
        $escaped = str_replace(["\\", "\""], ["\\\\", "\\\""], (string) $v);
        $out .= "{$k}: \"{$escaped}\"\n";
    }
    return $out;
}
```

> TaskLoader.gd's parser strips one pair of surrounding quotes and is permissive about extra keys. Always emit explicit `---` delimiters around the frontmatter block (per Pitfall 4 in RESEARCH.md).

---

### `app/Services/RunOrchestratorService.php` (MODIFIED — orchestrator)

**Analog:** itself. The planner must thread one new constructor parameter and add 9 call sites at the precise insertion points below.

#### Pattern A — Constructor parameter addition

Copy existing constructor shape from lines 19-31:

```php
public function __construct(
    private TaskSource $taskSource,
    private IssuePrefilterService $prefilter,
    private ClaudeSelectorService $selector,
    private ClaudePlannerService $planner,
    private PlanValidatorService $validator,
    private WorkspaceService $workspace,
    private GitService $git,
    private ClaudeExecutorService $executor,
    private VerificationService $verifier,
    private ?PlanArtifactStore $planArtifactStore = null,
    private ?RunLogStore $runLogStore = null,
) {}
```

**Add one parameter** (place it before the two existing `?Optional = null` tail params — required deps come first, optional last). Use `?TaskDirectoryWriterService = null` style to remain backward-compatible with any existing test that constructs the orchestrator positionally without the new dep:

```php
private ?TaskDirectoryWriterService $taskWriter = null,
```

And add the corresponding `use App\Services\TaskDirectoryWriterService;` to imports (it's already in the same namespace `App\Services`, so the `use` is unnecessary — just reference the class directly).

#### Pattern B — Insertion points inside `run()`

Reference the current shape of `run()` (lines 33-321 in `app/Services/RunOrchestratorService.php`). Insertion sites per RESEARCH.md Pattern 2 table:

| # | State | Insertion line (current) | Anchor in current source |
|---|-------|--------------------------|--------------------------|
| 1 | `new` (also writeNewTask) | between 108 and 110 | after `$this->pushLog("      Selected issue #{$selectedIssue['number']}: {$selectedIssue['title']}");` |
| 2 | `selected` | same block as #1 | immediately after #1 |
| 3 | `planning` | between 111 and 112 | after `$this->pushLog('[3/8] Running Claude planner');`, before `$plan = $this->planner->planTask(...)` |
| 4 | `planned` | after 163 | after `$this->pushLog('      Plan validated OK');` |
| 5 | `executing` | between 174 and 175 | after `$this->pushLog('[6/8] Running Claude executor');`, before `$this->executor->executeWithRepoProfile(...)` |
| 6 | `verifying` | between 213 and 214 | after `$this->pushLog('[7/8] Running verification');`, before `$this->verifier->verify(...)` |
| 7 | `pr_open` | after 263 | after `$this->pushLog("      Draft PR opened: {$prUrl}");` |
| 8 | `blocked` | inside `finally` (lines 300-320) | between existing workspace cleanup arm (301-308) and run-log append arm (310-319) |

#### Pattern C — Existing try/catch/finally (verbatim, lines 295-320)

This is the exact code block the planner inserts into. **Read these lines verbatim** so the new `blocked` arm slots in correctly:

```php
} catch (Throwable $e) {
    $this->pushLog("      Run crashed: {$e->getMessage()}");
    $caught = $e;

    throw $e;
} finally {
    if (isset($workspacePath) && $workspacePath !== null) {
        try {
            $this->workspace->cleanup($repoPath, $workspacePath);
            $this->pushLog('      Run finished in current checkout');
        } catch (\Exception $e) {
            $this->pushLog("      Warning: cleanup step failed: {$e->getMessage()}");
        }
    }

    try {
        $payload = $result instanceof RunResult
            ? $this->payloadFromResult($repo, $result)
            : $this->partialPayload($repo, $selectedIssue, $snapshot, $startedAt, $caught);

        $path = $runLogStore->append($payload);
        $this->pushLog("      Appended run log to {$path}");
    } catch (Throwable $e) {
        $this->pushLog("      Warning: run log write failed: {$e->getMessage()}");
    }
}
```

**New arm to insert** between the cleanup arm (closes at line 308 with `}`) and the run-log append arm (opens at line 310 with `try {`):

```php
if ($this->taskWriter !== null && $selectedIssue !== null) {
    try {
        $this->taskWriter->writeBlockedIfNotTerminal($repo, $selectedIssue['number']);
    } catch (\Throwable $e) {
        $this->pushLog("      Warning: blocked-state write failed: {$e->getMessage()}");
    }
}
```

> The pattern of wrapping every finally-arm in its own try/catch is established at lines 302-307 (cleanup) and 310-319 (run-log append). Match it exactly — never let a writer error mask the original exception that's propagating out of `try`.

#### Pattern D — Guard each `writeStatus` call on `$this->taskWriter !== null`

Because the constructor parameter defaults to `null` for backward-compatibility, every call site must guard:

```php
$this->taskWriter?->writeStatus($repo, $selectedIssue['number'], 'planning');
```

> The nullsafe operator `?->` is PHP 8.0+ idiomatic and concise. Project uses `?->` already (e.g., `$snapshot?->selectorUsage` at line 374 of the orchestrator).

#### Pattern E — `partialPayload` shape (REFERENCE ONLY — do not modify)

For context, the existing `partialPayload()` method at lines 355-385 captures the in-flight crash state. Phase 20 does NOT touch this method — it complements it by writing `blocked` to `status.md` from the same finally arm. The two paths are independent: `partialPayload` keeps writing to `~/.copland/logs/runs.jsonl`, while `writeBlockedIfNotTerminal` writes to `~/.copland/tasks/<repo>/<id>/status.md`.

```php
private function partialPayload(string $repo, ?array $selectedIssue, ?RunProgressSnapshot $snapshot, string $startedAt, ?Throwable $caught): array
{
    return [
        'repo' => $repo,
        'issue' => [
            'number' => $selectedIssue['number'] ?? $snapshot?->selectedTaskId,
            'title' => $selectedIssue['title'] ?? $snapshot?->selectedIssueTitle,
        ],
        'status' => 'crashed',
        // ...
    ];
}
```

> Note: `partialPayload` already handles `$selectedIssue === null` via `?? $snapshot?->selectedTaskId`. The writer's `writeBlockedIfNotTerminal` does NOT have a snapshot fallback — if no task was ever selected, no `status.md` exists to update. This is correct behavior (per Pitfall 1 in RESEARCH.md) — guard the writer call on `$selectedIssue !== null` only.

---

### `app/Commands/RunCommand.php` (MODIFIED — composition root)

**Analog:** itself, lines 289-299 (existing orchestrator construction).

Existing construction block (verbatim, lines 289-299):

```php
$orchestrator = new RunOrchestratorService(
    taskSource: $taskSource,
    prefilter: new IssuePrefilterService($repoConfig, new GitHubService, $repo),
    selector: new ClaudeSelectorService($globalConfig, $selectorClient),
    planner: new ClaudePlannerService($globalConfig, $plannerClient),
    validator: new PlanValidatorService,
    workspace: new WorkspaceService($repoConfig, $git),
    git: $git,
    executor: new ClaudeExecutorService($globalConfig, $executorClient),
    verifier: new VerificationService($git),
);
```

**Change:** append one new named argument (named-argument calling convention is already established here):

```php
$orchestrator = new RunOrchestratorService(
    taskSource: $taskSource,
    prefilter: new IssuePrefilterService($repoConfig, new GitHubService, $repo),
    selector: new ClaudeSelectorService($globalConfig, $selectorClient),
    planner: new ClaudePlannerService($globalConfig, $plannerClient),
    validator: new PlanValidatorService,
    workspace: new WorkspaceService($repoConfig, $git),
    git: $git,
    executor: new ClaudeExecutorService($globalConfig, $executorClient),
    verifier: new VerificationService($git),
    taskWriter: new TaskDirectoryWriterService,
);
```

And add the import: `use App\Services\TaskDirectoryWriterService;` near the existing service imports at the top of `RunCommand.php`.

> `$repoProfile['repo_path']` is constructed at line 286 (`'repo_path' => $path`) — this is the value the writer needs. The orchestrator already reads it at line 166 (`$repoPath = $repoProfile['repo_path'] ?? getcwd();`). The writer call sites just read `$repoProfile['repo_path']` directly.

---

### `tests/Unit/TaskDirectoryWriterServiceTest.php` (OPTIONAL — Pest smoke test)

**Analog:** `tests/Unit/RunLogStoreTest.php` (entire 55-line file).

Copy the temp-HOME idiom verbatim from `tests/Unit/RunLogStoreTest.php:6-12`:

```php
it('appends structured jsonl records under the global copland logs directory', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-run-log-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $store = new RunLogStore;
```

…and the cleanup line at the very end (line 54):

```php
    $_SERVER['HOME'] = $originalHome;
});
```

**For Phase 20** (smoke test exercising `writeNewTask` + `writeStatus(new)` + `writeStatus(blocked)`):

```php
<?php

use App\Services\TaskDirectoryWriterService;

it('creates task.md and status.md under ~/.copland/tasks with frontmatter', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-task-writer-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(
        clock: fn () => '2026-05-27T08:14:01Z',
    );

    $writer->writeNewTask(
        repoSlug: 'binarygary/copland',
        taskId: 42,
        title: 'Add console launcher',
        body: 'Body of the issue.',
        repoPath: '/Users/gary/projects/copland',
        sourceUrl: 'https://github.com/binarygary/copland/issues/42',
    );

    $taskMd = $home.'/.copland/tasks/binarygary__copland/42/task.md';
    expect(file_exists($taskMd))->toBeTrue();
    expect(file_get_contents($taskMd))->toContain('id: "42"');
    expect(file_get_contents($taskMd))->toContain('repo_slug: "binarygary/copland"');
    expect(file_get_contents($taskMd))->toContain('created_at: "2026-05-27T08:14:01Z"');

    $writer->writeStatus('binarygary/copland', 42, 'new');
    $statusMd = $home.'/.copland/tasks/binarygary__copland/42/status.md';
    expect(file_get_contents($statusMd))->toContain('state: "new"');

    $_SERVER['HOME'] = $originalHome;
});
```

> The `clock: fn () => '...'` named-argument call pins the timestamp for deterministic assertions. This is exactly the seam D-13 specifies.

---

## Shared Patterns

### Pattern: `HomeDirectory::resolve()` for path roots
**Source:** `app/Support/HomeDirectory.php:17-39` (already exists)
**Apply to:** All filesystem-writer services under `~/.copland/`
**Excerpt** (verbatim, lines 17-39):
```php
public static function resolve(): string
{
    $home = $_SERVER['HOME'] ?? null;
    if (is_string($home) && $home !== '') {
        return rtrim($home, '/');
    }

    $home = getenv('HOME');
    if (is_string($home) && $home !== '') {
        return rtrim($home, '/');
    }

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pwinfo = posix_getpwuid(posix_geteuid());
        if (is_array($pwinfo) && isset($pwinfo['dir']) && $pwinfo['dir'] !== '') {
            return rtrim($pwinfo['dir'], '/');
        }
    }

    throw new RuntimeException(
        'Could not resolve HOME directory. Set $HOME or ensure posix extension is available.'
    );
}
```
Phase 20 calls `HomeDirectory::resolve()` exactly once, gated behind the `$this->homeOverride ?? ...` seam.

### Pattern: `RuntimeException` for filesystem failures
**Source:** `app/Support/RunLogStore.php:20-22, 38-40`
**Apply to:** All `mkdir` failures, `file_put_contents` failures, `rename` failures in the new writer.
**Style:** Single-string descriptive message, including the failing path. Examples:
- `"Failed to create task directory at {$directory}"`
- `"Failed to write {$tmp}"`
- `"Failed to rename {$tmp} to {$path}"`

### Pattern: Constructor-property promotion with optional trailing nullable params
**Source:** `app/Services/RunOrchestratorService.php:19-31`, `app/Services/GitService.php:10`
**Apply to:** All new service constructors. Required deps first; optional/seam params last with `?Type = null` defaults.

### Pattern: Nullsafe operator for optional dependencies
**Source:** `app/Services/RunOrchestratorService.php:374` (`$snapshot?->selectorUsage`)
**Apply to:** Every orchestrator call site for the new `?TaskDirectoryWriterService $taskWriter` — use `$this->taskWriter?->writeStatus(...)` to preserve backward compatibility with existing tests that don't pass a writer.

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| (frontmatter rendering helper) | utility | transform | No existing in-tree code hand-rolls YAML frontmatter — RunLogStore uses `json_encode`. Pattern is synthesized in RESEARCH.md per the "Don't Hand-Roll: YAML output" guidance (TaskLoader's parser is intentionally narrow). 5-line `foreach` emitting `key: "escaped-value"` lines — keep inline as private method on the writer. |
| (transitions-table append) | utility | transform | No analog — markdown table append is novel to this phase. Two-line string-concat (header check + row append). Keep inline as private method on the writer. |

---

## Metadata

**Analog search scope:** `app/Support/`, `app/Services/`, `app/Commands/`, `tests/Unit/`
**Files scanned:** 6 (RunLogStore.php, HomeDirectory.php, GitService.php, RunOrchestratorService.php, RunCommand.php, RunLogStoreTest.php)
**Pattern extraction date:** 2026-05-27

**Key line-number anchors for the planner's `<read_first>` fields:**
- `app/Support/RunLogStore.php:1-66` — primary structural analog (~50 lines)
- `app/Support/HomeDirectory.php:17-39` — HOME resolution
- `app/Services/GitService.php:10` — `$runner` seam (1 line)
- `app/Services/RunOrchestratorService.php:19-31` — constructor shape
- `app/Services/RunOrchestratorService.php:79-108` — selection block (where `new` + `selected` writes go)
- `app/Services/RunOrchestratorService.php:111-175` — planner/validator/executor block (insertion points 3-5)
- `app/Services/RunOrchestratorService.php:213-263` — verifier/PR block (insertion points 6-7)
- `app/Services/RunOrchestratorService.php:295-320` — try/catch/finally (insertion point 8)
- `app/Services/RunOrchestratorService.php:355-385` — partialPayload reference shape (DO NOT MODIFY)
- `app/Commands/RunCommand.php:276-301` — composition root (repoProfile + orchestrator instantiation)
- `tests/Unit/RunLogStoreTest.php:1-55` — temp-HOME test idiom for the optional smoke test

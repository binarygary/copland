# Phase 20: Task & Status Writer - Research

**Researched:** 2026-05-27
**Domain:** PHP/Laravel Zero filesystem writer — atomic markdown emission to a Godot-read directory
**Confidence:** HIGH

## Summary

Phase 20 is a tightly-scoped, additive PHP filesystem writer. Every decision in CONTEXT.md (D-01..D-17) has been confirmed against the actual source: TaskLoader.gd's STATES list and frontmatter parser are exactly as described; the orchestrator's try/catch/finally is structured exactly where the `blocked` write needs to live; `$repoProfile['repo_path']` is the confirmed key; `~/.copland/tasks/` is greenfield (only Phase 19's `ConsoleCommand` mentions it, as a read target). The implementation is essentially "write a `TaskDirectoryWriterService` that mirrors `RunLogStore`'s shape and call it from 8 sites inside `RunOrchestratorService::run()`."

The only research finding that meaningfully updates CONTEXT.md is around the Asana `source_url`: the current `AsanaService::getOpenTasks()` does NOT expose `html_url` or `permalink_url` — the writer must synthesize one from the Asana project GID and task GID (or accept that for Asana sources, `source_url` is empty/derived). All other decisions hold exactly as written.

**Primary recommendation:** Implement `App\Services\TaskDirectoryWriterService` as a thin, constructor-injected, hand-rolled-frontmatter writer (no symfony/yaml dependency for output, since TaskLoader only handles top-level scalars). Insert 8 call sites in `RunOrchestratorService::run()` at the exact line ranges identified below. Use the same `$_SERVER['HOME']` override seam as `RunLogStoreTest` for testability.

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Schema contract with Godot:**
- **D-01:** Writer output schema is dictated by `console-godot/scripts/TaskLoader.gd`. `task.md` frontmatter MUST contain at minimum: `id`, `title`, `repo_slug`, `repo_path`, `created_at`. `status.md` frontmatter MUST contain at minimum: `state`, `updated_at`. Additional keys allowed.
- **D-02:** State vocabulary is TaskLoader's `STATES` array verbatim: `[new, selected, planning, planned, executing, verifying, pr_open, merged, blocked]`. Phase 20 emits 8 of these: `new, selected, planning, planned, executing, verifying, pr_open, blocked`. `merged` is out of scope.

**Lifecycle transitions:**
- **D-03:** 8 state writes total: `new` + `selected` (after selection), `planning` (before planner), `planned` (after validator accepts), `executing` (before executor loop), `verifying` (before verifier), `pr_open` (after `gh pr create` succeeds), `blocked` (from finally block on any exception/SIGINT pre-`pr_open`).

**Path layout and slug normalization:**
- **D-04:** Layout: `~/.copland/tasks/<repo-dir>/<task_id>/{task.md, status.md}`. No `runs/<run-id>/` in Phase 20.
- **D-05:** GitHub `owner/repo` → directory: slash collapses to double-underscore (`binarygary/copland` → `binarygary__copland`). Display value `binarygary/copland` lives in `repo_slug` frontmatter key.
- **D-06:** Asana tasks use the registered repo's local-path basename as both directory name and `repo_slug` (e.g., `/Users/gary/projects/copland` → directory `copland`, `repo_slug: copland`).
- **D-07:** Task IDs stringified, untruncated: GH `int` 42 → `"42"`; Asana GID `"1209876543210"` verbatim. POSIX-safe directory names.

**File format:**
- **D-08:** `status.md` = YAML frontmatter + append-only transition log below the closing `---`. Each transition rewrites frontmatter + appends one row to the markdown transitions table. `task.md` written ONCE on creation; never rewritten.
- **D-09:** All writes use tmp-file + atomic rename. Write to `*.tmp`, `fsync`, `rename()`. ~10-line private helper, no dependency.
- **D-10:** Directory creation recursive (`mkdir($path, 0755, true)`) and idempotent.

**Crash recovery:**
- **D-11:** `blocked` write lives inside the existing `try/catch/finally` block. In `finally`: if last-written state ≠ `pr_open` ∧ ≠ `blocked`, write `blocked`. Accepted limitation: SIGKILL / PHP fatal bypasses `finally`.

**Where the writer lives:**
- **D-12:** New class: `app/Services/TaskDirectoryWriterService.php`. Constructor-injected into `RunOrchestratorService`. Public surface: `writeNewTask(repoSlug, taskId, title, body, repoPath, sourceUrl): void`, `writeStatus(repoSlug, taskId, state): void`, `writeBlockedIfNotTerminal(repoSlug, taskId): void`. Internal in-memory last-state tracking.
- **D-13:** Constructor accepts `?callable $clock = null` (default `fn() => gmdate('Y-m-d\TH:i:s\Z')`) AND `?string $homeOverride = null` (default `HomeDirectory::resolve()`). Mirrors `GitService($runner)` seam pattern.

**Out of scope:**
- **D-14:** `runs/<run-id>/` subdirectories — Phase 21.
- **D-15:** Comprehensive Pest tests with temporary HOME — Phase 21. Smoke tests in Phase 20 optional.
- **D-16:** `~/.copland/logs/runs.jsonl` untouched.
- **D-17:** PR-merge polling / `merged` state — defer.

### Claude's Discretion

- Exact PHP method signatures (parameter ordering, type hints) — match Copland's existing service-class style.
- `Symfony\Component\Filesystem\Filesystem` vs direct `rename()` — both acceptable.
- Phase 20 smoke tests inline vs all deferred to Phase 21 — optional.
- Log messages on each transition write — recommended: keep writer silent.
- Optional `blocked_reason` frontmatter key on `blocked` state — additive, helpful for console; pick what reads cleanly.

### Deferred Ideas (OUT OF SCOPE)

- Per-run subdirectories `runs/<run-id>/` — Phase 21.
- Comprehensive Pest tests — Phase 21.
- `merged` state writes — v2.1.
- File-watching in Godot console — v2.1.
- Live-tail of executing runs — v2.1.
- Run drill-in selection in Godot UI — v2.1.
- `blocked_reason` exception text in frontmatter — Claude's discretion.
- Console write actions from Godot — out-of-scope per REQUIREMENTS.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TASK-01 | On task selection, orchestrator writes `~/.copland/tasks/<repo>/<id>/task.md` containing title, body, repo slug, repo path, source URL, `created_at` | Confirmed at Step 3.1: insert `writeNewTask(...)` between lines 101 (after `$selectedIssue` is non-null) and 108 (after the pushLog narrating selection). All required values are available in scope: `$selectedIssue['title']`, `$selectedIssue['body']`, `$repo` (GH slug), `$repoProfile['repo_path']`, `$selectedIssue['html_url']` (GH-only — see [VERIFIED: AsanaService.php:46-53] for Asana shape gap). |
| TASK-02 | Orchestrator writes/updates `status.md` on every lifecycle transition with per-transition timestamp | Confirmed at Step 3.2: 8 `writeStatus(...)` calls + 1 `writeBlockedIfNotTerminal(...)` in `finally`. Insertion-point line numbers documented below. The existing `try/catch/finally` block (lines 295-320) is the canonical home for the `blocked` write. |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Selecting where to write (HOME resolution) | Support utility (`HomeDirectory`) | — | Reuse the canonical helper [VERIFIED: app/Support/HomeDirectory.php] |
| Writing the markdown files | New Service (`TaskDirectoryWriterService`) | — | Service tier is canonical for filesystem-mutating operations (mirrors `PlanArtifactStore`, `RunLogStore`) |
| Threading the writer through to the orchestrator | Composition root (`RunCommand`) → constructor injection | — | Matches the existing pattern: every orchestrator dependency is composed in `RunCommand::runRepo()` [VERIFIED: app/Commands/RunCommand.php:289-299] |
| Invoking the writer per lifecycle event | Orchestrator (`RunOrchestratorService::run`) | — | The orchestrator already owns the lifecycle — adding the writes is one call per phase step |
| Atomic file write idiom | Private helper inside the writer (~10 lines) | — | Per D-09 — no dependency, no separate utility class needed |
| Crash-recovery state finalization | Orchestrator `finally` block | Writer's internal last-state tracker | Reuses an already-tested cleanup path |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP standard library (`mkdir`, `file_put_contents`, `rename`, `fopen` / `fsync`) | PHP 8.2+ | Atomic file writes, directory creation | Zero dependencies; `rename()` is POSIX-atomic on same filesystem; matches `RunLogStore` style [VERIFIED: app/Support/RunLogStore.php:20-41] |
| `gmdate('Y-m-d\TH:i:s\Z')` | PHP builtin | ISO-8601 UTC timestamps with `Z` suffix | Already used elsewhere in the codebase per CONTEXT.md D-13; matches `runs.jsonl` timestamp format |
| `Symfony\Component\Yaml` | ^8.0 (already a direct dep) | NOT recommended for output | See Don't Hand-Roll below; symfony/yaml's dumper would quote/escape in ways TaskLoader's minimal parser doesn't anticipate. [VERIFIED: composer.json line 23] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `App\Support\HomeDirectory` | internal | HOME resolution with `posix_geteuid` fallback chain | Reuse via `HomeDirectory::resolve()` unless `$homeOverride` is set per D-13 [VERIFIED: app/Support/HomeDirectory.php:17-39] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Direct `rename()` | `Symfony\Component\Filesystem\Filesystem::dumpFile()` | symfony/filesystem already in transitive tree (Laravel), but `dumpFile` adds an extra layer of abstraction over what is essentially `tmp + rename`. CONTEXT.md D-09 explicitly allows either; recommend direct `rename()` for minimal indirection and to match `RunLogStore`'s style. |
| Hand-rolled YAML frontmatter | `Yaml::dump()` | symfony/yaml will sometimes quote, escape, or fold values in ways TaskLoader's permissive-but-shallow parser doesn't expect (e.g., multi-line scalars with `|`). The frontmatter we emit is 5–7 key/value pairs of plain strings; hand-rolling is shorter and more contract-correct. See Don't Hand-Roll below. |

**Installation:** None — all dependencies are already in `composer.json`.

**Version verification:** Not applicable — no new packages.

## Package Legitimacy Audit

Phase 20 adds **no external packages**. The entire implementation uses PHP standard library functions and existing in-tree classes. No `composer require` is needed. Section omitted as inapplicable.

## Architecture Patterns

### System Architecture Diagram

```
                                  ┌──────────────────────────────┐
                                  │ RunCommand::runRepo()         │
                                  │ (composition root)            │
                                  │                               │
                                  │   $writer = new                │
                                  │     TaskDirectoryWriterService│
                                  │                               │
                                  │   $orchestrator = new          │
                                  │     RunOrchestratorService(    │
                                  │       …, $writer)              │
                                  └─────────────┬─────────────────┘
                                                │
                                                ▼
                                  ┌──────────────────────────────┐
                                  │ RunOrchestratorService::run() │
                                  │                               │
                                  │ Step 2: selection → ✏ new      │
                                  │                     ✏ selected │
                                  │ Step 3: planner   → ✏ planning │
                                  │ Step 4: validator → ✏ planned  │
                                  │ Step 6: executor  → ✏ executing│
                                  │ Step 7: verifier  → ✏ verifying│
                                  │ Step 8: gh pr     → ✏ pr_open  │
                                  │                               │
                                  │ finally { writeBlockedIf… }    │
                                  └─────────────┬─────────────────┘
                                                │ ✏ = writer call
                                                ▼
                                  ┌──────────────────────────────┐
                                  │ TaskDirectoryWriterService    │
                                  │                               │
                                  │  writeNewTask()  ─────┐       │
                                  │  writeStatus()   ─────┼──> tmp│
                                  │  writeBlockedIf… ─────┘  +    │
                                  │                       rename  │
                                  │  in-memory: lastState[r,id]   │
                                  └─────────────┬─────────────────┘
                                                │
                                                ▼
              ~/.copland/tasks/<repo-dir>/<task-id>/
                  ├── task.md      (written once)
                  └── status.md    (rewritten on every transition;
                                    transitions table appends)
                                                │
                                                ▼ (polled by F5)
                                  ┌──────────────────────────────┐
                                  │ console-godot/TaskLoader.gd   │
                                  │ _read_frontmatter()           │
                                  └──────────────────────────────┘
```

### Recommended Project Structure
```
app/
├── Services/
│   ├── TaskDirectoryWriterService.php       # NEW — Phase 20
│   └── RunOrchestratorService.php           # MODIFIED — constructor + 9 call sites
├── Support/
│   ├── HomeDirectory.php                    # unchanged — reused
│   └── RunLogStore.php                      # unchanged — reference pattern
└── Commands/
    └── RunCommand.php                       # MODIFIED — instantiate writer, pass to orchestrator
```

### Pattern 1: Constructor-Injected Filesystem Service
**What:** A service class that owns a single filesystem responsibility, accepts callable/string seams for testability, and throws `RuntimeException` on filesystem errors.
**When to use:** Anywhere new filesystem write paths are added under `~/.copland/`.
**Example (synthesized from existing patterns — paraphrasing `GitService::__construct` + `RunLogStore::ensureDirectoryExists`):**
```php
// Source: synthesizing GitService($runner) seam + RunLogStore directory pattern
// [VERIFIED: app/Services/GitService.php:10, app/Support/RunLogStore.php:32-41]
namespace App\Services;

use App\Support\HomeDirectory;
use RuntimeException;

class TaskDirectoryWriterService
{
    /** @var array<string, string> Last state written per "repoDir/taskId" key */
    private array $lastState = [];

    public function __construct(
        private $clock = null,           // ?callable — defaults to gmdate('...Z')
        private ?string $homeOverride = null,
    ) {}

    public function writeNewTask(
        string $repoSlug,        // display value: "binarygary/copland" or "copland"
        string|int $taskId,
        ?string $title,
        ?string $body,
        string $repoPath,
        ?string $sourceUrl,
    ): void {
        $dir = $this->taskDir($repoSlug, $taskId);
        $this->ensureDirectoryExists($dir);

        $now = $this->now();
        $frontmatter = $this->renderFrontmatter([
            'id'         => (string) $taskId,
            'title'      => (string) ($title ?? ''),
            'repo_slug'  => $repoSlug,
            'repo_path'  => $repoPath,
            'source_url' => (string) ($sourceUrl ?? ''),
            'created_at' => $now,
        ]);
        $content = "---\n{$frontmatter}---\n\n" . (string) ($body ?? '') . "\n";

        $this->atomicWrite($dir . '/task.md', $content);
    }

    public function writeStatus(string $repoSlug, string|int $taskId, string $state): void
    {
        // ... renders frontmatter (state, updated_at) + appends transitions row ...
        // Tracks $this->lastState[...] = $state for writeBlockedIfNotTerminal.
    }

    public function writeBlockedIfNotTerminal(string $repoSlug, string|int $taskId): void
    {
        $key = $this->key($repoSlug, $taskId);
        $current = $this->lastState[$key] ?? null;
        if ($current === null || $current === 'pr_open' || $current === 'blocked') {
            return;
        }
        $this->writeStatus($repoSlug, $taskId, 'blocked');
    }

    private function now(): string
    {
        return $this->clock !== null
            ? (string) ($this->clock)()
            : gmdate('Y-m-d\TH:i:s\Z');
    }

    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path . '.tmp';
        $bytes = file_put_contents($tmp, $content);
        if ($bytes === false) {
            throw new RuntimeException("Failed to write {$tmp}");
        }
        // Optional: fopen + fflush + fsync for paranoid durability — see Common Pitfalls
        if (! rename($tmp, $path)) {
            throw new RuntimeException("Failed to rename {$tmp} -> {$path}");
        }
    }

    private function taskDir(string $repoSlug, string|int $taskId): string
    {
        $home = $this->homeOverride ?? HomeDirectory::resolve();
        $repoDir = str_replace('/', '__', $repoSlug);   // GH: "owner/repo" -> "owner__repo"
        return "{$home}/.copland/tasks/{$repoDir}/" . (string) $taskId;
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create task directory {$dir}");
        }
    }

    private function renderFrontmatter(array $pairs): string
    {
        $out = '';
        foreach ($pairs as $k => $v) {
            // TaskLoader handles bare AND quoted scalars; quote always for safety
            // against colons, leading hyphens, and special YAML chars in titles.
            $escaped = str_replace(["\\", "\""], ["\\\\", "\\\""], (string) $v);
            $out .= "{$k}: \"{$escaped}\"\n";
        }
        return $out;
    }

    private function key(string $repoSlug, string|int $taskId): string
    {
        return $repoSlug . '/' . (string) $taskId;
    }
}
```

> **Note on `repoSlug` and the dual-write contract for D-05:** the writer accepts `repoSlug` in *display form* (`owner/repo` or the Asana basename), then derives the filesystem-safe form internally via `str_replace('/', '__', $repoSlug)`. This means the caller (orchestrator) does NOT need to know about the `__` collapse — it just passes the GH slug or the basename. The display value also ends up verbatim in `task.md`'s `repo_slug` frontmatter, which is exactly what TaskLoader's `_read_task` reads at line 188 [VERIFIED: console-godot/scripts/TaskLoader.gd:188].

### Pattern 2: Insertion Points in RunOrchestratorService::run()

Read each item with reference to `app/Services/RunOrchestratorService.php` [VERIFIED by direct read].

| # | Transition | Insertion point (line range) | Anchor | Notes |
|---|------------|------------------------------|--------|-------|
| 1 | `new` (also writes task.md) | Between line 101 and line 103 (after the `$selectedIssue === null` early-return; before the `$snapshot` mutation) | After: `foreach ($prefiltered->accepted as $issue) { ... }` block at lines 79-84 finds the issue; before the `if ($snapshot !== null)` block at line 103. | This is the first point where `$selectedIssue` is guaranteed non-null. Single call: `$writer->writeNewTask(...)` then immediately `$writer->writeStatus($repo, $issue['number'], 'new')`. |
| 2 | `selected` | Immediately after #1, same block | Same as above | Per D-03, both `new` and `selected` are emitted in the pre-planning block — emit them on adjacent lines. |
| 3 | `planning` | Before line 112 (the `$plan = $this->planner->planTask(...)` call) | After pushLog `[3/8] Running Claude planner` (line 111) | One call: `$writer->writeStatus($repo, $selectedIssue['number'], 'planning')`. |
| 4 | `planned` | After line 161 (the `return $result;` for validation failure) — i.e., inside the success branch, after line 163 (`$this->pushLog('      Plan validated OK');`) | After `Plan validated OK` log | Only written if validation passed. |
| 5 | `executing` | Before line 175 (the `$executionResult = $this->executor->executeWithRepoProfile(...)` call) | After pushLog `[6/8] Running Claude executor` (line 174) | One call. |
| 6 | `verifying` | Before line 214 (the `$verificationResult = $this->verifier->verify(...)` call) | After pushLog `[7/8] Running verification` (line 213) | One call. |
| 7 | `pr_open` | After line 263 (`$this->pushLog("      Draft PR opened: {$prUrl}");`) | After the draft-PR-opened log line | Per D-03, written AFTER `gh pr create` returns successfully with the URL. |
| 8 | `blocked` (conditional) | Inside the `finally` block (lines 300-320), specifically after the existing `if (isset($workspacePath) && $workspacePath !== null)` cleanup branch (lines 301-308) and BEFORE the run-log append branch (lines 310-319) | The `finally` arm | Call: `if ($selectedIssue !== null) { $writer->writeBlockedIfNotTerminal($repo, $selectedIssue['number']); }`. Must guard on `$selectedIssue` not being null (e.g., the selector returned `skip_all` — no task was ever selected, so no status to update). |

**Total orchestrator surface change:** ~10–12 new lines in `run()` + 1 new constructor parameter. Net ~30-40 lines per CONTEXT.md's estimate is accurate.

### Anti-Patterns to Avoid
- **Symfony YAML for output:** As discussed in Don't Hand-Roll — `Yaml::dump()` may emit constructs (multi-line scalars with `|`, quoted nested mappings) that TaskLoader's parser ignores or misreads. Hand-roll the 5-7 key/value pairs.
- **Writing `task.md` more than once:** D-08 says `task.md` is singular. Don't add "refresh" or "update" semantics. If the title changes, `status.md` is where transition timestamps live; `task.md` is the immutable creation record.
- **Broadcasting writes via `pushLog`:** D-12 / Specifics — keep the writer silent. The orchestrator already narrates `[3/8] Running Claude planner` etc. Double-narration clutters overnight logs.
- **Writing `blocked` after a successful `pr_open` path:** The `writeBlockedIfNotTerminal` helper exists precisely to gate this. Don't unconditionally write `blocked` from `finally` — only when the last state was non-terminal.
- **Per-source branching inside the writer:** GitHub vs Asana differences (slug derivation, source URL synthesis) are resolved AT THE CALL SITE in the orchestrator. The writer accepts pre-resolved `repoSlug`, `repoPath`, `sourceUrl` strings and stays source-agnostic.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HOME directory resolution | Custom `$_SERVER['HOME']` lookup | `HomeDirectory::resolve()` | Already exists, already has `posix_getpwuid` fallback, already tested. [VERIFIED: app/Support/HomeDirectory.php:17-39] |
| Recursive directory creation | Custom `mkdir` loop | `mkdir($path, 0755, true)` with `is_dir($path)` fallback check | Standard PHP, matches `RunLogStore::ensureDirectoryExists()` [VERIFIED: app/Support/RunLogStore.php:32-41] |
| Timestamp formatting | Custom date/time logic | `gmdate('Y-m-d\TH:i:s\Z')` | ISO-8601 UTC with literal `Z` per Specifics in CONTEXT.md; matches existing JSONL log |
| Atomic file write | Lock-based or `flock`-based custom | Tmp file + `rename()` | POSIX guarantees `rename()` atomicity on same filesystem. ~10 lines, no dependency. |

**However, intentionally hand-roll these (don't reach for a library):**

| Problem | Don't Use | Hand-Roll | Why |
|---------|-----------|-----------|-----|
| YAML frontmatter generation | `Symfony\Component\Yaml::dump()` | 5-line `foreach` emitting `key: "value"` lines | TaskLoader's frontmatter parser is intentionally minimal — only top-level scalar pairs, no nesting, no multi-line scalars. [VERIFIED: console-godot/scripts/TaskLoader.gd:218-258 — only handles `colon = stripped.find(":")`-then-substring, with trim of leading `'` or `"`.] symfony/yaml may emit folded/literal blocks (`>` / `\|`) for long body-like strings, or quote in ways that look different but parse identically to the dumper — TaskLoader's parser only strips one pair of surrounding quotes (lines 255-256) and treats whatever's left as the value. Hand-rolling guarantees the exact byte layout matches what TaskLoader expects. The body text goes BELOW the closing `---`, not inside frontmatter, so the multi-line-scalar problem doesn't apply to the body. |
| Markdown transitions table append | `league/commonmark` or similar | A small string template appending `\| {ts} \| {state} \|\n` | The transitions table is 3 columns of plain text. A library is overkill and would obscure the intent. |

**Key insight:** TaskLoader's parser is deliberately weak (~40 lines of GDScript) — it expects exactly what the writer emits. Coupling the writer to a general-purpose YAML library is a future-drift risk; hand-rolled output is auditable in ~15 lines and impossible to misalign.

## Runtime State Inventory

> Phase 20 is mostly greenfield (adds a writer to a never-before-written directory). Inventory included for completeness given that one CONTEXT.md decision (D-16) explicitly preserves an existing data path.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None in `~/.copland/tasks/`. The existing `~/.copland/logs/runs.jsonl` continues unchanged per D-16. No databases. | None — phase is additive. |
| Live service config | None — no external services configured per-task. The Godot console reads `~/.copland/tasks/` but is launched ad-hoc; no daemon, no service registration. | None. |
| OS-registered state | None — no launchd / cron registrations touch `~/.copland/tasks/`. `LaunchdPlist` support class manages a single `copland` plist [VERIFIED: app/Support/LaunchdPlist.php exists] but does not reference task directories. | None. |
| Secrets / env vars | None new. Existing `$_SERVER['HOME']` lookup is reused via `HomeDirectory::resolve()`. The writer's `$homeOverride` parameter (D-13) is for testing only and doesn't introduce a new env var. | None. |
| Build artifacts / installed packages | None — no new composer packages, no new generated files. The PHAR build (`box.json`) just picks up the new `.php` file under `app/Services/` automatically. | None. |

**Confirmed greenfield:** Grep over `/Users/garykovar/projects/codeable/copland/app/` for `~/.copland/tasks/` returns ONE file: `ConsoleCommand.php` line 12 (the description string `'Launch the Copland Console (Godot 4.2+ GUI pointed at ~/.copland/tasks/)'`). No PHP code writes to that path today. [VERIFIED: ripgrep over `app/`]

## Common Pitfalls

### Pitfall 1: Forgetting to guard the `blocked` write on `$selectedIssue !== null`
**What goes wrong:** The orchestrator catches an exception from Step 1 (the `fetchTasks` call) before `$selectedIssue` is ever set. The `finally` block tries to write `blocked` and crashes inside the writer because `$selectedIssue['number']` is undefined.
**Why it happens:** Step 1 (line 50, `$this->taskSource->fetchTasks(...)`) can throw GitHub-API errors, network errors, etc. Step 2 (line 56, selector call) can also throw before line 79 ever runs.
**How to avoid:** Wrap the `writeBlockedIfNotTerminal` call in `if ($selectedIssue !== null)` inside the `finally` block. The selector's "skip_all" decision also returns BEFORE setting `$selectedIssue`, so this guard covers both paths.
**Warning signs:** Any test that simulates a pre-selection failure will fail noisily on the finally call.

### Pitfall 2: `rename()` failing across filesystems
**What goes wrong:** `rename()` is only atomic when source and target are on the same filesystem. If `~/.copland/` happens to be on a different mount (e.g., a developer with `~/` on a network drive), `rename()` may fall back to a copy+delete sequence that is NOT atomic.
**Why it happens:** Cross-mount renames. Vanishingly rare for typical Mac/Linux developer setups where `~/.copland/` lives directly under `$HOME`.
**How to avoid:** Always write `.tmp` files INTO the same directory as the destination (not into `sys_get_temp_dir()`). The example pattern above does this correctly (`$tmp = $path . '.tmp'`).
**Warning signs:** Unit tests that pin HOME to a path on a different mount than the actual repo will exhibit subtle race conditions.

### Pitfall 3: Treating GitHub's `body` field as guaranteed non-null
**What goes wrong:** A GitHub issue with no description has `body: null` in the API response. Passing `null` to `file_put_contents` after string concatenation triggers a `TypeError` in PHP 8.2+ (strict types) or silent coercion to `""` (looser modes).
**Why it happens:** `getIssues()` returns the GitHub API response verbatim [VERIFIED: app/Services/GitHubService.php:44-53] — `body` may be `null`.
**How to avoid:** Cast in the writer: `(string) ($body ?? '')`. The example pattern above already does this.
**Warning signs:** Crash on first run against an issue with no description.

### Pitfall 4: TaskLoader status.md parser stops at the first blank line
**What goes wrong:** Per TaskLoader lines 240-249, `status.md` is allowed to have NO leading `---` — the parser treats the leading scalar block as frontmatter and stops at the first blank line. The transitions table (which starts with `## Transitions` then a blank line then the table) MUST come AFTER the closing `---` of an explicit frontmatter block, otherwise the parser will treat the `##` line as frontmatter and try to read `## Transitions` as a key.
**Why it happens:** TaskLoader's `_read_frontmatter` (lines 218-258) handles both `---`-delimited and `---`-less frontmatter for resilience. If we omit the closing `---`, anything after the blank line is invisible to Godot, but anything BEFORE the blank line could be misparsed as a key.
**How to avoid:** ALWAYS emit explicit `---` delimiters around frontmatter, then ONE blank line, then the markdown body. The example pattern above does this. Do NOT skip the closing `---`.
**Warning signs:** Godot shows the task with state `## Transitions` or similar garbage if the closing delimiter is missed.

### Pitfall 5: `repoSlug` containing characters that break the filesystem path
**What goes wrong:** A theoretical `owner` with a dot or a leading hyphen could survive the `/` → `__` collapse but still cause path issues. Asana repos with the basename "." or ".." would be disastrous.
**Why it happens:** GitHub `owner/repo` is well-constrained (alphanumeric + `-` + `_`, no slashes after the first one). Asana basenames come from registered local paths which are user-controlled.
**How to avoid:** GitHub case is safe by GitHub's own naming rules. For Asana, the registered `repo_path` is user-supplied (`.copland.yml`); the basename is unlikely to be malicious but COULD be empty (if path ends in `/`) or be `.`. A defensive check (`if ($repoSlug === '' || $repoSlug === '.' || $repoSlug === '..') throw …`) is cheap insurance.
**Warning signs:** Tests with edge-case paths reveal it.

### Pitfall 6: Asana tasks have no `html_url` — synthesize or accept empty
**What goes wrong:** `AsanaService::getOpenTasks()` returns `['number' => $gid, 'title' => ..., 'body' => ..., 'labels' => [...]]` — no `html_url` key. [VERIFIED: app/Services/AsanaService.php:46-53]. Reading `$selectedIssue['html_url']` for an Asana task is undefined-index.
**Why it happens:** Asana's API exposes a permalink via `permalink_url` (not currently requested in `opt_fields`) or it can be synthesized as `https://app.asana.com/0/{projectGid}/{taskGid}`. Neither is currently in `$selectedIssue`.
**How to avoid:** At the orchestrator call site, branch on source: `$sourceUrl = $selectedIssue['html_url'] ?? null;`. Document this gap in the writer — `sourceUrl` is allowed to be `null` / empty string for Asana sources. The writer should write the key as `source_url: ""` rather than omit it, for schema stability. **Alternative (Claude's discretion):** add `permalink_url` to `AsanaService::getOpenTasks()`'s `opt_fields` query and propagate it as `html_url`. This is a small, additive Asana change — but it crosses Phase 20's scope into Asana service modification. Recommend keeping Phase 20 minimal and writing `source_url: ""` for Asana; file a follow-up if the console needs it.
**Warning signs:** Asana tasks render with no clickable issue link in the console.

## Code Examples

### Computing `repoDir` from `$repo` (the slug passed into `run()`)
```php
// Source: synthesizing CONTEXT.md D-05 + D-06 + existing RunOrchestrator signature
// [VERIFIED: app/Services/RunOrchestratorService.php:33 — first param is `string $repo`]
// $repo is "owner/repo" for GitHub, or whatever the orchestrator received for Asana
// (currently the orchestrator is GitHub-centric in its $repo handling; Asana's repo
//  comes through as a slug too, since the Asana-path-basename is computed at
//  $repoProfile['repo_path'] time)

// At call site #1 inside run(), after $selectedIssue is non-null:
$repoSlug = $repo;                                      // display form, e.g. "binarygary/copland"
$taskId   = $selectedIssue['number'];                   // int (GH) or string (Asana)
$title    = $selectedIssue['title'] ?? '';
$body     = $selectedIssue['body'] ?? '';
$repoPath = $repoProfile['repo_path'];                  // [VERIFIED: line 286 of RunCommand.php]
$sourceUrl = $selectedIssue['html_url'] ?? '';          // empty for Asana — see Pitfall 6

$writer->writeNewTask($repoSlug, $taskId, $title, $body, $repoPath, $sourceUrl);
$writer->writeStatus($repoSlug, $taskId, 'new');
$writer->writeStatus($repoSlug, $taskId, 'selected');
```

### Finally-block insertion
```php
// Source: synthesizing CONTEXT.md D-11 + existing finally block
// [VERIFIED: app/Services/RunOrchestratorService.php:300-320]
} finally {
    // ─── existing workspace cleanup (lines 301-308) ──────────────
    if (isset($workspacePath) && $workspacePath !== null) {
        try {
            $this->workspace->cleanup($repoPath, $workspacePath);
            $this->pushLog('      Run finished in current checkout');
        } catch (\Exception $e) {
            $this->pushLog("      Warning: cleanup step failed: {$e->getMessage()}");
        }
    }

    // ─── NEW (Phase 20): write blocked if non-terminal ──────────
    if ($selectedIssue !== null) {
        try {
            $this->writer->writeBlockedIfNotTerminal($repo, $selectedIssue['number']);
        } catch (\Throwable $e) {
            $this->pushLog("      Warning: blocked-state write failed: {$e->getMessage()}");
        }
    }

    // ─── existing run-log append (lines 310-319) ────────────────
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

> **Note on error handling inside finally:** Wrap the `writeBlockedIfNotTerminal` call in its own `try/catch` — never let a writer error mask the original exception that's propagating out of the `try` block. This matches the existing pattern at lines 305-307 and 317-319.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| No task-level persistence; only `~/.copland/logs/runs.jsonl` (post-run JSONL) | Live `task.md` + `status.md` per task, polled by Godot console | Phase 20 (this phase) | Console can render real run state without parsing the JSONL log; the JSONL log stays as the canonical audit trail (D-16). |
| Per-source branching for filesystem layout | Source-agnostic writer, branching at call site | Phase 20 | Writer signature stays simple; orchestrator handles GH vs Asana shape differences before calling. |

**Deprecated/outdated:** None — phase is purely additive.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Asana tasks should write `source_url: ""` rather than synthesize a permalink. The alternative — adding `permalink_url` to `AsanaService::getOpenTasks()`'s `opt_fields` — is in CONTEXT.md's Claude's Discretion but feels like a Phase 17 retroactive change. Recommend writing empty and revisiting in Phase 22/v2.1. | Pitfall 6 + Code Examples | Asana tasks render in the console with no clickable issue link. Easy follow-up. |
| A2 | The `finally` block's existing structure does NOT need refactoring — the new `blocked` write fits as a third arm between cleanup and run-log append. [VERIFIED by reading lines 300-320 of RunOrchestratorService.php — three independent `try`/`catch` clauses already coexist there.] | Code Examples | If the orchestrator's `finally` is restructured in a later phase, the new arm's relative position must be re-verified. |
| A3 | The writer should write `source_url` as a frontmatter key even though TaskLoader's required minimum is `id`, `title`, `repo_slug`, `repo_path`, `created_at`. Rationale: success criterion 1 says "task.md containing the task title, body, repo slug, repo path, source URL, and `created_at` timestamp" — `source_url` IS required by the phase's own success criterion, but TaskLoader is permissive about unknown keys (it ignores them). So write it for the human-readability + future-proofing reasons; TaskLoader won't reject the extra key. | Code Examples | Negligible — TaskLoader skips unknown keys. |
| A4 | `body` should be the markdown document body BELOW the frontmatter closing `---`, NOT a frontmatter scalar. Rationale: D-08 says "The issue/task body text is also rendered as the markdown document body below `---`" — explicit. TaskLoader does not read the body anyway (only frontmatter), so this is purely for human inspection. | Code Examples | None — readers ignore the body. |

**If this table is empty:** Not applicable — A1 is a meaningful assumption that should be confirmed during planning or surfaced at execution.

## Open Questions

1. **Should Phase 20 fix Asana's missing source URL?**
   - What we know: Asana tasks have no `html_url` in the current `$selectedIssue` shape [VERIFIED]. The Asana permalink format is well-known (`https://app.asana.com/0/{projectGid}/{taskGid}`).
   - What's unclear: Whether the Phase 20 planner should add `permalink_url` to `AsanaService::getOpenTasks()`'s opt_fields and propagate it, or keep Phase 20 minimal and write `source_url: ""` for Asana.
   - Recommendation: Per A1, write `source_url: ""` and file a follow-up. Phase 20 is already touching 2 files (writer + orchestrator + RunCommand wiring = 3) and adding Asana-source churn pushes it close to the 3-files / 250-line budget.

2. **Should Phase 20 include even smoke-level tests?**
   - What we know: D-15 says comprehensive tests are Phase 21; smoke tests in Phase 20 are optional.
   - What's unclear: Whether the team wants any test confidence before merging Phase 20.
   - Recommendation: Add one Pest test exercising the happy path of `TaskDirectoryWriterService::writeNewTask()` + `writeStatus()` against a temp HOME. ~30 lines. Tiny budget impact, big regression-safety win, mirrors `RunLogStoreTest.php`'s structure. Phase 21's deeper coverage (lifecycle, failure paths, frontmatter parsing round-trip) remains its deliverable.

3. **Optional `blocked_reason` frontmatter key?**
   - What we know: CONTEXT.md notes this as Claude's discretion; would store the exception message on `blocked` writes.
   - What's unclear: Whether the Godot console will surface it (TaskLoader currently doesn't read it but would ignore it).
   - Recommendation: Add it as an optional parameter to `writeStatus()` (default `null`, only emitted when non-null on the `blocked` transition). 3 extra lines. Future-proofs the console.

## Environment Availability

> Phase 20 has no external dependencies beyond PHP standard library + already-installed composer packages. Section included for completeness.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All code | ✓ | per composer.json `"php": "^8.2"` | none — hard requirement |
| `symfony/yaml` | NOT needed for output — see Don't Hand-Roll | ✓ | ^8.0 (direct dep) | hand-rolled |
| `App\Support\HomeDirectory` | Writer HOME resolution | ✓ | in-tree | n/a |
| `App\Support\RunLogStore` | Reference pattern only | ✓ | in-tree | n/a |
| Godot 4.2+ | NOT needed for Phase 20 (read-side; Phase 19 dependency) | n/a | n/a | n/a |

**Missing dependencies with no fallback:** None.
**Missing dependencies with fallback:** None.

## Validation Architecture

> Included per default (no `workflow.nyquist_validation: false` override observed in repo).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest (PHPUnit-based) — already in tree |
| Config file | `tests/Pest.php`, `phpunit.xml` (Laravel Zero conventions) |
| Quick run command | `./vendor/bin/pest --filter=TaskDirectoryWriter` |
| Full suite command | `./vendor/bin/pest` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TASK-01 | `writeNewTask()` creates `~/.copland/tasks/<repo>/<id>/task.md` with required + optional frontmatter keys and body | unit | `./vendor/bin/pest tests/Unit/TaskDirectoryWriterServiceTest.php` | ❌ Wave 0 (Phase 21 owns comprehensive; Phase 20 optionally adds smoke) |
| TASK-02 | `writeStatus()` rewrites frontmatter + appends transitions row; `writeBlockedIfNotTerminal()` writes `blocked` only when last state non-terminal | unit | `./vendor/bin/pest tests/Unit/TaskDirectoryWriterServiceTest.php` | ❌ Wave 0 (same) |
| TASK-02 (orchestrator wiring) | Orchestrator calls writer at all 8 lifecycle points and once in finally | unit | `./vendor/bin/pest tests/Unit/RunOrchestratorServiceTest.php` (extend existing) | ✅ exists — extend with assertions on writer mock |

### Sampling Rate
- **Per task commit:** `./vendor/bin/pest --filter=TaskDirectoryWriter` (writer-focused, < 1s)
- **Per wave merge:** `./vendor/bin/pest` (full suite, 132+ tests per `.planning/codebase/TESTING.md`-style baseline)
- **Phase gate:** Full suite green; PHPStan level 5 clean; manual smoke (`copland console` against a real `~/.copland/tasks/` populated by a one-shot run)

### Wave 0 Gaps
- [ ] `tests/Unit/TaskDirectoryWriterServiceTest.php` — covers TASK-01 + TASK-02 happy path (Phase 21 comprehensive; Phase 20 may include smoke). The Phase-20 minimum is one happy-path test that exercises `writeNewTask` + `writeStatus(new)` + `writeStatus(blocked)` against a temp HOME, using the `$_SERVER['HOME']` swap idiom from `RunLogStoreTest.php`.
- [ ] Extend `tests/Unit/RunOrchestratorServiceTest.php` — inject a Mockery writer mock through the orchestrator constructor; assert `writeNewTask`, `writeStatus`, and `writeBlockedIfNotTerminal` are called with the expected arguments in the expected order along the happy path AND a representative failure path (e.g., verification fails → blocked).

## Security Domain

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — (writer is offline; no network calls) |
| V3 Session Management | no | — |
| V4 Access Control | partially | Files created under `~/.copland/tasks/` with mode 0755 directories. Files inherit umask. **Recommendation:** since `~/.copland/` may contain issue bodies that include sensitive information (private repo descriptions, API keys leaked into issue text), consider `0700` for the `tasks/` root and `0600` for the files. Discuss with planner; this is a tightening that matches `~/.ssh/` conventions. |
| V5 Input Validation | yes | The writer accepts user-derived inputs (issue title, body) and writes them to disk. **Risk:** an issue body containing the literal byte sequence `\n---\n` followed by YAML could trick a *future* writer that re-reads the file. TaskLoader doesn't re-read into structured data outside frontmatter, but defensive encoding (e.g., escaping `\` and `"` inside frontmatter scalars — the example pattern does this) prevents YAML-injection-style mishaps. The body content below `---` is unvalidated by design (it's a markdown document; users render it visually). |
| V6 Cryptography | no | — (no secrets, no signing) |

### Known Threat Patterns for PHP filesystem writes

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Path traversal via `$taskId` or `$repoSlug` | Tampering | `$taskId` is GH int or Asana GID (alphanumeric); `$repoSlug` derives from GH `owner/repo` (constrained) or local-path basename (user-controlled `.copland.yml`). Add a defensive check rejecting `..`, `/`, leading `.`, or empty values in the writer's `taskDir()` helper. |
| TOCTOU on directory creation | Tampering | `mkdir($path, 0755, true) && ! is_dir($path)` already accounts for concurrent creation (matches `RunLogStore::ensureDirectoryExists` [VERIFIED: app/Support/RunLogStore.php:38]). |
| Symlink attack at the tmp-rename target | Tampering | Tmp file is created in the same directory as the target, which is under `~/.copland/tasks/` — only the user can write there. Risk negligible for a personal-machine tool; flag for hardening if Copland ever runs as a service. |
| Information disclosure via `0644` mode | Information Disclosure | See ASVS V4 above — recommend tightening to `0700` / `0600`. |

## Sources

### Primary (HIGH confidence)
- `/Users/garykovar/projects/codeable/copland/console-godot/scripts/TaskLoader.gd` lines 20-30 (STATES), lines 177-209 (`_read_task` reading exactly `id, title, repo_slug, repo_path, created_at, state, updated_at`), lines 218-258 (frontmatter parser — top-level scalars only, strips one pair of surrounding quotes)
- `/Users/garykovar/projects/codeable/copland/app/Services/RunOrchestratorService.php` lines 33 (`run()` signature with `$repoProfile`), 79-108 (selection block), 166 (`$repoProfile['repo_path']` consumption), 295-320 (try/catch/finally)
- `/Users/garykovar/projects/codeable/copland/app/Support/RunLogStore.php` lines 27-41 (`HomeDirectory::resolve()` reuse, `ensureDirectoryExists` idiom)
- `/Users/garykovar/projects/codeable/copland/app/Support/HomeDirectory.php` lines 17-39 (canonical resolver)
- `/Users/garykovar/projects/codeable/copland/app/Services/GitService.php` line 10 (callable seam pattern: `private $runner = null`)
- `/Users/garykovar/projects/codeable/copland/app/Services/AsanaService.php` lines 46-53 (Asana task shape — no `html_url`)
- `/Users/garykovar/projects/codeable/copland/app/Services/GitHubService.php` line 44-53 (GitHub issue API — `body` may be null)
- `/Users/garykovar/projects/codeable/copland/app/Commands/RunCommand.php` lines 276-301 (composition root — `repoProfile` construction with `'repo_path' => $path`, orchestrator instantiation)
- `/Users/garykovar/projects/codeable/copland/tests/Unit/RunLogStoreTest.php` lines 1-55 (temp-HOME pattern using `$_SERVER['HOME']`)
- `/Users/garykovar/projects/codeable/copland/tests/Unit/RunOrchestratorServiceTest.php` lines 28-422 (orchestrator test fixture style; `makeIssue()` shape at lines 415-422)
- `/Users/garykovar/projects/codeable/copland/composer.json` line 23 (`symfony/yaml: ^8.0`)
- `/Users/garykovar/projects/codeable/copland/.planning/phases/20-task-status-writer/20-CONTEXT.md` (D-01..D-17, all decisions verified against source)

### Secondary (MEDIUM confidence)
- Asana permalink format `https://app.asana.com/0/{projectGid}/{taskGid}` — well-known convention; not verified in this session via API call, but matches public Asana docs (training knowledge). [ASSUMED]

### Tertiary (LOW confidence)
- None.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every claim cited from in-tree source. No new packages, so no slopsquatting risk.
- Architecture: HIGH — insertion points read directly from `RunOrchestratorService.php`; `$repoProfile['repo_path']` confirmed at both construction (RunCommand.php:286) and consumption (RunOrchestratorService.php:166).
- Pitfalls: HIGH — all six pitfalls have source-level evidence (GitHub null body in API; Asana shape verified; TaskLoader parser corner cases verified by reading lines 240-249 of the .gd file).
- Asana source URL handling: MEDIUM — recommendation (A1) is a design judgment; the underlying gap (no `html_url`) is verified.

**Research date:** 2026-05-27
**Valid until:** 2026-06-26 (~30 days — codebase is stable; the only churn risk is a Phase 21 refactor of the orchestrator's finally block, which would invalidate insertion-point line numbers but not the overall design)

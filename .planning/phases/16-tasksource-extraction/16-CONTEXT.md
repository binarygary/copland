# Phase 16: TaskSource Extraction - Context

**Gathered:** 2026-04-08
**Status:** Ready for planning

<domain>
## Phase Boundary

Introduce a `TaskSource` interface covering all `GitHubService` call sites in `RunOrchestratorService`. Wrap the existing `GitHubService` in a `GitHubTaskSource` implementation. Inject `TaskSource` into the orchestrator — it no longer references `GitHubService` directly. No behavior change. Enables Phase 17 to add `AsanaTaskSource` without touching the orchestrator.

</domain>

<decisions>
## Implementation Decisions

### Interface Scope

- **D-01:** `TaskSource` covers **all 6 `GitHubService` call sites** in `RunOrchestratorService`:
  1. `fetchTasks()` — issue/task fetch (Step 1)
  2. `addComment()` — failure comment after executor failure (Step 6)
  3. `addComment()` — failure comment after verification failure (Step 7)
  4. `openDraftPr()` — create draft PR (Step 11)
  5. `removeTag()` — remove label/tag after PR creation (Step 11)
  6. `addComment()` — success comment with PR link (Step 12)

  Rationale: success criterion 3 requires orchestrator to have zero direct `GitHubService` references. Full abstraction now means Phase 17's `AsanaTaskSource` is self-contained (it can inject `GitHubService` internally for PR creation).

- **D-02:** `RunOrchestratorService` constructor removes `GitHubService $github` and adds `TaskSource $taskSource`. No other constructor changes.

### Method Naming

- **D-03:** Interface uses **generic, task-neutral method names**:

```php
interface TaskSource {
    /** @param string[] $tags */
    public function fetchTasks(string $repo, array $tags): array;

    public function addComment(string $repo, string|int $taskId, string $body): void;

    public function openDraftPr(string $repo, string $branch, string $title, string $body): array;

    public function removeTag(string $repo, string|int $taskId, string $tag): void;
}
```

  Rationale: `removeLabel` and `commentOnIssue` leak GitHub vocabulary. Generic names (`fetchTasks`, `addComment`, `openDraftPr`, `removeTag`) map cleanly to both GitHub Issues and Asana in Phase 17. Rename cost in Phase 16 is 6 orchestrator call sites — trivial.

- **D-04:** `taskId` parameter type is `string|int` throughout (not just `int`) — forwards-compatible with Asana GIDs which are strings (per existing STATE.md decision: "Asana GIDs handled as strings throughout pipeline").

### GitHubTaskSource Implementation

- **D-05:** `GitHubTaskSource` wraps the existing `GitHubService` and implements `TaskSource`. It is a thin delegation layer — no logic, just forwarding calls:
  - `fetchTasks()` → `GitHubService::getIssues()`
  - `addComment()` → `GitHubService::commentOnIssue()`
  - `openDraftPr()` → `GitHubService::createDraftPr()`
  - `removeTag()` → `GitHubService::removeLabel()`

- **D-06:** `GitHubService` itself is **unchanged** — no renamed methods, no interface added to it. It remains the concrete GitHub API client. `GitHubTaskSource` is a new wrapper class.

### Test Strategy

- **D-07:** Orchestrator tests (`RunOrchestratorServiceTest`) are updated to **mock `TaskSource` directly** (interface-level mock). Method names update to match D-03 generic names:
  - `getIssues` → `fetchTasks`
  - `commentOnIssue` → `addComment`
  - `createDraftPr` → `openDraftPr`
  - `removeLabel` → `removeTag`
  Constructor arg name changes from `github:` to `taskSource:`.

- **D-08:** `GitHubServiceTest` (Feature test) is **unchanged** — it tests `GitHubService` directly and `GitHubService` has no changes.

- **D-09:** A new `GitHubTaskSourceTest` (Unit test) verifies the delegation layer — each `TaskSource` method calls the correct `GitHubService` method with the correct arguments. This is a small, focused test covering the 4 delegation paths.

### AppServiceProvider Wiring

- **D-10:** `AppServiceProvider` binds `TaskSource::class` to `GitHubTaskSource::class`. `GitHubTaskSource` is resolved with `GitHubService` injected via the container.

### Claude's Discretion

- Namespace placement for `TaskSource` and `GitHubTaskSource` — follow Phase 14 pattern (`App\Contracts\TaskSource`, `App\Services\GitHubTaskSource` or `App\Support\GitHubTaskSource`)
- Whether `openDraftPr` return type is `array` or a typed value object — follow existing `createDraftPr` return convention
- Named constructor argument in orchestrator tests (`taskSource:` vs positional) — follow existing test conventions

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Orchestrator — primary change target
- `app/Services/RunOrchestratorService.php` — All 6 `GitHubService` call sites; constructor to update; orchestrator logic unchanged

### GitHub service — unchanged but wrapped
- `app/Services/GitHubService.php` — Existing method signatures that `GitHubTaskSource` delegates to; do not modify

### DI wiring
- `app/Providers/AppServiceProvider.php` — Bind `TaskSource::class`; follow Phase 14/15 pattern for interface bindings

### Tests — orchestrator tests need updating; GitHub test unchanged
- `tests/Unit/RunOrchestratorServiceTest.php` — Update mock type and method names per D-07
- `tests/Feature/GitHubServiceTest.php` — Must pass unchanged (D-08)

### Prior phase patterns
- `.planning/phases/14-llmclient-contracts/14-CONTEXT.md` — Interface introduction pattern; `App\Contracts\` namespace; constructor injection

### Requirements
- `.planning/REQUIREMENTS.md` — Phase 16 has no direct requirements (structural refactor enabling ASANA-01 through ASANA-05 in Phase 17)

</canonical_refs>

<code_context>
## Existing Code Insights

### GitHubService call sites (exact methods to abstract)
- `getIssues(string $repo, array $labels): array` → `fetchTasks()`
- `commentOnIssue(string $repo, int $number, string $body): void` → `addComment()`
- `createDraftPr(string $repo, string $branch, string $title, string $body): array` → `openDraftPr()`
- `removeLabel(string $repo, int $number, string $label): void` → `removeTag()`

### Orchestrator test mock pattern (current → updated)
```php
// Current
$github = \Mockery::mock(GitHubService::class);
$github->shouldReceive('getIssues')->once()->andReturn([$issue]);
// ...
github: $github,

// After Phase 16
$taskSource = \Mockery::mock(TaskSource::class);
$taskSource->shouldReceive('fetchTasks')->once()->andReturn([$issue]);
// ...
taskSource: $taskSource,
```

### Established Patterns
- `App\Contracts\` for interfaces (Phase 14: `LlmClient`)
- `final class` with constructor injection for service wrappers
- `AppServiceProvider::register()` for container bindings

</code_context>

<specifics>
## Specific Decisions

- User chose **full abstraction (A1)**: all 6 GitHub call sites in the interface — orchestrator has zero direct GitHubService references
- User chose **generic method names (B2)**: `fetchTasks`, `addComment`, `openDraftPr`, `removeTag` — not GitHub-shaped names
- User chose **mock TaskSource directly (C1)**: orchestrator tests import and mock the interface, not the concrete class
- `taskId` is `string|int` throughout — Asana GID compatibility from day one

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 16-tasksource-extraction*
*Context gathered: 2026-04-08*

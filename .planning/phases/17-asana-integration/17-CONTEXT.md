# Phase 17: Asana Integration - Context

**Gathered:** 2026-04-08
**Status:** Ready for planning

<domain>
## Phase Boundary

Implement `AsanaService`, `AsanaTaskSource`, config mapping (global + per-repo), and PR link comment-back. Users can configure Asana projects as a task source per repo; Copland fetches open Asana tasks, runs the same code pipeline, and posts the resulting PR link back as an Asana comment. The `TaskSource` interface already exists from Phase 16 — this phase provides the Asana implementation.

</domain>

<decisions>
## Implementation Decisions

### Config Schema

- **D-01:** Asana PAT is stored at the **top level** of `~/.copland.yml` as `asana_token`. Mirrors `claude_api_key` placement — consistent with existing top-level credential keys.

- **D-02:** Asana project-to-repo mapping is **nested under the existing `repos:` entries** in `~/.copland.yml`:
  ```yaml
  repos:
    - slug: owner/repo
      path: /path/to/repo
      asana_project: "1234567890"      # Asana project GID (string)
      asana_filters:
        tags: [agent-ready]            # task must have ALL these tags (optional)
        section: Backlog               # task must be in this section (optional)
  ```
  This keeps all per-repo config in one entry. `asana_project` and `asana_filters` are optional; if absent, the repo uses GitHub Issues.

- **D-03:** Task source is activated per repo via `task_source: asana` in the **repo-level `.copland.yml`** (repo root). Copland defaults to `github` if the key is absent — no breaking change for existing repos:
  ```yaml
  # .copland.yml (repo root)
  task_source: asana
  base_branch: main
  max_executor_rounds: 12
  ```

### Filtering (ASANA-03)

- **D-04:** Both `tags` and `section` filtering are supported **independently**. Either is optional; if both are configured, both must match (AND logic).
  - `tags: [agent-ready]` — task must have ALL listed tags
  - `section: Backlog` — task must be in the named section

- **D-05:** Filtering is applied **client-side in PHP** after fetching all incomplete tasks from the project. Copland fetches from `/projects/{gid}/tasks?completed_since=now` (open tasks), then filters by tag names and section name in PHP. Simpler than Asana's search API; sufficient for personal-scale projects.

### SelectionResult Widening

- **D-06:** `SelectionResult::$selectedIssueNumber` is **renamed to `$selectedTaskId`** and type-widened from `?int` to `string|int|null`. This is a field rename + type change:
  ```php
  // Before
  public readonly ?int $selectedIssueNumber,

  // After
  public readonly string|int|null $selectedTaskId,
  ```
  All call sites using `->selectedIssueNumber` must be updated. This touches: `ClaudeSelectorService`, `RunOrchestratorService`, `PlanCommand`, any prompt templates that reference the issue number, and tests.

### AsanaTaskSource Design

- **D-07:** `AsanaTaskSource` holds **both `AsanaService` and `GitHubService`** internally:
  - `fetchTasks()` → `AsanaService` (fetch + filter Asana tasks)
  - `addComment()` → `AsanaService` (post comment to Asana task)
  - `openDraftPr()` → `GitHubService::createDraftPr()` (PRs are always GitHub)
  - `removeTag()` → `AsanaService` (remove tag from Asana task, if applicable; may be a no-op or not implemented if Asana has no equivalent)

  This was anticipated in Phase 16 ("AsanaTaskSource can inject GitHubService internally for PR creation"). `AppServiceProvider` binds `TaskSource::class` to `AsanaTaskSource::class` when `task_source: asana` is configured.

- **D-08:** `AsanaService` constructs a `GuzzleHttp\Client` internally with the Asana API base URL (`https://app.asana.com/api/1.0`) and PAT auth header. Follows the `GitHubService` constructor pattern. No new HTTP client dependencies.

- **D-09:** `AppServiceProvider` binding for `TaskSource` is conditional: reads `task_source` from `RepoConfig` and binds either `GitHubTaskSource` or `AsanaTaskSource`. Since `RepoConfig` requires a repo path (known only at runtime), the factory lambda must resolve `RepoConfig` from the container or accept a deferred binding approach — follow the pattern already established for `LlmClientFactory` in Phase 15.

### Empty State Handling (ASANA-05)

- **D-10:** When no Asana tasks pass the filter (empty project or all filtered out), Copland exits cleanly with the same informative-message behavior as GitHub Issues' empty state. No new error codes — consistent with existing "no tasks selected" exit path.

### Claude's Discretion

- Exact Asana API endpoints used for tag fetching vs task fetching (e.g., whether to use `/tasks/{gid}/tags` or expand tags in the task list response)
- Whether `removeTag()` on `AsanaTaskSource` is a no-op or removes a real Asana tag — decide based on Asana API capability
- Test mock strategy for `AsanaService` — follow `GitHubService` Guzzle mock pattern or use constructor injection for testability
- Exact field names returned by Asana API and how they map to the task array structure passed to `ClaudeSelectorService`

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Core interface and existing implementation
- `app/Contracts/TaskSource.php` — Interface all task sources implement; 4 methods; `string|int $taskId`
- `app/Services/GitHubTaskSource.php` — Delegation pattern to follow for `AsanaTaskSource`
- `app/Services/RunOrchestratorService.php` — How `TaskSource` is used in orchestration; D-06 call sites for `selectedTaskId`

### Config classes — both need Asana support
- `app/Config/GlobalConfig.php` — Add `asanaToken()` getter and `asana_project`/`asana_filters` accessors per repo entry
- `app/Config/RepoConfig.php` — Add `taskSource()` getter returning `'github'` or `'asana'`

### Data class to update
- `app/Data/SelectionResult.php` — Rename `selectedIssueNumber` → `selectedTaskId`, widen to `string|int|null` (D-06)

### DI wiring
- `app/Providers/AppServiceProvider.php` — Conditional `TaskSource` binding based on `task_source` config (D-09)

### Prior phase patterns
- `.planning/phases/16-tasksource-extraction/16-CONTEXT.md` — TaskSource design decisions; D-05 confirms AsanaTaskSource holds GitHubService for openDraftPr
- `.planning/phases/15-provider-implementations/15-CONTEXT.md` — LlmClientFactory pattern for conditional service binding in AppServiceProvider

### Requirements
- `.planning/REQUIREMENTS.md` — ASANA-01 through ASANA-05

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `GitHubTaskSource` — exact delegation pattern to replicate for `AsanaTaskSource`
- `GitHubService` — Guzzle HTTP client pattern to follow in `AsanaService`
- `LlmClientFactory` — conditional factory binding in `AppServiceProvider` (model for task_source-conditional binding)

### Established Patterns
- `App\Contracts\` for interfaces; `App\Services\` for implementations
- `final class` with constructor injection
- `AppServiceProvider::register()` for container bindings
- Top-level credential keys in `~/.copland.yml` (`claude_api_key`, `asana_token`)
- `repos:` entries accept scalar string or object with `slug`/`path` — new `asana_project`/`asana_filters` keys extend the object form

### Integration Points
- `RunOrchestratorService` — injects `TaskSource`; uses `selectedTaskId` from `SelectionResult` to route comments and PR operations
- `AppServiceProvider` — binds `TaskSource::class`; needs conditional logic based on `task_source` config key
- `ClaudeSelectorService` — returns `SelectionResult`; the `selectedTaskId` rename propagates here
- Prompt templates — any template referencing `selectedIssueNumber` needs updating

</code_context>

<specifics>
## Specific Decisions

- `asana_token` at top level of `~/.copland.yml` (not nested)
- Project mapping nested under `repos:` entries — same entry, new `asana_project` key
- `task_source: asana` in repo-level `.copland.yml` activates the Asana path
- Tags AND section filtering both supported, client-side, AND logic when both set
- `selectedIssueNumber` → `selectedTaskId` rename + `string|int|null` type widening
- `AsanaTaskSource` holds `AsanaService` + `GitHubService`; `openDraftPr` delegates to GitHub

</specifics>

<deferred>
## Deferred Ideas

- Mark Asana task "In Progress" after selection — explicitly deferred to v1.2 (REQUIREMENTS.md Future Requirements)
- Provider health check for Asana in `copland doctor` — future milestone
- Asana task status sync from GitHub PR state — requires webhooks, out of scope

</deferred>

---

*Phase: 17-asana-integration*
*Context gathered: 2026-04-08*

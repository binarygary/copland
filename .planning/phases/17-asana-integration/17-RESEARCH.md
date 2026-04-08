# Phase 17: Asana Integration - Research

**Researched:** 2026-04-08
**Domain:** Asana REST API, PHP Guzzle HTTP, Laravel Zero service providers, data class widening
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** `asana_token` stored at the top level of `~/.copland.yml` (mirrors `claude_api_key`)
- **D-02:** Asana project-to-repo mapping nested under the existing `repos:` entries in `~/.copland.yml` as `asana_project` (string GID) and `asana_filters` (optional `tags:` list and `section:` name)
- **D-03:** `task_source: asana` in repo-level `.copland.yml` activates Asana; defaults to `github` if absent
- **D-04:** Tags AND section filtering both supported, client-side, AND logic when both configured
- **D-05:** Filtering applied client-side in PHP after fetching all incomplete tasks via `/projects/{gid}/tasks?completed_since=now`
- **D-06:** `SelectionResult::$selectedIssueNumber` renamed to `$selectedTaskId`, type widened from `?int` to `string|int|null`; all call sites updated
- **D-07:** `AsanaTaskSource` holds both `AsanaService` (fetch/comment/tag) and `GitHubService` (PR creation); `openDraftPr()` delegates to GitHub
- **D-08:** `AsanaService` constructs a `GuzzleHttp\Client` internally with base URL `https://app.asana.com/api/1.0` and PAT auth header — follows `GitHubService` constructor pattern
- **D-09:** `AppServiceProvider` conditional `TaskSource` binding reads `task_source` from `RepoConfig`; follows `LlmClientFactory` deferred binding pattern
- **D-10:** Empty task list (no tasks pass filter) exits cleanly with informative message — consistent with existing "no tasks selected" exit path

### Claude's Discretion

- Exact Asana API endpoints for tag fetching (whether to use `/tasks/{gid}/tags` or expand tags in the task list response via `opt_fields`)
- Whether `removeTag()` on `AsanaTaskSource` is a no-op or removes a real Asana tag
- Test mock strategy for `AsanaService` — follow `GitHubService` Guzzle mock pattern or use constructor injection
- Exact field names returned by Asana API and how they map to the task array structure passed to `ClaudeSelectorService`

### Deferred Ideas (OUT OF SCOPE)

- Mark Asana task "In Progress" after selection — deferred to v1.2
- Provider health check for Asana in `copland doctor` — future milestone
- Asana task status sync from GitHub PR state — requires webhooks, out of scope
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ASANA-01 | User can map Asana projects to repos in `~/.copland.yml` | `GlobalConfig` needs `asanaToken()` getter; `configuredRepos()` must pass through `asana_project`/`asana_filters` keys |
| ASANA-02 | Copland fetches open tasks from a configured Asana project (same selection pipeline as GitHub Issues) | `GET /projects/{gid}/tasks?completed_since=now` with `opt_fields=gid,name,notes,tags.name,memberships.section.name` |
| ASANA-03 | User can filter which Asana tasks Copland picks up by tag or section name | Client-side PHP filtering after full fetch; AND logic when both `tags` and `section` configured |
| ASANA-04 | Copland adds a comment to the Asana task with the GitHub PR link when a PR is opened | `POST /tasks/{task_gid}/stories` with `{"data": {"text": "..."}}` |
| ASANA-05 | User can configure Asana as the task source per repo (alongside GitHub Issues) | `task_source: asana` in repo-level `.copland.yml`; `RepoConfig::taskSource()` getter; conditional `AppServiceProvider` binding |
</phase_requirements>

---

## Summary

Phase 17 delivers the Asana integration layer on top of the `TaskSource` interface introduced in Phase 16. The work has three distinct tracks: (1) config extension — adding `asana_token` to `GlobalConfig` and `asana_project`/`asana_filters` to per-repo entries; (2) new service classes — `AsanaService` (Guzzle HTTP client mirroring `GitHubService`) and `AsanaTaskSource` (holds both `AsanaService` and `GitHubService`); (3) a data migration — renaming `SelectionResult::$selectedIssueNumber` to `$selectedTaskId` and widening its type to `string|int|null`.

The critical discovery from reading the codebase is that `RunCommand::runRepo()` instantiates `RunOrchestratorService` **directly** (not via the container), meaning the conditional `TaskSource` binding in `AppServiceProvider` is never exercised for the main run path. The command must also be updated to wire `AsanaTaskSource` when `task_source: asana` is configured. The `selectedIssueNumber` rename propagates through at least 12 call sites in `RunOrchestratorService` alone, plus `SelectionResult`, `ClaudeSelectorService`, `RunResult`, `RunProgressSnapshot`, and tests.

**Primary recommendation:** Plan three waves — (1) config + data class widening (foundational, unblocks everything), (2) `AsanaService` + `AsanaTaskSource` + `AppServiceProvider` conditional binding + `RunCommand` wiring, (3) test coverage for `AsanaService` and `AsanaTaskSource`.

---

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `guzzlehttp/guzzle` | 7.x (already in composer.lock) | HTTP client for Asana API | Already used by `GitHubService`; no new dependency |
| `symfony/yaml` | already present | Config parsing for `asana_token`, `asana_project` | Already used by `GlobalConfig`, `RepoConfig` |

### Asana API

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/projects/{project_gid}/tasks` | GET | Fetch open tasks (filter by `completed_since=now`) |
| `/tasks/{task_gid}/stories` | POST | Add a comment to a task |
| `/tasks/{task_gid}/removeTag` | POST | Remove a tag from a task |
| `/projects/{project_gid}/sections` | GET | Fetch section names (used for section-name-to-GID lookup) |

**Authentication:** `Authorization: Bearer {asana_token}` header. Personal Access Tokens (PATs) are the correct auth mechanism for personal tools; OAuth is explicitly out of scope.

**No new packages required.** The Asana PHP SDK is deprecated (confirmed: Asana officially recommends direct HTTP calls for PHP). Guzzle is the correct tool.

**Version verification:** All packages already in `composer.lock`. No installation step needed.

---

## Architecture Patterns

### Recommended File Layout

```
app/
├── Config/
│   ├── GlobalConfig.php         # Add asanaToken(), asanaProject($slug), asanaFilters($slug)
│   └── RepoConfig.php           # Add taskSource() → 'github'|'asana'
├── Contracts/
│   └── TaskSource.php           # Unchanged (Phase 16)
├── Data/
│   └── SelectionResult.php      # RENAME selectedIssueNumber → selectedTaskId, widen type
├── Services/
│   ├── AsanaService.php          # NEW — Guzzle HTTP client for Asana REST API
│   └── AsanaTaskSource.php       # NEW — implements TaskSource, holds AsanaService + GitHubService
└── Providers/
    └── AppServiceProvider.php    # UPDATE — conditional TaskSource binding
app/Commands/
    └── RunCommand.php            # UPDATE — wire AsanaTaskSource when task_source: asana
```

### Pattern 1: AsanaService mirrors GitHubService

`AsanaService` follows the exact same constructor pattern as `GitHubService` — injectable `?Client` for tests, PAT resolved from constructor arg:

```php
// Source: app/Services/GitHubService.php (existing pattern)
final class AsanaService
{
    public function __construct(
        private string $token,
        private ?Client $http = null,
    ) {
        $this->http ??= new Client([
            'base_uri' => 'https://app.asana.com/api/1.0',
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }
}
```

Note: `GitHubService` resolves its token lazily via `gh` CLI. `AsanaService` takes the token directly from `GlobalConfig::asanaToken()` at construction time — simpler because there is no CLI auth tool for Asana.

### Pattern 2: AsanaTaskSource delegation

Mirrors `GitHubTaskSource` exactly but holds two services:

```php
// Source: app/Services/GitHubTaskSource.php (existing delegation pattern)
final class AsanaTaskSource implements TaskSource
{
    public function __construct(
        private AsanaService $asana,
        private GitHubService $github,  // for openDraftPr only
    ) {}

    public function fetchTasks(string $repo, array $tags): array
    {
        // $tags is ignored — AsanaService uses asana_filters from GlobalConfig
        return $this->asana->getOpenTasks();
    }

    public function addComment(string $repo, string|int $taskId, string $body): void
    {
        $this->asana->addStory((string) $taskId, $body);
    }

    public function openDraftPr(string $repo, string $branch, string $title, string $body): array
    {
        return $this->github->createDraftPr($repo, $branch, $title, $body);
    }

    public function removeTag(string $repo, string|int $taskId, string $tag): void
    {
        $this->asana->removeTag((string) $taskId, $tag);
    }
}
```

### Pattern 3: AppServiceProvider conditional binding

The current `AppServiceProvider` binds `TaskSource::class` unconditionally to `GitHubTaskSource`. The challenge (D-09) is that `RepoConfig` requires a repo path only known at runtime. Looking at the codebase: `RunCommand::runRepo()` instantiates `RunOrchestratorService` directly — the container binding is **not used** for the run path. Two options:

**Option A (recommended — follow existing direct-instantiation pattern):** Do not change `AppServiceProvider` for the run path. Instead, update `RunCommand::runRepo()` to read `$repoConfig->taskSource()` and instantiate the correct `TaskSource` directly before constructing the orchestrator. This is consistent with how every other service is wired in `runRepo()`.

**Option B (AppServiceProvider conditional binding):** Use a deferred/contextual binding with a closure that reads `RepoConfig` from a bound instance. This is more complex and the current codebase doesn't exercise the container-bound `TaskSource` at all.

**Research finding:** Option A is the correct approach based on reading `RunCommand`. The `AppServiceProvider` binding serves tests and non-run commands (like `PlanCommand`). Update `AppServiceProvider` to default to `GitHubTaskSource` (unchanged), and update `RunCommand::runRepo()` to conditionally build `AsanaTaskSource`.

### Pattern 4: Asana API task structure

The Asana task object fields needed for the selector pipeline. Request with:
```
opt_fields=gid,name,notes,tags.name,memberships.section.name,completed
```

Expected response (per Asana docs + forum confirmation):
```json
{
  "data": [
    {
      "gid": "1234567890123456",
      "name": "Fix login timeout bug",
      "notes": "Users report session expires after 5 minutes...",
      "completed": false,
      "tags": [{"gid": "...", "name": "agent-ready"}],
      "memberships": [
        {
          "project": {"gid": "...", "resource_type": "project"},
          "section": {"gid": "...", "name": "Backlog", "resource_type": "section"}
        }
      ]
    }
  ]
}
```

**Mapping to selector format** (matches GitHub Issues structure the selector already consumes):
```php
[
    'number' => $task['gid'],   // string — Asana GID
    'title'  => $task['name'],
    'body'   => $task['notes'] ?? '',
    'labels' => array_map(fn($t) => ['name' => $t['name']], $task['tags'] ?? []),
]
```

The `number` key is what `RunOrchestratorService` uses to match `$selection->selectedTaskId` back to the issue array. Using `gid` as `number` makes this work with the renamed field.

### Pattern 5: SelectionResult rename propagation

All call sites of `selectedIssueNumber` must be updated. Confirmed locations from code inspection:

- `app/Data/SelectionResult.php` — property declaration (rename + type widen)
- `app/Services/ClaudeSelectorService.php` — line 60: `selectedIssueNumber: $json['selected_issue_number'] ?? null` → `selectedTaskId:`
- `app/Services/RunOrchestratorService.php` — 12 references: lines 68, 80, 92, 93, 105, 124, 151, 198, 232, 282, 328, 360
- `app/Data/RunResult.php` — `$selectedIssueNumber` property (`?int`) needs widening to `string|int|null`
- `app/Support/RunProgressSnapshot.php` — likely has `$selectedIssueNumber` property
- `app/Commands/RunCommand.php` — references `$result->selectedIssueNumber` in run log payload (line 366)
- Prompt templates in `resources/prompts/` — check for `selected_issue_number` in selector prompt

### Anti-Patterns to Avoid

- **Fetching sections separately before filtering:** Do not make a separate `/projects/{gid}/sections` call to resolve section names to GIDs. Use `memberships.section.name` directly in `opt_fields` and compare by name string client-side (D-05).
- **Using `?int $taskId` anywhere:** Asana GIDs are numeric strings (e.g., `"1234567890123456"`) that exceed PHP's 32-bit int range and may exceed 64-bit int range on some systems. Always `string`.
- **Casting GID to int:** PHP's `(int)` cast on a 16-digit Asana GID silently truncates. The `GitHubTaskSource` already casts `(int) $taskId` — this is safe for GitHub issue numbers but would be wrong for Asana. `AsanaTaskSource` must pass GIDs as strings.
- **Using Asana PHP SDK:** Officially deprecated; use Guzzle directly.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTTP client with auth headers | Custom curl wrapper | `GuzzleHttp\Client` (already in project) | Already used by `GitHubService`; retry, redirects, error handling built in |
| PAT token storage | Custom credential store | Top-level YAML key (D-01) | Consistent with `claude_api_key` pattern; already has `ensureExists()` default template |
| Pagination | Manual offset cursor loop | Single page fetch with `limit=100` | Personal-scale projects have <100 open tasks; pagination adds complexity with no benefit |

---

## Common Pitfalls

### Pitfall 1: RunCommand wires services directly, bypassing AppServiceProvider

**What goes wrong:** Developer updates `AppServiceProvider` to conditionally bind `AsanaTaskSource`, but the run path ignores the container — `RunOrchestratorService` is constructed directly in `RunCommand::runRepo()`. The conditional binding is never executed.

**Why it happens:** `AppServiceProvider` binding looks authoritative but `RunCommand` uses `new` directly for all services.

**How to avoid:** Update `RunCommand::runRepo()` to read `$repoConfig->taskSource()` and build the correct `TaskSource` with `new`. Keep `AppServiceProvider` binding as `GitHubTaskSource` default for non-run-command usage (PlanCommand, tests).

**Warning signs:** Tests for `RunCommand` pass but manual `copland run` always uses GitHub Issues regardless of config.

### Pitfall 2: selectedIssueNumber → selectedTaskId rename misses RunOrchestratorService call sites

**What goes wrong:** The rename is applied to `SelectionResult.php` and `ClaudeSelectorService.php` but `RunOrchestratorService` still uses `->selectedIssueNumber` — PHP fatal error at runtime on the `skip_all` path and the issue-match loop.

**Why it happens:** `RunOrchestratorService` has 12 references; it's easy to miss some using find-replace.

**How to avoid:** After renaming, run `grep -r "selectedIssueNumber" app/` and verify zero matches. Also check `RunResult.php` and `RunProgressSnapshot.php`.

**Warning signs:** `php artisan run` throws `Attempt to read property "selectedIssueNumber" on null` or `Call to undefined method ... selectedIssueNumber`.

### Pitfall 3: Asana GID truncation via int cast

**What goes wrong:** An Asana GID like `"1206654088086513"` gets cast to int somewhere in the pipeline and either truncates silently or causes a type mismatch when passed back to `AsanaService::addStory()`.

**Why it happens:** `GitHubTaskSource` casts `(int) $taskId` which is fine for GitHub; the same pattern would be catastrophic for Asana. Also `RunResult::$selectedIssueNumber` is `?int` — widening to `string|int|null` is required.

**How to avoid:** `AsanaTaskSource` methods always pass GIDs as strings. `RunResult::$selectedIssueNumber` must be renamed and type-widened alongside `SelectionResult`. `RunOrchestratorService` uses `$selectedIssue['number']` (the mapped GID) everywhere — this flows through correctly since it's stored as a string in the task array.

**Warning signs:** Comments posted to wrong Asana task; API returns 404 on story creation; GID comparison in orchestrator loop fails to find selected issue.

### Pitfall 4: opt_fields missing for tag and section data

**What goes wrong:** `GET /projects/{gid}/tasks` returns only `gid` and `name` by default. Without `opt_fields`, tags and memberships are empty arrays.

**Why it happens:** Asana API excludes most fields by default to reduce payload size.

**How to avoid:** Always include `opt_fields=gid,name,notes,tags.name,memberships.section.name,completed` in the task fetch request.

**Warning signs:** Tag filter passes all tasks through (tags array always empty); section filter passes all tasks through.

### Pitfall 5: Section name comparison is project-relative

**What goes wrong:** A task can belong to multiple projects (multi-homed). The `memberships` array may contain multiple entries for different projects. Checking only `$task['memberships'][0]['section']['name']` could match the wrong project's section.

**Why it happens:** Asana supports tasks in multiple projects simultaneously.

**How to avoid:** When filtering by section, iterate all `memberships` entries and check if any membership's section name matches AND the membership's project GID matches the configured `asana_project`. This prevents false positives from sibling projects.

**Warning signs:** Task appears in "Backlog" section of a different project and gets selected unexpectedly.

---

## Code Examples

Verified patterns from official sources and existing codebase:

### Fetch open tasks from a project

```php
// Source: https://developers.asana.com/reference/gettasksforproject + forum (opt_fields for memberships/tags)
$response = $this->requestJson('GET', "/projects/{$projectGid}/tasks", [
    'query' => [
        'completed_since' => 'now',
        'opt_fields'      => 'gid,name,notes,tags.name,memberships.section.name,completed',
        'limit'           => 100,
    ],
]);
return $response['data'] ?? [];
```

### Post a comment (story) to a task

```php
// Source: https://developers.asana.com/reference/createstoryfortask
$this->requestJson('POST', "/tasks/{$taskGid}/stories", [
    'json' => ['data' => ['text' => $body]],
]);
```

### Remove a tag from a task

```php
// Source: https://developers.asana.com/reference/removetagfortask
$this->requestJson('POST', "/tasks/{$taskGid}/removeTag", [
    'json' => ['data' => ['tag' => $tagGid]],
]);
```

**Note on removeTag:** The endpoint requires the tag's GID, not its name. To remove by name, first find the tag GID from the task's `tags` array (already fetched with `opt_fields=tags.name`). If the tag is not present on the task, treat as a no-op.

### requestJson helper (follows GitHubService pattern)

```php
// Source: app/Services/GitHubService.php (existing pattern to follow)
private function requestJson(string $method, string $uri, array $options = []): array
{
    $options['headers'] = [
        'Authorization' => 'Bearer '.$this->token,
        'Accept'        => 'application/json',
        ...($options['headers'] ?? []),
    ];

    try {
        $response = $this->http->request($method, $uri, $options);
    } catch (GuzzleException $e) {
        $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
            ? $e->getResponse()->getStatusCode()
            : 'request failed';
        $body = method_exists($e, 'getResponse') && $e->getResponse() !== null
            ? (string) $e->getResponse()->getBody()
            : $e->getMessage();

        throw new RuntimeException("Asana API error: {$status} {$body}", previous: $e);
    }

    $decoded = json_decode((string) $response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}
```

### GlobalConfig additions

```php
// Source: app/Config/GlobalConfig.php (pattern: existing top-level key getters)
public function asanaToken(): string
{
    return $this->data['asana_token'] ?? '';
}

public function asanaProjectForRepo(string $slug): ?string
{
    foreach ($this->repos() as $repo) {
        if (is_array($repo) && ($repo['slug'] ?? '') === $slug) {
            return isset($repo['asana_project']) ? (string) $repo['asana_project'] : null;
        }
    }
    return null;
}

public function asanaFiltersForRepo(string $slug): array
{
    foreach ($this->repos() as $repo) {
        if (is_array($repo) && ($repo['slug'] ?? '') === $slug) {
            return $repo['asana_filters'] ?? [];
        }
    }
    return [];
}
```

### RepoConfig addition

```php
// Source: app/Config/RepoConfig.php (pattern: existing scalar getters with default)
public function taskSource(): string
{
    return $this->data['task_source'] ?? 'github';
}
```

### Client-side filtering in AsanaService

```php
// Pattern: D-04, D-05 — AND logic, client-side, by name
private function applyFilters(array $tasks, array $filters, string $projectGid): array
{
    $requiredTags = $filters['tags'] ?? [];
    $requiredSection = $filters['section'] ?? null;

    return array_values(array_filter($tasks, function (array $task) use ($requiredTags, $requiredSection, $projectGid) {
        // Tag filter: task must have ALL required tags
        if (!empty($requiredTags)) {
            $taskTagNames = array_map(fn($t) => $t['name'], $task['tags'] ?? []);
            foreach ($requiredTags as $tag) {
                if (!in_array($tag, $taskTagNames, true)) {
                    return false;
                }
            }
        }

        // Section filter: any membership in THIS project matching the section name
        if ($requiredSection !== null) {
            $found = false;
            foreach ($task['memberships'] ?? [] as $membership) {
                if (
                    ($membership['project']['gid'] ?? '') === $projectGid &&
                    ($membership['section']['name'] ?? '') === $requiredSection
                ) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }));
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Asana official PHP SDK | Direct Guzzle HTTP calls | SDK deprecated ~2022 | No SDK dependency; build `AsanaService` like `GitHubService` |
| `selectedIssueNumber: ?int` | `selectedTaskId: string|int|null` | This phase (D-06) | All call sites updated; Asana GIDs flow through pipeline as strings |
| `TaskSource` = always GitHub | Conditional `TaskSource` per repo | This phase (D-03, D-09) | `task_source: asana` in repo config activates `AsanaTaskSource` |

**Deprecated/outdated:**

- Asana PHP SDK (`asana/asana`): Do not add this dependency. Officially deprecated by Asana.

---

## Open Questions

1. **removeTag GID resolution**
   - What we know: `/tasks/{task_gid}/removeTag` requires tag GID, not tag name; the task fetch includes `tags.name` and `tags.gid` when using `opt_fields=tags`
   - What's unclear: Whether `opt_fields=tags.name` also returns `tags.gid` or requires `opt_fields=tags.gid,tags.name`
   - Recommendation: Use `opt_fields=gid,name,notes,tags,memberships.section.name,completed` to get full tag objects (includes GID); then GID is available for removeTag without an extra API call

2. **RunCommand wiring: RepoConfig availability for AsanaService constructor**
   - What we know: `AsanaService` needs `asana_token` (from `GlobalConfig`) and `asana_project`/`asana_filters` (from `GlobalConfig` per-repo lookup). `RunCommand::runRepo()` has both `$globalConfig` and `$repoConfig` in scope.
   - What's unclear: Whether `AsanaTaskSource` receives project GID and filters via constructor, or whether `AsanaService::getOpenTasks()` receives them as parameters
   - Recommendation: Pass project GID and filters to `AsanaService` constructor at build time in `RunCommand::runRepo()` — consistent with how other services are wired

3. **PlanCommand needs updating for Asana task source**
   - What we know: `PlanCommand` also instantiates services directly and may use `GitHubService` directly for issue fetching
   - What's unclear: Whether `PlanCommand` needs the same `task_source` conditional logic
   - Recommendation: Check `PlanCommand` during planning; scope it if it uses `GitHubService` directly for issue listing

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All code | ✓ | PHP 8.4.19 | — |
| `guzzlehttp/guzzle` | AsanaService HTTP | ✓ | Already in composer.lock | — |
| `symfony/yaml` | Config parsing | ✓ | Already in composer.lock | — |
| Asana REST API | AsanaService | External | v1.0 (stable) | — (no fallback; requires PAT) |

**Missing dependencies with no fallback:** None — all required packages are already present.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest PHP (confirmed via `./vendor/bin/pest`) |
| Config file | `phpunit.xml` / Pest config in `composer.json` |
| Quick run command | `./vendor/bin/pest --no-coverage` |
| Full suite command | `./vendor/bin/pest --no-coverage` |

**Baseline:** 99 tests, 362 assertions, all passing.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ASANA-01 | `GlobalConfig::asanaToken()` returns token from YAML | unit | `./vendor/bin/pest tests/Unit/GlobalConfigTest.php --no-coverage` | ✅ (extend existing) |
| ASANA-01 | `GlobalConfig::asanaProjectForRepo()` returns project GID | unit | `./vendor/bin/pest tests/Unit/GlobalConfigTest.php --no-coverage` | ✅ (extend existing) |
| ASANA-02 | `AsanaService::getOpenTasks()` fetches from correct endpoint | unit | `./vendor/bin/pest tests/Unit/AsanaServiceTest.php --no-coverage` | ❌ Wave 0 |
| ASANA-02 | `AsanaTaskSource::fetchTasks()` delegates to AsanaService | unit | `./vendor/bin/pest tests/Unit/AsanaTaskSourceTest.php --no-coverage` | ❌ Wave 0 |
| ASANA-03 | Tag filter: tasks without required tag are excluded | unit | `./vendor/bin/pest tests/Unit/AsanaServiceTest.php --no-coverage` | ❌ Wave 0 |
| ASANA-03 | Section filter: tasks not in named section are excluded | unit | `./vendor/bin/pest tests/Unit/AsanaServiceTest.php --no-coverage` | ❌ Wave 0 |
| ASANA-03 | AND logic: both tag and section must match | unit | `./vendor/bin/pest tests/Unit/AsanaServiceTest.php --no-coverage` | ❌ Wave 0 |
| ASANA-04 | `AsanaService::addStory()` posts to `/tasks/{gid}/stories` | unit | `./vendor/bin/pest tests/Unit/AsanaServiceTest.php --no-coverage` | ❌ Wave 0 |
| ASANA-04 | `AsanaTaskSource::addComment()` delegates to AsanaService | unit | `./vendor/bin/pest tests/Unit/AsanaTaskSourceTest.php --no-coverage` | ❌ Wave 0 |
| ASANA-05 | `RepoConfig::taskSource()` returns 'asana' or 'github' | unit | `./vendor/bin/pest tests/Unit/RepoConfigTest.php --no-coverage` | ✅ (extend existing) |
| ASANA-05 | `SelectionResult::$selectedTaskId` accepts string GID | unit | `./vendor/bin/pest tests/Unit/RunOrchestratorServiceTest.php --no-coverage` | ✅ (update existing) |
| D-06 | All `selectedIssueNumber` call sites renamed | regression | `./vendor/bin/pest --no-coverage` (full suite) | ✅ (existing suite covers) |

### Sampling Rate

- **Per task commit:** `./vendor/bin/pest --no-coverage`
- **Per wave merge:** `./vendor/bin/pest --no-coverage`
- **Phase gate:** Full suite green (99+ tests) before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/AsanaServiceTest.php` — covers ASANA-02, ASANA-03, ASANA-04
- [ ] `tests/Unit/AsanaTaskSourceTest.php` — covers ASANA-02, ASANA-04 delegation paths

*(Existing test files for GlobalConfig, RepoConfig, RunOrchestratorService need extensions, not new files.)*

---

## Sources

### Primary (HIGH confidence)

- Asana REST API docs — `https://developers.asana.com/reference/gettasksforproject` — GET tasks endpoint, `completed_since=now` param, `opt_fields` mechanism
- Asana REST API docs — `https://developers.asana.com/reference/createstoryfortask` — POST stories endpoint, `{"data": {"text": "..."}}` body
- Asana REST API docs — `https://developers.asana.com/reference/removetagfortask` — POST removeTag endpoint
- Asana REST API docs — `https://developers.asana.com/reference/getsectionsforproject` — sections endpoint, confirmed section info lives in `memberships`
- Codebase: `app/Services/GitHubService.php` — Guzzle constructor pattern to replicate
- Codebase: `app/Services/GitHubTaskSource.php` — delegation pattern to replicate
- Codebase: `app/Services/RunOrchestratorService.php` — 12 `selectedIssueNumber` call sites identified
- Codebase: `app/Commands/RunCommand.php` — confirmed direct service instantiation (container not used)

### Secondary (MEDIUM confidence)

- Asana developer forum — `https://forum.asana.com/t/get-all-project-tasks-with-their-section/15606` — confirmed `memberships.section.name` as `opt_fields` value for section data
- WebSearch: Asana PHP SDK deprecated — recommendation to use Guzzle/cURL directly

### Tertiary (LOW confidence)

- None — all key claims verified against official docs or codebase

---

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH — Guzzle already in project; Asana REST API v1.0 is stable and verified
- Architecture: HIGH — `GitHubService`/`GitHubTaskSource` patterns are in codebase; `RunCommand` direct-wiring confirmed by reading source
- Pitfalls: HIGH — GID truncation, opt_fields omission, RunCommand bypass all verified by reading actual code
- Asana API field names: MEDIUM — verified endpoint shape from docs; exact `opt_fields` values for nested fields (`tags.name` vs `tags`) need empirical confirmation during implementation

**Research date:** 2026-04-08
**Valid until:** 2026-05-08 (Asana API v1.0 is stable; PHP stack is stable)

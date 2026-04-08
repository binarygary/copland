# Features Research: Multi-Provider LLM + Asana

**Domain:** Autonomous overnight GitHub issue resolver CLI — v1.1 extension
**Researched:** 2026-04-08
**Confidence:** MEDIUM overall (WebSearch and WebFetch unavailable; findings from training knowledge
cross-referenced against codebase internals. Critical API surface verified against published documentation
patterns; flagged where verification needed.)

---

## LLM Provider Abstraction

### Table Stakes

Users adding a second LLM provider to a tool like Copland expect:

| Behavior | Why Expected | Complexity |
|----------|--------------|------------|
| Global provider default in `~/.copland.yml` | One config location controls all repos | Low |
| Per-repo provider override in `.copland.yml` | Some repos may need a more capable cloud model while others run local | Low |
| Same three model roles (selector, planner, executor) respected per provider | Core pipeline shape does not change | Low |
| Model name configurable per role per provider | Different providers have wildly different model name formats | Low |
| Clear error if provider not reachable | Ollama down at 2am should fail loudly, not silently produce empty PRs | Low |
| Token usage still tracked | Cost display is a shipped feature; it must not regress for cloud providers | Medium |
| Run log still records provider name + model | Morning review must show which provider ran | Trivial |

### Differentiators

| Behavior | Value | Complexity |
|----------|-------|------------|
| Fallback provider chain (Ollama → OpenRouter → Anthropic) | If local model fails, fall through to cloud | High — retry logic now has provider dimension |
| Cost display shows $0.00 for Ollama runs | Surfaces the value of local inference | Low — just need a zero-cost path in estimator |
| Provider shown in run summary line | "executor=ollama/llama3.2" vs "executor=anthropic/claude-sonnet-4-6" | Trivial |

### Anti-Features (avoid)

| Anti-Feature | Why Avoid |
|--------------|-----------|
| Auto-selecting provider based on issue complexity | Over-engineering. User configures the provider; let them own the decision. |
| Parallel requests to multiple providers for "best of" | Multiplies cost and complexity. Not appropriate for an overnight agent. |
| Provider-specific prompt variants | One prompt template should work for any capable LLM. If it doesn't, fix the prompt — don't fork it. |
| Caching architecture differences between providers | Anthropic caching is Anthropic-specific. Do not attempt to retrofit cache_control blocks onto OpenAI-compat APIs — just skip them. |
| Streaming responses | Copland already uses blocking message calls. Adding streaming adds complexity with no benefit for a non-interactive agent. |

### Provider Abstraction Design Notes

Copland's current LLM surface is `AnthropicApiClient` — a thin retry wrapper that calls
`$this->client->messages->create(...)` using the `anthropic-ai/sdk` typed client. The services
(`ClaudeSelectorService`, `ClaudePlannerService`, `ClaudeExecutorService`) accept an `AnthropicApiClient`
instance via constructor injection and call `$apiClient->messages(model, maxTokens, system, tools, messages)`.

**The right abstraction boundary is a provider-agnostic `LlmClient` interface** that replaces
`AnthropicApiClient` at the injection site. Each provider implements this interface. The services
never change — they call `$this->client->messages(...)` regardless of which backend is running.

**Response normalization:** Anthropic's SDK returns typed objects (`$response->content[0]->text`,
`$response->usage->inputTokens`). OpenAI-compatible APIs (Ollama, OpenRouter) return arrays or stdClass with
`choices[0].message.content` and `usage.prompt_tokens` / `usage.completion_tokens`. The provider adapter
must normalize these into the same object shape that the services expect.

**Tool use (function calling):** The executor relies on Anthropic's tool-use API. OpenAI-compatible
endpoints support function calling via the `tools` parameter with a different schema format (OpenAI format
vs Anthropic format). This is the single hardest compatibility problem. See PITFALLS.md.

---

## Ollama Integration

### Table Stakes

| Behavior | Why Expected | Complexity |
|----------|--------------|------------|
| Calls `http://localhost:11434/v1/chat/completions` (OpenAI-compatible endpoint) | Ollama's documented OpenAI-compat endpoint; all serious Ollama integrations use this path | Low |
| Configurable base URL (`ollama_base_url`) | User may run Ollama on a non-standard port or remote host | Trivial |
| No authentication required by default | Ollama local runs have no auth out of the box | Low |
| Model name passed as configured string | Ollama model names look like `llama3.2`, `qwen2.5-coder:7b`, `mistral` | Trivial |
| Graceful failure if Ollama not running | Connection refused at 2am must produce a clear error, not a PHP fatal | Low |
| Tool/function calling supported for executor | The executor requires multi-turn tool use; the model must support it | Medium — not all Ollama models support function calling well |

### Expected Behavior

**API surface** (HIGH confidence — Ollama OpenAI-compat endpoint is well-documented):
- Base URL: `http://localhost:11434/v1`
- Endpoint: `POST /chat/completions`
- Auth: None (or optional `Authorization: Bearer` with any non-empty string for compatibility)
- Request format: OpenAI chat completions format
  ```json
  {
    "model": "llama3.2",
    "messages": [{"role": "user", "content": "..."}],
    "tools": [...],
    "stream": false
  }
  ```
- Response format: OpenAI response shape
  ```json
  {
    "choices": [{"message": {"role": "assistant", "content": "...", "tool_calls": [...]}}],
    "usage": {"prompt_tokens": 120, "completion_tokens": 48, "total_tokens": 168}
  }
  ```

**Token counting:** Ollama returns `usage.prompt_tokens` / `completion_tokens` in the OpenAI format, not
Anthropic's `inputTokens` / `outputTokens`. The adapter normalizes this. No cache tokens — cost is $0.00.

**Function calling quality:** MEDIUM confidence — support varies by model. Models explicitly trained for
function calling (e.g., `qwen2.5-coder`, `mistral-nemo`, `llama3.1` and later with `tools` support) work.
General-purpose models may produce malformed tool call JSON or ignore the tools parameter. The executor
must handle this gracefully (existing thrashing detection in `ExecutorRunState` will catch repeated bad calls,
but the failure mode will be "thrashing abort" not "clean skip").

**Practical constraint:** Ollama is most useful for the selector and planner roles (no tool calling required —
just JSON output). Using Ollama for the executor requires a function-calling-capable model. Recommend config
documentation that calls this out explicitly.

**Connection refused handling:** Guzzle will throw a `ConnectException` if Ollama is not running. The
adapter must catch this and convert it to a `RuntimeException` with a human-readable message ("Ollama is not
running at http://localhost:11434 — start it with `ollama serve`").

**Config surface in `~/.copland.yml`:**
```yaml
provider: ollama           # or: anthropic, openrouter

ollama:
  base_url: http://localhost:11434/v1   # optional, this is the default

models:
  selector: llama3.2
  planner: qwen2.5-coder:7b
  executor: qwen2.5-coder:7b
```

Per-repo override in `.copland.yml`:
```yaml
provider: anthropic        # override to cloud for this specific repo
models:
  executor: claude-sonnet-4-6
```

---

## OpenRouter Integration

### Table Stakes

| Behavior | Why Expected | Complexity |
|----------|--------------|------------|
| Calls `https://openrouter.ai/api/v1/chat/completions` | OpenRouter's documented endpoint | Low |
| Authenticates with `Authorization: Bearer <api_key>` | Standard Bearer token auth | Trivial |
| `openrouter_api_key` stored in `~/.copland.yml` (not env var) | Consistent with existing `claude_api_key` pattern | Trivial |
| Model name passed as `provider/model` string (e.g., `anthropic/claude-3-haiku`, `openai/gpt-4o-mini`) | OpenRouter model naming convention | Trivial |
| HTTP-level retry on 429 and 5xx | Overnight reliability requirement; same as existing Anthropic retry policy | Low |
| Token usage tracked for cost display | OpenRouter returns usage in OpenAI format | Low |

### Expected Behavior

**API surface** (HIGH confidence — OpenRouter is OpenAI-compatible and well-documented):
- Base URL: `https://openrouter.ai/api/v1`
- Endpoint: `POST /chat/completions`
- Auth: `Authorization: Bearer sk-or-...` (OpenRouter API key format)
- Optional headers that OpenRouter recommends for attribution:
  - `HTTP-Referer: https://github.com/binarygary/copland`
  - `X-Title: Copland`
- Request/response format: OpenAI chat completions (identical shape to Ollama above)
- Tool calling: Supported and reliable — OpenRouter routes to models that support it (e.g.,
  `anthropic/claude-3-haiku-20240307`, `openai/gpt-4o-mini`). Model must support function calling —
  caller's responsibility to pick an appropriate model.

**Rate limiting:** OpenRouter enforces per-key rate limits. 429 responses should use the same retry/backoff
as the existing Anthropic client. `Retry-After` header may be present; the adapter should respect it if
available, otherwise fall back to exponential backoff.

**Cost tracking:** OpenRouter returns token counts in `usage.prompt_tokens` / `usage.completion_tokens`.
OpenRouter-specific cost data is available in response headers (`x-openrouter-cost`), but this requires
header inspection. A simpler approach: use the same model-name-based rate estimation as the existing
`AnthropicCostEstimator`, extended with common OpenRouter model rates. This is "good enough" for a personal
tool. Exact billing is visible in the OpenRouter dashboard.

**Config surface in `~/.copland.yml`:**
```yaml
provider: openrouter

openrouter:
  api_key: sk-or-...
  # Optional: site_url and app_name for attribution headers

models:
  selector: openai/gpt-4o-mini
  planner: anthropic/claude-3-5-sonnet
  executor: anthropic/claude-3-5-sonnet
```

**Differences from direct Anthropic:**
- Anthropic prompt caching (`cache_control` blocks) is NOT available via OpenRouter (MEDIUM confidence —
  caching requires direct Anthropic API access). Do not pass cache_control blocks when provider is openrouter.
- Response object shape uses OpenAI format, not Anthropic format. The adapter normalizes this.
- Some models via OpenRouter may not support function calling. Model selection is user's responsibility.

---

## Asana Task Source

### Table Stakes

| Behavior | Why Expected | Complexity |
|----------|--------------|------------|
| Pull tasks from a configured Asana project | Core feature — this is the alternative to GitHub Issues | Medium |
| Filter tasks by assignee, section, or custom field | "copland-ready" equivalent of a GitHub label | Medium |
| Task title and description passed to selector and planner | Same data shape as GitHub issues | Low (mapping layer) |
| After PR opened: post comment on Asana task with PR URL | Closes the loop — user sees PR link in Asana | Medium |
| Asana personal access token (PAT) in `~/.copland.yml` | Consistent with existing API key storage pattern | Trivial |
| GitHub PRs still used for code changes | Asana is task source only; code workflow unchanged | Low |

### Asana Task Lifecycle (selection → PR → comment)

**Phase 1: Task Fetching**

Asana REST API endpoint for listing tasks in a project:
```
GET https://app.asana.com/api/1.0/projects/{project_gid}/tasks
```
- Auth: `Authorization: Bearer {personal_access_token}`
- Returns task `gid` (global ID), `name`, `notes` (description), `assignee`, `completed`
- Requires `opt_fields` query param to get description: `?opt_fields=gid,name,notes,assignee,completed,tags`

**Task filtering** (equivalent to GitHub label filtering):
- Option A: Tag-based — Asana tasks tagged with a specific tag (e.g., "copland-ready") are candidates.
  Fetch tag GID from config, filter tasks by that tag.
- Option B: Section-based — Tasks in a specific section/column of the project are candidates.
  `GET /sections/{section_gid}/tasks`
- Option C: Custom field-based — More powerful but more complex. Avoid for v1.

**Recommendation: tag-based filtering** (LOW complexity, analogous to GitHub label pattern, user-familiar).

**Data mapping to existing pipeline:**
```php
// Asana task → issue-shaped array for selector/planner
[
    'number'  => (int) $task['gid'],   // treat GID as issue number
    'title'   => $task['name'],
    'body'    => $task['notes'] ?? '',
    'labels'  => [],                   // no label equivalent needed — pre-filtered by tag
    'html_url' => "https://app.asana.com/0/{project_gid}/{task_gid}",
]
```

**Phase 2: Selection and Planning**

No changes needed. The selector and planner receive the mapped task array. The task GID substitutes for the
GitHub issue number throughout the pipeline. The existing `SelectionResult`, `PlanResult`, and
`RunOrchestratorService` flow continues unchanged.

**Phase 3: Post-PR Comment on Asana Task**

After the GitHub draft PR is created, post a comment (called a "story" in Asana) on the Asana task:

```
POST https://app.asana.com/api/1.0/tasks/{task_gid}/stories
{
  "data": {
    "text": "Draft PR opened: https://github.com/owner/repo/pull/117\n\nCopland automated this change."
  }
}
```

Auth: same Bearer token.

This is a fire-and-forget call. If it fails, log the failure but do not abort the run — the PR was already
opened, and the comment is a convenience, not a correctness requirement.

**Phase 4: Task Status Update (optional differentiator)**

Asana allows marking tasks with custom fields or moving them to a section. After PR is opened, optionally
move the task to a "In Review" section or remove the "copland-ready" tag. This prevents the same task from
being re-selected on the next run.

**This is the Asana equivalent of the GitHub "add label / remove label" step in `RunOrchestratorService`.**
It is table stakes for preventing duplicate runs, not a differentiator. It must be implemented.

```
DELETE https://app.asana.com/api/1.0/tasks/{task_gid}/removeTag
{
  "data": { "tag": "{copland_ready_tag_gid}" }
}
```
— OR —
```
POST https://app.asana.com/api/1.0/tasks/{task_gid}/addProject
{
  "data": { "project": "{project_gid}", "section": "{in_review_section_gid}" }
}
```

**Config surface in `~/.copland.yml`:**
```yaml
task_source: asana    # or: github (default)

asana:
  access_token: 1/...
  workspace_gid: "12345678"

# Per-repo mapping: which Asana project + tag maps to this GitHub repo
repos:
  - slug: owner/repo
    path: /path/to/checkout
    asana_project_gid: "98765432"
    asana_ready_tag_gid: "11111111"      # tag meaning "ready for Copland"
    asana_in_review_section_gid: "22222222"  # optional: move here after PR
```

**Authentication:** Asana Personal Access Tokens (PATs) are available from Asana profile settings. They are
the correct auth mechanism for a single-user personal tool. OAuth is over-engineering here. PATs do not
expire unless manually revoked.

**Confidence on API shape:** MEDIUM — Asana REST API v1 has been stable for years. The `tasks`, `stories`,
and `tags` endpoints are core Asana API surface. `opt_fields` pattern for field selection is characteristic
of Asana's API. Verify exact field names against Asana API reference before implementation.

### Asana Task Lifecycle — Dependency on GitHub

Asana is a task source, not a code host. The full lifecycle is:

```
Asana project (task candidates)
    ↓  fetch + filter by tag
Copland selector
    ↓  selection result
Copland planner
    ↓  plan result
Git worktree (existing GitHub repo)
    ↓  execute + verify
GitHub (draft PR)
    ↓  PR URL
Asana task (comment with PR link + remove ready tag)
```

GitHub is still required. The user must have a GitHub repo connected to their Asana project tasks — meaning
the Asana task describes work that lives in the GitHub codebase. The `repos:` list in config gains a new
`asana_project_gid` field to make this mapping explicit.

### Anti-Features (avoid)

| Anti-Feature | Why Avoid |
|--------------|-----------|
| Syncing Asana task status from GitHub PR state | Requires webhooks or polling. This is a personal CLI, not a service. Out of scope. |
| Creating Asana tasks from GitHub issues | Wrong direction. Asana is task source, GitHub is code host. Don't blur the boundary. |
| Asana OAuth flow | Over-engineering for single-user tool. PAT stored in `~/.copland.yml` is sufficient. |
| Supporting Asana subtasks as work items | Subtask structure varies wildly across projects. Flat task list is predictable. |
| Writing implementation steps back to Asana task description | The PR body already contains the plan. Duplication without benefit. |
| Asana as the PR destination | GitHub issues + PRs are the audit trail. Asana is input only. |

---

## Feature Dependencies

```
LLM provider abstraction → Ollama integration (Ollama IS the first alternative provider)
LLM provider abstraction → OpenRouter integration (same interface, different adapter)
Provider abstraction → Cost estimator extension (need $0.00 path for Ollama, new rates for OpenRouter models)
Provider abstraction → Prompt caching bypass (do not pass cache_control blocks to non-Anthropic providers)
Asana task source → GitHub PR creation (unchanged — Asana is input only)
Asana task source → Label/tag mutation after PR (must implement to prevent re-selection)
Asana task source → Task-source-agnostic pipeline (selector/planner receive normalized task array regardless of source)
```

---

## Complexity Summary

| Feature Area | Estimated Complexity | Key Risk |
|--------------|---------------------|----------|
| LLM provider interface | Low | Response normalization surface (Anthropic vs OpenAI shape) |
| Ollama adapter | Low–Medium | Tool calling quality varies by model |
| OpenRouter adapter | Low | Nearly identical to Ollama adapter — same OpenAI compat layer |
| Cost estimator for new providers | Low | New model rate table; Ollama = $0.00 |
| Asana task fetching + filtering | Medium | opt_fields pagination, tag GID lookup |
| Asana → issue shape mapping | Low | Trivial data mapping layer |
| Asana post-PR comment | Low | Single POST after PR creation |
| Asana tag removal (re-selection prevention) | Low | Same pattern as GitHub label removal |
| Config schema changes | Low | Additive fields; backward compatible with Anthropic default |

---

## Sources

- Codebase inspection: `AnthropicApiClient`, `ClaudeSelectorService`, `ClaudeExecutorService`, `GlobalConfig`,
  `AnthropicCostEstimator`, `INTEGRATIONS.md` — HIGH confidence (direct inspection)
- Ollama OpenAI-compatible API: training knowledge of Ollama v0.3+ documented behavior — MEDIUM confidence
  (verify `http://localhost:11434/v1` base URL and `chat/completions` endpoint against current Ollama docs)
- OpenRouter API: training knowledge of OpenRouter's published OpenAI-compatible API — MEDIUM confidence
  (verify auth header name and model naming convention against openrouter.ai/docs)
- Asana REST API v1: training knowledge of stable Asana API surface — MEDIUM confidence
  (verify `opt_fields` task fields, stories POST body shape, tag/section mutation endpoints against
  developers.asana.com before implementation)

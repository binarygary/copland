# Architecture Research: Multi-Provider LLM + Asana

**Project:** Copland v1.1
**Researched:** 2026-04-08
**Mode:** Integration architecture for existing codebase

---

## Current State Summary

The three Claude service classes (`ClaudeSelectorService`, `ClaudePlannerService`,
`ClaudeExecutorService`) all receive an `AnthropicApiClient` by constructor injection.
`AnthropicApiClient` wraps the `anthropic-ai/sdk` PHP client
(`$this->client->messages->create()`), adds retry/backoff, and returns the SDK's native
response object.

The coupling points that must change for multi-provider support:

1. **`AnthropicApiClient::messages()` return type** — returns the SDK's native object.
   Callers destructure it directly:
   - `$response->content[0]->text` (selector, planner)
   - `$response->stopReason` (executor)
   - `$response->content[$block]->type === 'tool_use'`, `$block->id`, `$block->name`,
     `$block->input` (executor)
   - `$response->usage->inputTokens`, `->cacheCreationInputTokens`, `->cacheReadInputTokens`
     (all three services)
   This schema is Anthropic SDK-specific.

2. **`ClaudeExecutorService`** — imports `Anthropic\Messages\CacheControlEphemeral` and
   `Anthropic\Messages\TextBlockParam` to build the system prompt block for prompt caching.
   These types belong to the SDK and are not available for other providers.

3. **`AnthropicCostEstimator`** — hardcoded Anthropic model name matching
   (`str_contains($normalized, 'haiku-4-5')`). Fails silently for non-Anthropic model
   strings, returning the Sonnet rate `[3.0, 15.0]`.

4. **`GlobalConfig`** — no provider concept. Only `claude_api_key` and model name fields.
   Model names like `claude-haiku-4-5` implicitly assume Anthropic.

5. **`RunCommand::runRepo()`** — constructs `AnthropicApiClient` directly with
   `new Client(apiKey: $globalConfig->claudeApiKey())` (Anthropic SDK).
   This is the single wiring point for the API client.

6. **`RunOrchestratorService`** — hardwired to `GitHubService` as task source. Step 1
   calls `$this->github->getIssues()`. On-task success/failure comments always target
   GitHub issues. No abstraction over task source exists.

---

## LLM Provider Abstraction

### New Interface and Value Objects

**`app/Contracts/LlmClient.php`**

```php
namespace App\Contracts;

interface LlmClient
{
    public function messages(
        string $model,
        int $maxTokens,
        string|array $system = '',
        array $tools = [],
        array $messages = [],
    ): LlmResponse;
}
```

**`app/Contracts/LlmResponse.php`**

```php
namespace App\Contracts;

class LlmResponse
{
    public function __construct(
        public readonly string   $stopReason,  // 'end_turn' | 'tool_use' | 'stop'
        public readonly array    $content,     // normalized blocks (array shapes below)
        public readonly ?LlmUsage $usage,
    ) {}
}
```

Content blocks are normalized to plain array shape:

```
['type' => 'text',     'text' => '...']
['type' => 'tool_use', 'id' => '...', 'name' => '...', 'input' => [...]]
```

**`app/Contracts/LlmUsage.php`**

```php
namespace App\Contracts;

class LlmUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens  = 0,
    ) {}
}
```

These three contracts eliminate the SDK type dependency from all Claude service classes.
Content blocks are accessed via array keys rather than object properties.

### How Existing Claude Services Adapt

All three services replace the `AnthropicApiClient` type hint with `LlmClient`:

```
ClaudeSelectorService::__construct(private LlmClient $apiClient)
ClaudePlannerService::__construct(private LlmClient $apiClient)
ClaudeExecutorService::__construct(private LlmClient $apiClient)
```

Response access changes from object property to array key:

```
Before: $response->content[0]->text
After:  $response->content[0]['text']

Before: $block->type === 'tool_use'
After:  $block['type'] === 'tool_use'

Before: $block->name, $block->id, (array)$block->input
After:  $block['name'], $block['id'], $block['input']

Before: $response->usage->inputTokens
After:  $response->usage->inputTokens   (same path, now on LlmUsage)
```

The executor's prompt-caching system prompt (`TextBlockParam::with(...)` with
`CacheControlEphemeral`) is Anthropic SDK-specific. The solution is to move the cache
annotation decision into `AnthropicApiClient` itself. The `LlmClient` interface accepts
`system` as `string|array`. When a plain string is passed, `AnthropicApiClient` wraps it
in a `TextBlockParam` with `CacheControlEphemeral` before calling the SDK — Anthropic
caching is preserved without SDK types leaking into the service layer. Non-Anthropic
providers receive the plain string and send it as-is. This removes the
`CacheControlEphemeral` and `TextBlockParam` imports from `ClaudeExecutorService`.

### Config Flow

`GlobalConfig` gains a top-level `llm` block:

```yaml
# ~/.copland.yml

llm:
  provider: anthropic                          # anthropic | ollama | openrouter
  anthropic_api_key: ""
  openrouter_api_key: ""
  ollama_base_url: "http://localhost:11434"

models:
  selector: claude-haiku-4-5
  planner: claude-sonnet-4-6
  executor: claude-sonnet-4-6
```

Per-repo `.copland.yml` can override provider and models:

```yaml
llm:
  provider: ollama
  ollama_base_url: "http://localhost:11434"

models:
  selector: qwen2.5-coder:7b
  planner: qwen2.5-coder:32b
  executor: qwen2.5-coder:32b
```

**Provider resolution order:** per-repo `llm.provider` → global `llm.provider` → default
`anthropic`.

**New methods on `GlobalConfig`:**
- `llmProvider(): string`
- `anthropicApiKey(): string` (renamed from `claudeApiKey()` — old name kept as alias)
- `openRouterApiKey(): string`
- `ollamaBaseUrl(): string`

**New methods on `RepoConfig`:**
- `llmProvider(): ?string` — null means use global default
- `ollamaBaseUrl(): ?string`
- `selectorModel(): ?string` — null means use global default
- `plannerModel(): ?string`
- `executorModel(): ?string`

The combined provider + model resolution happens in `LlmClientFactory` (see below).

---

## Ollama + OpenRouter Concrete Providers

### Implementation Approach

Both Ollama (since 0.1.24) and OpenRouter expose an OpenAI-compatible
`/v1/chat/completions` endpoint. One concrete implementation handles both — the only
differences are base URL and auth header.

**New class: `app/Support/OpenAiCompatClient.php`** — implements `LlmClient`

The method converts Copland's `messages` array (already OpenAI-shaped:
`[['role'=>'user','content'=>...]]`) plus `system` string into a `chat/completions`
request body. Tool definitions follow the OpenAI `tools` format, which is already
compatible with the schema produced by `ClaudeExecutorService::buildTools()` — no
changes needed there.

Response normalization:
- `choices[0].finish_reason` → `LlmResponse::$stopReason` (map `'stop'` to `'end_turn'`
  so executor condition `$response->stopReason === 'end_turn'` still works)
- `choices[0].message.content` → text block
- `choices[0].message.tool_calls[]` → normalized `['type'=>'tool_use','id'=>...,'name'=>...,'input'=>...]`
- `usage.prompt_tokens` / `usage.completion_tokens` → `LlmUsage`
- No cache fields — `cacheWriteTokens` and `cacheReadTokens` default to 0

**Retry:** Extract retry/backoff logic from `AnthropicApiClient` into a decorator:

**New class: `app/Support/RetryingLlmClient.php`** — wraps any `LlmClient`, adds
exponential backoff. Both `AnthropicApiClient` and `OpenAiCompatClient` become thin
clients. `RetryingLlmClient` wraps whichever concrete client is constructed.

**New class: `app/Support/LlmClientFactory.php`**

```php
public static function make(
    string $provider,        // 'anthropic' | 'ollama' | 'openrouter'
    GlobalConfig $global,
    ?RepoConfig $repo = null,
): LlmClient
```

Returns `RetryingLlmClient` wrapping the appropriate concrete client. Reads API keys,
base URLs, and retry config from the merged global + repo config.

**Ollama specifics:**
- Base URL: `http://localhost:11434/v1`
- No auth header
- Tool call support depends on the model. Models known to support tools as of mid-2025:
  `qwen2.5-coder`, `llama3.1`, `mistral-nemo`. `LlmClientFactory` should emit a
  descriptive error on first tool dispatch failure, not a silent hang.

**OpenRouter specifics:**
- Base URL: `https://openrouter.ai/api/v1`
- Auth: `Authorization: Bearer {openrouter_api_key}`
- OpenRouter recommends `HTTP-Referer` and `X-Title` headers for routing/analytics — add
  as optional config `llm.openrouter_referer` and `llm.openrouter_title`.

**Cost reporting for non-Anthropic models:**
`AnthropicCostEstimator::ratesForModel()` currently falls back to `[3.0, 15.0]` (Sonnet
rate) for unknown model strings. Change the fallback to `[0.0, 0.0]` for any string not
matching known Anthropic patterns. Ollama is local (no dollar cost). OpenRouter model
costs vary — display the token counts but show `$0.0000 est. (rate unknown)` for
unrecognized model strings rather than a misleading Sonnet-rate estimate.

---

## Asana Task Source

### IssueSource Interface

The orchestrator's task-fetch and task-feedback calls are currently GitHub-specific and
inline. Introducing an interface separates "where do tasks come from" and "how do we
report back" from the execution pipeline.

**New interface: `app/Contracts/TaskSource.php`**

```php
namespace App\Contracts;

interface TaskSource
{
    /**
     * Return candidate tasks as normalized arrays.
     * Required fields: number (int), title (string), body (string), labels (string[]).
     * 'number' is a source-local identifier usable by selector and prefilter.
     */
    public function getTasks(string $repo, array $repoProfile): array;

    /**
     * Called on PR open success. Post PR link to the source task.
     */
    public function onSuccess(string $repo, int $taskNumber, string $prUrl, string $summary): void;

    /**
     * Called on execution or verification failure. Post failure note to the source task.
     */
    public function onFailure(string $repo, int $taskNumber, string $reason): void;
}
```

**GitHub task source: `app/Services/GitHubTaskSource.php`** — wraps `GitHubService`.
Moves the `getIssues()`, `commentOnIssue()` success/failure calls that are currently
inline in `RunOrchestratorService` into this class. No behavior change — this is a
structural extraction. Label removal (`removeLabel()`) stays in the orchestrator directly
because it is a GitHub PR-tracking concern shared regardless of task source.

**Asana task source: `app/Services/AsanaTaskSource.php`** — wraps `AsanaService`.

### New AsanaService

**Location: `app/Services/AsanaService.php`**

Responsibilities:
- Fetch tasks from an Asana project:
  `GET https://app.asana.com/api/1.0/tasks?project={gid}&opt_fields=gid,name,notes,tags.name,completed`
- Filter to `completed: false` tasks matching required tags.
- Post a story (comment) to a task:
  `POST https://app.asana.com/api/1.0/tasks/{gid}/stories`
  Body: `{"data": {"text": "..."}}`

**Auth:** Personal Access Token stored in `~/.copland.yml` as `asana.pat`.
Authorization header: `Authorization: Bearer {pat}`.

**HTTP client:** Reuse Guzzle (already a dependency). New `Client` instance with
`base_uri: https://app.asana.com/api/1.0`.

**Public interface:**
```php
public function getTasks(string $projectGid): array          // returns normalized task arrays
public function addStory(string $taskGid, string $text): void
```

`AsanaTaskSource` wraps `AsanaService`, normalizes tasks to the `[number, title, body,
labels]` shape the selector and prefilter expect, and implements `onSuccess` / `onFailure`
via `addStory`.

**GID handling:** Asana GIDs are 16-digit numeric strings (e.g. `"1204567890123456"`). On
64-bit PHP, these are safely representable as `int` (max PHP int on 64-bit is ~9.2×10^18;
max Asana GID observed is ~1.8×10^15). `AsanaTaskSource` holds a `gidMap` property:
a `[int $index => string $gid]` array populated in `getTasks()`. The `number` field
exposed to selector/prefilter is the list index (1-based). `onSuccess` and `onFailure`
look up the GID from `gidMap` by task number.

### Config: Project to Repo Mapping

```yaml
# ~/.copland.yml

asana:
  pat: ""
  projects:
    - project_gid: "1204567890123456"
      repo: owner/repo
      required_tags: [agent-ready]       # optional; no filter if omitted
      blocked_tags:  [agent-skip, blocked] # optional
```

**New methods on `GlobalConfig`:**
- `asanaPat(): string`
- `asanaProjects(): array`
- `asanaProjectGidForRepo(string $repo): ?string`

Per-repo `.copland.yml` can declare task source explicitly:

```yaml
task_source: asana    # github (default) | asana
```

**New method on `RepoConfig`:**
- `taskSource(): ?string` — null means auto-detect from global config

### Task Source Resolution

In `RunCommand::runRepo()` (or extracted to `TaskSourceFactory`):

1. If `RepoConfig::taskSource()` is `'asana'` → use `AsanaTaskSource`.
2. Else if `GlobalConfig::asanaProjectGidForRepo($repo)` returns non-null → use
   `AsanaTaskSource`.
3. Otherwise → use `GitHubTaskSource`.

**New class: `app/Support/TaskSourceFactory.php`**

### Task Lifecycle: Selection → PR → Comment

Current GitHub-inline flow in `RunOrchestratorService` (steps affected):

| Step | Current call | After refactor |
|------|-------------|----------------|
| Step 1: fetch tasks | `$this->github->getIssues(...)` | `$this->taskSource->getTasks(...)` |
| Step 6: exec failure | `$this->github->commentOnIssue(...)` | `$this->taskSource->onFailure(...)` |
| Step 7: verify failure | `$this->github->commentOnIssue(...)` | `$this->taskSource->onFailure(...)` |
| Post-PR: success | `$this->github->commentOnIssue(...)` + `removeLabel(...)` | `$this->taskSource->onSuccess(...)` + `$this->github->removeLabel(...)` |

PR creation (`createDraftPr`) always targets GitHub regardless of task source — this stays
on `GitHubService` directly in the orchestrator.

Label removal (`removeLabel`) stays on `GitHubService` directly — it is not a task-source
concern; it reflects PR state on the GitHub issue tracker regardless of where the task
originated.

`GitHubTaskSource::onSuccess()` calls `github->commentOnIssue()` with the PR link — exact
current behavior.
`AsanaTaskSource::onSuccess()` calls `asana->addStory(gid, "PR opened: {$prUrl}\n\n{$summary}")`.
`AsanaTaskSource::onFailure()` calls `asana->addStory(gid, "Agent run failed: {$reason}")`.

**`RunOrchestratorService` constructor change:**

```
Before: private GitHubService $github (used for both tasks and PR/label ops)
After:  private GitHubService $github (PR creation + label removal only)
        private TaskSource $taskSource (task fetch + task-side feedback)
```

### Prefilter Compatibility

`IssuePrefilterService` currently calls `$this->github->hasOpenLinkedPr()` to exclude
issues already in progress. Asana tasks have no equivalent — there is no linked-PR concept
in Asana's data model.

Recommendation: keep `IssuePrefilterService` as-is. When task source is Asana, the
`GitHubTaskSource`-specific prefilter step (linked PR check) is simply skipped because
`AsanaTaskSource::getTasks()` does not involve GitHub issues at all. The
`IssuePrefilterService` only operates on the normalized task list; the `hasOpenLinkedPr`
check requires the GitHub issue `number` field to correlate, which Asana tasks will not
have a real GitHub issue number for. Solution: pass the task source type into
`IssuePrefilterService` (or provide an injectable skip flag), and skip the
`hasOpenLinkedPr` call for non-GitHub sources.

---

## Suggested Build Order

**Phase 1 — LlmClient contracts + AnthropicApiClient normalization**

Introduce `LlmClient`, `LlmResponse`, `LlmUsage`. Adapt `AnthropicApiClient` to implement
`LlmClient` (wrap native SDK response in `LlmResponse`/`LlmUsage`). Move
`CacheControlEphemeral` wrapping into `AnthropicApiClient::messages()`. Adapt the three
Claude services to accept `LlmClient` and use array-access on response content.

Zero behavior change. All existing tests pass. This is the prerequisite for everything
else in the LLM track.

**Phase 2 — OpenAiCompatClient + LlmClientFactory + config**

Implement `OpenAiCompatClient` (Guzzle-based, normalizes OpenAI-compat responses to
`LlmResponse`). Extract retry into `RetryingLlmClient` decorator. Implement
`LlmClientFactory`. Extend `GlobalConfig` with `llmProvider()`, `openRouterApiKey()`,
`ollamaBaseUrl()`. Extend `RepoConfig` with optional provider + model overrides. Wire
`RunCommand` and `PlanCommand` through `LlmClientFactory`.

Update `AnthropicCostEstimator` fallback to `[0.0, 0.0]` for unrecognized model strings.

**Phase 3 — TaskSource interface + GitHubTaskSource refactor**

Introduce `TaskSource`. Implement `GitHubTaskSource` wrapping existing `GitHubService`
behavior. Update `RunOrchestratorService` to accept `TaskSource` alongside `GitHubService`
(PR + label ops stay on `GitHubService`). Route task fetch and task-side feedback through
`TaskSource`. Wire `RunCommand` to inject `GitHubTaskSource`.

Behavior is identical to current. All existing tests continue passing. This is the
structural prerequisite for Phase 4.

**Phase 4 — AsanaService + AsanaTaskSource + config**

Implement `AsanaService` (Guzzle, PAT auth, `getTasks`, `addStory`). Implement
`AsanaTaskSource` with GID map. Extend `GlobalConfig` with `asanaPat()`, `asanaProjects()`,
`asanaProjectGidForRepo()`. Extend `RepoConfig` with `taskSource()`. Implement
`TaskSourceFactory`. Wire `RunCommand` to use `TaskSourceFactory`.

Update `IssuePrefilterService` to skip the `hasOpenLinkedPr` check when task source is
not GitHub (inject a bool flag or pass task source type).

**Phase 5 — Tests**

Unit tests for: `OpenAiCompatClient` response normalization and retry, `LlmClientFactory`
provider selection logic, `AsanaService` with mock Guzzle, `AsanaTaskSource` lifecycle
(getTasks normalization, onSuccess story, onFailure story), `TaskSourceFactory` resolution
logic, `RunOrchestratorService` with injected `TaskSource` mock.

Note: Phases 1–4 should each include their own unit tests inline. Phase 5 covers
integration-style tests and any gaps.

**Rationale for this order:**
- Phase 1 is the seam that makes Phases 2-4 safe without breaking existing behavior.
- Phase 2 ships Ollama/OpenRouter without touching the task source layer at all.
- Phase 3 is a structural refactor that validates correctness before any new behavior.
- Phase 4 is additive only — no existing code path is modified by it.

---

## Files Changed vs New

### New Files

| File | Purpose |
|------|---------|
| `app/Contracts/LlmClient.php` | Interface for all LLM providers |
| `app/Contracts/LlmResponse.php` | Normalized response value object |
| `app/Contracts/LlmUsage.php` | Normalized usage value object |
| `app/Contracts/TaskSource.php` | Interface for task sources |
| `app/Support/OpenAiCompatClient.php` | OpenAI-compat HTTP client (Ollama + OpenRouter) |
| `app/Support/RetryingLlmClient.php` | Retry/backoff decorator for any LlmClient |
| `app/Support/LlmClientFactory.php` | Resolves provider config → concrete LlmClient |
| `app/Support/TaskSourceFactory.php` | Resolves task source config → concrete TaskSource |
| `app/Services/GitHubTaskSource.php` | Wraps GitHubService as TaskSource |
| `app/Services/AsanaService.php` | Asana REST API client (tasks + stories) |
| `app/Services/AsanaTaskSource.php` | Wraps AsanaService as TaskSource |

### Modified Files

| File | Changes |
|------|---------|
| `app/Support/AnthropicApiClient.php` | Implement `LlmClient`; normalize SDK response to `LlmResponse`/`LlmUsage`; absorb `CacheControlEphemeral` wrapping; strip retry into `RetryingLlmClient` |
| `app/Services/ClaudeSelectorService.php` | Type hint `LlmClient`; array-access response content |
| `app/Services/ClaudePlannerService.php` | Type hint `LlmClient`; array-access response content |
| `app/Services/ClaudeExecutorService.php` | Type hint `LlmClient`; array-access response content and tool blocks; remove `CacheControlEphemeral`/`TextBlockParam` imports; pass system as string |
| `app/Services/RunOrchestratorService.php` | Add `TaskSource $taskSource` constructor param; route `getTasks()`, `onSuccess()`, `onFailure()` through task source; keep `GitHubService` for PR and label ops |
| `app/Services/IssuePrefilterService.php` | Skip `hasOpenLinkedPr` check for non-GitHub task sources (bool flag or task source type param) |
| `app/Config/GlobalConfig.php` | Add `llmProvider()`, `openRouterApiKey()`, `ollamaBaseUrl()`, `asanaPat()`, `asanaProjects()`, `asanaProjectGidForRepo()`; update default YAML template; keep `claudeApiKey()` as alias for `anthropicApiKey()` |
| `app/Config/RepoConfig.php` | Add `llmProvider()`, `ollamaBaseUrl()`, `taskSource()`, per-role model getters |
| `app/Commands/RunCommand.php` | Replace direct `new AnthropicApiClient(new Client(...))` with `LlmClientFactory::make()`; inject `TaskSourceFactory`-resolved source into orchestrator |
| `app/Commands/PlanCommand.php` | Same LlmClientFactory wiring change |
| `app/Support/AnthropicCostEstimator.php` | Change unknown-model fallback from `[3.0, 15.0]` to `[0.0, 0.0]`; add display note for zero-cost models |

### Unchanged Files

`ExecutorPolicy`, `ExecutorRunState`, `GitService`, `WorkspaceService`,
`VerificationService`, `PlanValidatorService`, all `app/Data/` classes,
`AnthropicMessageSerializer`, `FileMutationHelper`, `RunLogStore`, `PlanArtifactStore`,
`RunProgressSnapshot`, `ExecutorProgressFormatter`.

---

## Key Integration Constraints

**Prompt caching with non-Anthropic providers.** The `CacheControlEphemeral` annotation
has no standard equivalent on OpenAI-compat endpoints. Moving the wrapping into
`AnthropicApiClient` means Ollama and OpenRouter simply receive a plain string for
`system` and never see the annotation. No behavior change for them; caching continues to
work for Anthropic as before.

**Tool support on Ollama.** The executor requires tool/function calling. Ollama models
without function calling capability will return text responses without `tool_calls`, which
causes the executor loop to treat every response as `end_turn` with no progress. Document
supported models in config. Consider a startup check that makes a minimal tool-call test
request and surfaces a clear error rather than a silent infinite loop.

**Asana GID as task number.** The `number` field in the normalized task array is used by
`SelectionResult::selectedIssueNumber` (an `int`). Asana GIDs are large but fit in 64-bit
PHP int. `AsanaTaskSource` stores GIDs as strings in `gidMap` and exposes a sequential
1-based index as `number`. The selector sees numbers like `1, 2, 3`; `AsanaTaskSource`
maps back to `gidMap[1]` for API calls.

**Cost display for Ollama.** Ollama is free (local). `AnthropicCostEstimator` with
`[0.0, 0.0]` rates will show `$0.0000 est.` which is accurate. Token counts are still
reported for awareness of model load.

**Backward compatibility.** The `claude_api_key` field in existing `~/.copland.yml` files
must continue to work. `GlobalConfig::anthropicApiKey()` reads from `llm.anthropic_api_key`
first, then falls back to the top-level `claude_api_key` field. Existing users' configs
work without modification.

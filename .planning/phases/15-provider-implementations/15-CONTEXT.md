# Phase 15: Provider Implementations - Context

**Gathered:** 2026-04-08
**Status:** Ready for planning

<domain>
## Phase Boundary

Add `OpenAiCompatClient` (covering both Ollama and OpenRouter behind one class), an `LlmClientFactory` that resolves the right client per stage, and config wiring so users can set a global default provider and override it per repo or per stage. Also: Ollama reachability probe, Ollama model capability warning, OpenRouter attribution headers, tool schema translation, and `stopReason` normalization.

No changes to the three Claude services' business logic — only constructor injection and `stopReason` check sites update.

</domain>

<decisions>
## Implementation Decisions

### Config Schema

- **D-01:** Keep the existing `models:` key in `~/.copland.yml` (selector/planner/executor model names). Add a **separate** `llm:` block for provider + credentials. The two keys coexist — `models:` remains the model name source; `llm:` is the provider/routing source.

- **D-02:** The `llm:` block uses **nested provider objects**:

```yaml
llm:
  default:
    provider: anthropic        # or ollama / openrouter
  stages:                      # optional — overrides default per stage
    selector:
      provider: ollama
      base_url: http://localhost:11434
      model: llama3.1
    executor:
      provider: openrouter
      api_key: sk-or-...
      model: google/gemini-flash-1.5
```

- **D-03:** When `llm:` is absent (existing installs), the factory defaults to `provider: anthropic` using `claude_api_key` from the top level. Backwards-compatible — no migration needed.

- **D-04:** Per-repo override in `.copland.yml` uses the **same nested structure** as the global file. Repo-level `llm:` settings override global at the same granularity (stage-level overrides stage, default overrides default).

- **D-05:** Resolution order: repo `llm.stages.{stage}` → global `llm.stages.{stage}` → repo `llm.default` → global `llm.default` → implicit Anthropic fallback.

### Per-Stage Client Resolution

- **D-06:** A new `LlmClientFactory` class is responsible for building `LlmClient` instances per stage. Services receive the resolved client (not the factory) in their constructors — no per-call re-resolution.

- **D-07:** Factory signature: `LlmClientFactory::forStage(string $stage, GlobalConfig $global, ?RepoConfig $repo = null): LlmClient`. Stage names are: `'selector'`, `'planner'`, `'executor'`.

- **D-08:** `AppServiceProvider` registers `LlmClientFactory` in the container. For each Claude service, `AppServiceProvider` calls `LlmClientFactory::forStage(...)` at bind time to wire the correct client instance. The single `LlmClient::class` binding from Phase 14 is replaced by per-service bindings.

### Tool Schema Translation

- **D-09:** A separate `ToolSchemaTranslator` class handles the Anthropic → OpenAI tool schema mapping. `OpenAiCompatClient::complete()` calls it before sending. Rationale: independently testable, and the transformation is non-trivial enough to deserve its own class.

- **D-10:** Translation shape:
  - Input (Anthropic): `['name' => '...', 'description' => '...', 'input_schema' => ['type' => 'object', 'properties' => [...]]]`
  - Output (OpenAI): `['type' => 'function', 'function' => ['name' => '...', 'description' => '...', 'parameters' => ['type' => 'object', 'properties' => [...]]]]`

- **D-11:** `cache_control` blocks in `SystemBlock` are **stripped** by `OpenAiCompatClient` before building the request (D-07 carried from Phase 14). Only plain text is sent.

### stopReason Normalization

- **D-12:** Canonical `LlmResponse.stopReason` values are **OpenAI conventions**: `stop` (normal end) and `tool_calls` (tool invocation). All clients normalize to this.

- **D-13:** A shared `LlmResponseNormalizer` (or static helper) handles the mapping:
  - `AnthropicApiClient`: `end_turn` → `stop`, `tool_use` → `tool_calls`
  - `OpenAiCompatClient`: `stop` stays `stop`, `tool_calls` stays `tool_calls`, anything else passes through

- **D-14:** The three Claude services will be updated to check `$response->stopReason === 'tool_calls'` (previously `'tool_use'` from Anthropic). This is a required change — the executor's agentic loop depends on detecting tool calls.

### Ollama Reachability Probe

- **D-15:** Before the orchestration loop starts (in `RunOrchestratorService` or the run command), check if any stage is configured for Ollama. If so, probe `GET {base_url}/api/tags`. On failure (non-200 or connection refused), exit with a clear error: `"Ollama is not reachable at {base_url}. Is it running?"`. Run does not start.

- **D-16:** Probe happens once at startup even if multiple stages use Ollama (dedup by base_url).

### Ollama Model Capability Warning

- **D-17:** Known tool-capable model list is **hardcoded** in a constant in `OpenAiCompatClient` (or a sibling class). No user config. Easy to update in future versions.

- **D-18:** Warning fires **once at startup**, before the run begins, if any Ollama-configured stage uses a model not on the list. Message format: `⚠ Warning: Ollama model '{model}' is not on the known tool-capable list. Tool use may fail.` Run continues regardless.

### OpenRouter Attribution Headers

- **D-19:** All `OpenAiCompatClient` requests to OpenRouter include:
  - `HTTP-Referer: https://github.com/binarygary/copland`
  - `X-Title: Copland`

  These are injected in `OpenAiCompatClient` when `provider: openrouter` is detected.

### Claude's Discretion

- Exact namespace for `LlmClientFactory`, `ToolSchemaTranslator`, `LlmResponseNormalizer` — follow existing conventions (`App\Support\` or `App\Services\`)
- Whether `LlmResponseNormalizer` is a class, trait, or static helper — choose simplest form
- How `openai-php/client` is instantiated for Ollama vs OpenRouter (same client, different base URL + auth)
- Exact list of known tool-capable Ollama models to hardcode
- Whether the reachability probe lives in `RunOrchestratorService` or is called from `RunCommand`

</decisions>

<specifics>
## Specific Ideas

- `openai-php/client` is the chosen library for `OpenAiCompatClient` (covers both Ollama and OpenRouter — noted in STATE.md milestone decisions)
- Ollama uses `base_url + model` (no API key); OpenRouter uses `api_key + model` (base URL is fixed at `https://openrouter.ai/api/v1`)
- `ToolSchemaTranslator` was chosen over inline translation specifically for testability

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Interface and value objects (Phase 14 output)
- `app/Contracts/LlmClient.php` — The interface all providers must implement; `complete()` signature is locked
- `app/Data/LlmResponse.php` — Response shape; `stopReason` will be updated to OpenAI canonical values
- `app/Data/LlmUsage.php` — Token counts shape; no changes needed
- `app/Data/SystemBlock.php` — System prompt block; `cache` flag must be stripped by non-Anthropic clients

### Existing clients and config
- `app/Support/AnthropicApiClient.php` — Existing implementation; must be updated for stopReason normalization
- `app/Config/GlobalConfig.php` — How global config is parsed/defaulted; new `llm:` block added here
- `app/Config/RepoConfig.php` — How repo config is parsed; new `llm:` block added here
- `app/Providers/AppServiceProvider.php` — Current LlmClient binding; will be replaced with per-service factory wiring

### Requirements
- `.planning/REQUIREMENTS.md` — PROV-03 (global provider config), PROV-04 (per-repo override), PROV-05 (per-stage), OLLAMA-01, OLLAMA-02, OLLAMA-03, OPENR-01, OPENR-02

### Prior phase decisions
- `.planning/phases/14-llmclient-contracts/14-CONTEXT.md` — All Phase 14 decisions (interface shape, SystemBlock, LlmUsage, etc.)
- `.planning/STATE.md` — Milestone decisions: `openai-php/client` chosen, cache_control stripping required, stopReason normalization required

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `GlobalConfig` — Existing YAML-parsing config class; new `llm:` key will be added with getter methods following the same `$this->data['key'] ?? 'default'` pattern
- `RepoConfig` — Same pattern; add `llm:` support here too
- `AnthropicCostEstimator` — Stays as-is; cost estimation per stage is unchanged
- `AppServiceProvider` — Currently has one `bind(LlmClient::class, AnthropicApiClient::class)`; will be expanded to call `LlmClientFactory::forStage()` per service

### Established Patterns
- `final class` with `readonly` constructor properties for data objects
- Constructor injection for service dependencies
- Config classes: load/parse in constructor, typed public getters with defaults

### Integration Points
- `RunOrchestratorService` — Ollama reachability probe fires here (or in `RunCommand`) before the 8-step loop
- `ClaudeSelectorService`, `ClaudePlannerService`, `ClaudeExecutorService` — Constructor changes: `LlmClient $apiClient` stays the same; `stopReason` check in executor changes from `'tool_use'` to `'tool_calls'`
- `AppServiceProvider::register()` — Wires `LlmClientFactory` and per-service client bindings

</code_context>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 15-provider-implementations*
*Context gathered: 2026-04-08*

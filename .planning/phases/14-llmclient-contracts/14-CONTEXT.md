# Phase 14: LlmClient Contracts - Context

**Gathered:** 2026-04-08
**Status:** Ready for planning

<domain>
## Phase Boundary

Introduce a `LlmClient` interface that `AnthropicApiClient` implements, and update the three Claude services (`ClaudeSelectorService`, `ClaudePlannerService`, `ClaudeExecutorService`) to depend on the interface rather than the concrete class. No behavior change — this is a pure structural refactor enabling Phase 15's provider switching.

New value objects introduced: `LlmClient` (interface), `LlmResponse`, `LlmUsage`, `SystemBlock`.

</domain>

<decisions>
## Implementation Decisions

### LlmResponse Content Shape

- **D-01:** `LlmResponse` uses a **thin wrapper** — content is a plain `array` of associative arrays (e.g. `['type' => 'text', 'text' => '...']`, `['type' => 'tool_use', 'name' => '...', 'input' => [], 'id' => '...']`). No typed content block classes.
- **D-02:** The three Claude services **will be updated** to use array access (`$block['type']`, `$block['text']`, etc.) instead of object property access. This is an expected change for a refactor phase.
- **D-03:** `LlmResponse` shape:

```php
final class LlmResponse {
    public readonly array $content;    // plain assoc arrays
    public readonly string $stopReason;
    public readonly LlmUsage $usage;
}
```

### System Prompt in Interface

- **D-04:** `LlmClient::complete()` accepts the system prompt as `array $systemBlocks = []` — an array of `SystemBlock` value objects (not a plain string, not raw assoc arrays).
- **D-05:** `SystemBlock` is a typed readonly value object:

```php
final class SystemBlock {
    public function __construct(
        public readonly string $text,
        public readonly bool $cache = false,
    ) {}
}
```

- **D-06:** `AnthropicApiClient::complete()` translates `SystemBlock[]` to `TextBlockParam`+`CacheControlEphemeral` SDK types internally. Cache control stays an implementation detail of the Anthropic client.
- **D-07:** Phase 15 providers will strip the `cache` flag when building their requests (per existing STATE.md decision: "Anthropic cache_control blocks must be stripped before sending to non-Anthropic providers").
- **D-08:** The interface signature:

```php
interface LlmClient {
    /** @param SystemBlock[] $systemBlocks */
    public function complete(
        string $model,
        int $maxTokens,
        array $messages,
        array $tools = [],
        array $systemBlocks = [],
    ): LlmResponse;
}
```

### LlmUsage + Cost

- **D-09:** `LlmUsage` carries **raw tokens only** — no cost estimation:

```php
final class LlmUsage {
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}
}
```

- **D-10:** Cost estimation stays in each Claude service via `AnthropicCostEstimator::forModel()`. Each service converts `LlmUsage` → `ModelUsage` (adding model name + cost).
- **D-11:** `ModelUsage` is **kept as-is**. `LlmUsage` is a new interface-level type; `ModelUsage` continues to serve reporting downstream (`RunResult`, `ExecutionResult`, etc.).

### Preserved Behaviors (non-negotiable)

- **D-12:** All retry and backoff behavior in `AnthropicApiClient` is preserved unchanged (Phase 1 decision).
- **D-13:** Prompt caching on the executor system prompt is preserved — `SystemBlock(cache: true)` passed in `systemBlocks` (Phase 4 decision).
- **D-14:** Cache-write and cache-read tokens billed separately from uncached input — preserved via `cacheWriteTokens` and `cacheReadTokens` in `LlmUsage` (Phase 5 decision).

### Claude's Discretion

- Namespace placement for new types (`App\Contracts\LlmClient`? `App\Data\LlmResponse`? etc.) — Claude decides following existing conventions.
- Whether to rename `AnthropicApiClient::messages()` to `complete()` or add `complete()` as a new method delegating to the existing implementation — Claude decides based on minimal diff.
- Exact `stopReason` normalization (`end_turn` → `stop` parity noted in STATE.md) — Claude decides whether to normalize in this phase or defer to Phase 15 when non-Anthropic providers are added.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Existing implementation to adapt
- `app/Support/AnthropicApiClient.php` — existing `messages()` method signature, retry logic, and cache control; must implement `LlmClient`
- `app/Services/ClaudeSelectorService.php` — uses `AnthropicApiClient`, accesses `$response->content[0]->text` and `$response->usage`
- `app/Services/ClaudePlannerService.php` — same pattern as selector
- `app/Services/ClaudeExecutorService.php` — uses structured system prompt with `TextBlockParam`+`CacheControlEphemeral`; iterates content blocks checking `->type`; checks `->stopReason`
- `app/Data/ModelUsage.php` — existing usage/cost data object that must remain unchanged

### Requirements
- `REQUIREMENTS.md` — PROV-01 (LlmClient interface, LlmResponse, LlmUsage), PROV-02 (AnthropicApiClient implements LlmClient)

### Prior phase decisions referenced
- Phase 1: retry behavior owned by `AnthropicApiClient`
- Phase 4: executor prompt caching on system prompt block
- Phase 5: cache-write/read tokens billed separately
- STATE.md: "Anthropic cache_control blocks must be stripped before sending to non-Anthropic providers; normalize stopReason in LlmResponse (end_turn → stop parity)"

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AnthropicCostEstimator` — stays; each Claude service continues calling `forModel()` to convert `LlmUsage` to `ModelUsage`
- `AnthropicMessageSerializer` — stays; used by executor to build assistant content blocks
- `ModelUsage` — stays; interface-agnostic reporting object
- Anthropic SDK types (`TextBlockParam`, `CacheControlEphemeral`) move inside `AnthropicApiClient` internals

### Established Patterns
- Data classes use `final class` with `readonly` constructor properties (see `ModelUsage`, `PlanResult`, etc.)
- Services accept dependencies via constructor injection
- Constructor property promotion used throughout

### Integration Points
- `AppServiceProvider` will need updating to bind `LlmClient` to `AnthropicApiClient` in the service container
- All three Claude service constructors change from `AnthropicApiClient $apiClient` to `LlmClient $apiClient`

</code_context>

<specifics>
## Specific Ideas

- User explicitly chose structured `SystemBlock` value object over plain assoc arrays for the system param — keeps the interface typed even though it's slightly more Phase 14 work.
- User chose to keep `LlmUsage` token-only and preserve `ModelUsage` — no merge, no downstream disruption.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 14-llmclient-contracts*
*Context gathered: 2026-04-08*

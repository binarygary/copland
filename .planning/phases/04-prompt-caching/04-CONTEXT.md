# Phase 4: Prompt Caching - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Introduce Anthropic Prompt Caching to the executor loop to significantly reduce input token costs. 

The primary target is the executor's system prompt and tool definitions, which are sent on every round of the agentic loop (up to 12 rounds). By adding `cache_control: {type: ephemeral}` to the system prompt, subsequent rounds will hit the cache, paying only ~10% of the normal input cost.

This phase focuses on the **mechanism** of caching (sending the correct headers/payloads). Phase 5 will update the cost model to actually track and report these savings accurately.

</domain>

<decisions>
## Implementation Decisions

### Caching Strategy
- **D-01:** Apply `cache_control` specifically to the **system prompt** in the executor loop. This provides the highest ROI as the system prompt (including tool definitions) is large (~800-1000 tokens) and remains static across all rounds.
- **D-02:** Do NOT apply caching to the `messages` array in the executor loop. Since the conversation history grows each round, caching intermediate messages would result in frequent cache misses and potentially higher costs/latency for minimal gain.
- **D-03:** Use the `ephemeral` cache type (currently the only type supported by Anthropic).

### SDK Integration
- **D-04:** Use the structured `Anthropic\Messages\TextBlockParam` and `Anthropic\Messages\CacheControlEphemeral` classes from the SDK to construct the cached system prompt.
- **D-05:** Update `AnthropicApiClient::messages()` to support the array-based `system` parameter required for block-level caching.

### Cost Model (Partial)
- **D-06:** In this phase, continue using the existing `AnthropicCostEstimator` which tracks flat input/output tokens. 
- **D-07:** Caching hits will show up as `cache_read_input_tokens` in the API response, but `AnthropicCostEstimator` currently ignores these. This is acceptable for Phase 4; Phase 5 will introduce `cache_creation_input_tokens` and `cache_read_input_tokens` tracking to `ModelUsage`.

### Tool Definitions
- **D-08:** Verify if the SDK automatically handles `cache_control` on tool definitions. If not, consider manually injecting it into the tool array if Anthropic supports tool-level caching (research suggests system prompt is sufficient and more reliable). *Decision: Stick to system prompt caching first as it covers tools anyway when sent via the Messages API.*

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 4 success criteria: `cache_creation_input_tokens` on round 1, `cache_read_input_tokens` on rounds 2-12.

### Existing Code (direct edit targets)
- `app/Support/AnthropicApiClient.php` — Needs to handle array-based `system` prompts.
- `app/Services/ClaudeExecutorService.php` — Needs to wrap `$systemPrompt` in a `TextBlockParam` with `cache_control`.

### SDK Reference
- `vendor/anthropic-ai/sdk/src/Messages/TextBlockParam.php` — Used for structured content blocks.
- `vendor/anthropic-ai/sdk/src/Messages/CacheControlEphemeral.php` — Used for the cache marker.
- `vendor/anthropic-ai/sdk/src/Messages/Usage.php` — Contains `cacheCreationInputTokens` and `cacheReadInputTokens` (for Phase 5).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AnthropicApiClient` already centralizes all message creation calls.
- `ClaudeExecutorService::buildTools()` provides the tool definitions that are included in the system prompt context.

### Established Patterns
- The executor uses a `while(true)` loop for rounds. Round 1 will trigger a cache creation, Rounds 2+ will trigger cache reads.

</code_context>

<specifics>
## Specific Ideas

- Wrap the system prompt in `ClaudeExecutorService::executeWithPolicy()`:
  ```php
  $system = [
      \Anthropic\Messages\TextBlockParam::with(
          text: $systemPrompt,
          cacheControl: \Anthropic\Messages\CacheControlEphemeral::with()
      )
  ];
  ```
- This ensures the entire system prompt + tool definitions are cached.

</specifics>

<deferred>
## Deferred Ideas

- **Phase 5:** Tracking `cache_creation_input_tokens` (1.25x cost) and `cache_read_input_tokens` (0.1x cost).
- **Phase 5:** Updating `ModelUsage` data object to hold these extra fields.
- **Phase 5:** Updating `AnthropicCostEstimator` to use the correct rates for cached tokens.

</deferred>

---

*Phase: 04-prompt-caching*
*Context gathered: 2026-04-03*

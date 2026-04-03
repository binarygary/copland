# Phase 5: Cache-Aware Cost Model - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Update Copland's cost tracking to accurately account for Anthropic's prompt caching. 

Currently, `ModelUsage` and `AnthropicCostEstimator` only track flat input and output tokens. This phase introduces `cache_creation_input_tokens` (billed at 1.25x the base input rate) and `cache_read_input_tokens` (billed at 0.1x the base input rate).

Success looks like CLI output and run logs accurately reflecting the cost savings from the caching implemented in Phase 4.

</domain>

<decisions>
## Implementation Decisions

### Data Model Updates
- **D-01:** Update `App\Data\ModelUsage` to include `cacheWriteTokens` and `cacheReadTokens` as nullable or defaulted integers.
- **D-02:** Update `ModelUsage::add()` to correctly sum these new token types.
- **D-03:** `estimatedCostUsd` in `ModelUsage` must be recalculated or provided during construction based on the new breakdown.

### Cost Calculation
- **D-04:** Update `AnthropicCostEstimator::forModel()` to accept optional `$cacheWrite` and `$cacheRead` tokens.
- **D-05:** Apply rates:
    - Base Input: `rate` (e.g., $3.00/MTok for Sonnet 3.5)
    - Cache Write: `rate * 1.25`
    - Cache Read: `rate * 0.10`
    - Output: `outputRate` (e.g., $15.00/MTok for Sonnet 3.5)
- **D-06:** Total Cost = `(uncached_input * rate) + (cache_write * rate * 1.25) + (cache_read * rate * 0.10) + (output * outputRate)`.
- *Note:* Anthropic's `input_tokens` in the response *includes* `cache_read_input_tokens`, but *excludes* `cache_creation_input_tokens`. Wait—let's double check. 
- *Correction from Anthropic Docs:* `input_tokens` is the total number of input tokens processed. `cache_read_input_tokens` is the subset of `input_tokens` that were read from cache. `cache_creation_input_tokens` are tokens that were *added* to the cache (and are also counted in `input_tokens` if they were part of the input). 
- *Actually:* `input_tokens` is the "total input tokens" billed at various rates. 
    - `input_tokens` - `cache_read_input_tokens` = tokens billed at 1.0x rate.
    - `cache_read_input_tokens` = tokens billed at 0.1x rate.
    - `cache_creation_input_tokens` = tokens billed at 1.25x rate (these are *not* in `input_tokens` usually, or they are the "writing" part).
- *Official Anthropic behavior:* `input_tokens` is everything in the request. `cache_read_input_tokens` is how many of those were cached. `cache_creation_input_tokens` is how many were *written* to cache this time.
- *Wait, let's look at the Usage object again:* `inputTokens`, `outputTokens`, `cacheCreationInputTokens`, `cacheReadInputTokens`.
- *Refined D-06:* Total Cost = `((inputTokens - cacheReadInputTokens) * rate) + (cacheReadInputTokens * rate * 0.1) + (cacheCreationInputTokens * rate * 1.25) + (outputTokens * outputRate)`.

### Display Logic
- **D-07:** Update `AnthropicCostEstimator::format()` to show the breakdown if cache tokens are present: `1,234 input (+500 write, 800 read), 456 output, $0.0123 est.`

### Service Integration
- **D-08:** Update `ClaudeSelectorService`, `ClaudePlannerService`, and `ClaudeExecutorService` to pass the cache token counts from the response usage to `AnthropicCostEstimator::forModel()`.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 5 success criteria: `ModelUsage` tracks separate fields, cost estimator reflects lower cost on cached runs.

### Existing Code (direct edit targets)
- `app/Data/ModelUsage.php`
- `app/Support/AnthropicCostEstimator.php`
- `app/Services/ClaudeExecutorService.php`
- `app/Services/ClaudePlannerService.php`
- `app/Services/ClaudeSelectorService.php`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AnthropicCostEstimator::ratesForModel()` already provides base rates.
- `ModelUsage::add()` pattern for combining costs across rounds/services.

### Established Patterns
- Services extract usage via a private `usageFromResponse()` method (except Executor which does it inline).

</code_context>

<specifics>
## Specific Ideas

- Update `ModelUsage` constructor:
  ```php
  public function __construct(
      public readonly string $model,
      public readonly int $inputTokens,
      public readonly int $outputTokens,
      public readonly float $estimatedCostUsd,
      public readonly int $cacheWriteTokens = 0,
      public readonly int $cacheReadTokens = 0,
  ) {}
  ```
- Update `AnthropicCostEstimator::forModel` signature:
  ```php
  public static function forModel(string $model, int $inputTokens, int $outputTokens, int $cacheWrite = 0, int $cacheRead = 0): ModelUsage
  ```

</specifics>

<deferred>
## Deferred Ideas

None.
</deferred>

---

*Phase: 05-cache-aware-cost-model*
*Context gathered: 2026-04-03*

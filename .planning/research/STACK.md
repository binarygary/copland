# Technology Stack — Research

**Project:** Copland (autonomous GitHub issue resolver)
**Milestone scope:** Improve existing PHP CLI — prompt caching, retry/backoff, file read limits, cost tracking
**Researched:** 2026-04-02
**Overall confidence:** HIGH (Anthropic API shape from training + SDK source patterns; retry from established PHP ecosystem)

---

## 1. Anthropic Prompt Caching

**Confidence:** HIGH — cache_control API is stable, documented, and well-understood as of training cutoff.

### How Caching Works

The Anthropic Messages API supports a `cache_control` block on specific message parts. When present, the API attempts to cache the prefix of the conversation up to and including that block. Subsequent requests that share the same prefix use the cache instead of reprocessing those tokens.

**Cache TTL:** 5 minutes (ephemeral). The cache is extended another 5 minutes on each hit. Under normal operation the executor's 12-round loop runs in 2-4 minutes, so the cache will not expire mid-run.

**Cost model:**
- Cache write: 25% more expensive than base input rate (one-time cost on first request)
- Cache read: 90% cheaper than base input rate (paid on all subsequent requests)
- Net effect: For a 12-round executor loop, rounds 2-12 pay 10% of normal system-prompt cost
- Estimated savings on executor system prompt (~800 tokens): ~$0.000072 saved per 10 rounds at Sonnet rates; meaningful across hundreds of overnight runs

**Minimum token threshold:** The system prompt must be at least 1,024 tokens for caching to activate. Verify the executor.md prompt meets this threshold; if it does not, padding with additional context or combining with tool definitions will push it over.

### API Shape

The `system` parameter changes from a plain string to an array of content blocks:

```php
// Before (current code — system is a plain string)
$response = $this->client->messages->create(
    model: $this->model,
    maxTokens: 4096,
    system: $systemPrompt,            // string
    tools: $tools,
    messages: $messages,
);

// After (with prompt caching — system is an array)
$response = $this->client->messages->create(
    model: $this->model,
    maxTokens: 4096,
    system: [
        [
            'type' => 'text',
            'text' => $systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ],
    ],
    tools: $tools,
    messages: $messages,
);
```

**Where to apply cache_control in Copland:**

| Location | Where in Code | Value |
|----------|--------------|-------|
| Executor system prompt | `ClaudeExecutorService.php` line 93 | `system` array with `cache_control` on the text block |
| Executor tool definitions | Same call, `tools` parameter | `cache_control` on the last tool definition |
| Planner system prompt | `ClaudePlannerService.php` line 52 | Less impact (single call), but same pattern applies |

Caching the tools array alongside the system prompt is the highest-value change because both are sent on every round and the tools array is ~600 tokens. The `cache_control` marker should be placed on the **last element** of the block sequence you want cached — this tells the API to cache everything up to and including that point.

To cache both system prompt and tools in one cache entry:

```php
// Cache system prompt
$system = [
    [
        'type' => 'text',
        'text' => $systemPrompt,
        'cache_control' => ['type' => 'ephemeral'],
    ],
];

// Cache tools (place cache_control on the last tool)
$tools = $this->buildTools();
$tools[count($tools) - 1]['cache_control'] = ['type' => 'ephemeral'];
```

Note: Tool-level `cache_control` support was added to the API — verify the installed `anthropic-ai/sdk ^0.8.0` passes through arbitrary keys on tool definitions. If the SDK strips unknown keys, the tool-level caching silently fails (no error, just no cache). System prompt caching works unconditionally since `system` is a top-level array the SDK passes through.

### Usage Response — Tracking Cache Hits

When caching is active, the usage object gains additional fields:

```php
$response->usage->inputTokens          // tokens not from cache
$response->usage->cacheCreationInputTokens  // tokens written to cache (round 1 only)
$response->usage->cacheReadInputTokens      // tokens read from cache (rounds 2-12)
```

**Required change to `AnthropicCostEstimator`:** The current estimator counts all `inputTokens` at full rate. With caching, cache-read tokens should be costed at 10% of the input rate, and cache-write tokens at 125%. To report accurate costs:

```php
// Accurate cost model with caching
$inputCost   = ($inputTokens / 1_000_000) * $inputRate;               // uncached tokens
$writeCost   = ($cacheWriteTokens / 1_000_000) * ($inputRate * 1.25); // cache write premium
$readCost    = ($cacheReadTokens / 1_000_000) * ($inputRate * 0.10);  // cache read discount
```

If the SDK's response object does not expose these fields yet (depends on SDK version vs. API version), fall back to treating `inputTokens` as the total — costs will be slightly wrong but no breakage.

---

## 2. Retry / Backoff for Anthropic API Calls

**Confidence:** HIGH — HTTP error semantics are stable; PHP retry patterns are well-established.

### Retryable vs. Non-Retryable Errors

| HTTP Status | Meaning | Retry? |
|-------------|---------|--------|
| 429 | Rate limited | YES — respect `retry-after` header if present |
| 500 | Internal server error | YES — transient server fault |
| 502 | Bad gateway | YES — transient network/proxy |
| 503 | Service unavailable | YES — transient overload |
| 529 | Overloaded (Anthropic-specific) | YES — same as 503 |
| 408 | Request timeout | YES — transient |
| 400 | Bad request (malformed payload) | NO — permanent client error |
| 401 | Unauthorized (bad API key) | NO — permanent |
| 403 | Forbidden | NO — permanent |
| 404 | Not found | NO — permanent |
| 422 | Unprocessable entity | NO — permanent (malformed request structure) |

Network-level errors (DNS failure, connection reset, socket timeout) should also be retried. These surface as `\GuzzleHttp\Exception\ConnectException` or similar.

### Recommended Strategy

**Exponential backoff with jitter, 3 attempts total:**

```
Attempt 1:  immediate
Attempt 2:  sleep(1 + random_float(0, 0.5)) seconds
Attempt 3:  sleep(2 + random_float(0, 1.0)) seconds
Give up:    propagate exception
```

Jitter (random fraction of base delay) prevents thundering herd if multiple overnight runs hit the same rate limit window simultaneously.

**For 429 with `retry-after` header:** Use the header value instead of the backoff formula. The Anthropic API often returns `retry-after: N` seconds. Guzzle surfaces response headers via `$e->getResponse()->getHeader('retry-after')`.

### Where to Apply in Copland

Three call sites need retry wrapping:

| Service | Line | Priority | Why |
|---------|------|----------|-----|
| `ClaudeExecutorService.php` | ~90 | **Critical** | Multi-round loop; a failure in round 8 wastes all prior work |
| `ClaudePlannerService.php` | ~52 | High | Single call; planner failure wastes selector cost |
| `ClaudeSelectorService.php` | ~42 | Medium | First call; cheapest to lose, but retry is still free value |

### Implementation Pattern

Extract a private helper method to be shared across all three service classes (or a trait / base class):

```php
private function createWithRetry(array $params, int $maxAttempts = 3): mixed
{
    $attempt = 0;
    $lastException = null;

    while ($attempt < $maxAttempts) {
        try {
            return $this->client->messages->create(...$params);
        } catch (\Anthropic\Exceptions\ApiException $e) {
            $status = $e->getCode(); // HTTP status code

            // Non-retryable: throw immediately
            if (in_array($status, [400, 401, 403, 404, 422], true)) {
                throw $e;
            }

            $lastException = $e;
            $attempt++;

            if ($attempt >= $maxAttempts) {
                break;
            }

            // Respect retry-after header on 429
            $retryAfter = null;
            if ($status === 429 && method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $headers = $e->getResponse()->getHeaders();
                $retryAfter = (int) ($headers['retry-after'][0] ?? $headers['Retry-After'][0] ?? 0);
            }

            $delay = $retryAfter > 0
                ? $retryAfter
                : (($attempt === 1 ? 1 : 2) + lcg_value() * $attempt);

            sleep((int) ceil($delay));

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Network error — always retryable
            $lastException = $e;
            $attempt++;

            if ($attempt >= $maxAttempts) {
                break;
            }

            sleep((int) ceil($attempt + lcg_value()));
        }
    }

    throw $lastException;
}
```

**SDK exception class note:** The `anthropic-ai/sdk ^0.8.0` may use a different exception hierarchy. Check the installed SDK's `src/Exceptions/` directory for the correct base class name. Likely candidates: `ApiException`, `AnthropicException`, or a Guzzle `ClientException` wrapper. If the SDK wraps Guzzle exceptions directly, catch `\GuzzleHttp\Exception\RequestException` and inspect `$e->getResponse()->getStatusCode()`.

**Avoid:** Third-party retry libraries (`spatie/guzzle-rate-limiter-middleware`, `caseyamcl/guzzle_retry_middleware`) — they add middleware at the Guzzle transport layer, which may conflict with the Anthropic SDK's own Guzzle client configuration. A simple inline retry loop is more transparent and easier to test.

---

## 3. File Read Size Management in Agentic Loops

**Confidence:** HIGH — the pattern is clear from the codebase analysis and general agentic loop literature.

### The Problem (Specific to Copland)

The `readFile()` method (line 285 of `ClaudeExecutorService.php`) returns the full file contents with no size limit:

```php
return file_get_contents($fullPath);
```

Every subsequent API call re-sends the full conversation history. A 500-line file read in round 2 of a 12-round loop is transmitted 11 more times. At ~75 tokens/KB and 20KB for a large PHP file, that is ~1,500 tokens × 11 rounds = 16,500 extra input tokens from a single read.

### Recommended Pattern: Line Cap with Truncation Notice

```php
private function readFile(string $workspacePath, string $path, ExecutorPolicy $policy): string
{
    $normalizedPath = $policy->assertToolPathAllowed($path, 'read_file');
    $fullPath = $workspacePath . '/' . ltrim($normalizedPath, '/');

    if (! file_exists($fullPath)) {
        return "Error: file not found: {$normalizedPath}";
    }

    $lines = file($fullPath, FILE_IGNORE_NEW_LINES);
    $total = count($lines);
    $cap = 300; // configurable via ExecutorPolicy

    if ($total <= $cap) {
        return implode("\n", $lines);
    }

    $truncated = implode("\n", array_slice($lines, 0, $cap));
    return $truncated . "\n\n[FILE TRUNCATED — showing {$cap} of {$total} lines. Use read_file with offset/limit, or read_file_range if available.]";
}
```

**Cap value recommendation:** 300 lines as default. Rationale:
- Covers the vast majority of PHP service classes, which are typically 100-400 lines
- A 300-line file is ~9KB, ~2,250 tokens — manageable in history
- Files over 300 lines are usually large enough that Claude only needs the relevant section

**Make the cap configurable** via `ExecutorPolicy` so the `.copland.yml` `max_executor_rounds` field's sibling can be `max_file_read_lines`. This matches the existing policy-driven design.

### Optional: Offset/Range Support

For the case where Claude legitimately needs to read beyond the cap, add an optional `offset` parameter to the `read_file` tool:

```php
// Tool definition addition
'properties' => [
    'path'   => ['type' => 'string'],
    'offset' => ['type' => 'integer', 'description' => 'Line number to start reading from (1-indexed)', 'default' => 1],
],
```

This allows Claude to read large files in windows without relaxing the cap. Priority: lower than the basic cap — the cap alone solves 90% of the cost problem.

### What NOT to Do

**Do not truncate by byte size.** PHP's `substr()` on file content can split multi-byte UTF-8 characters and create malformed strings that confuse the model. Line-based truncation is always safe.

**Do not silently truncate.** The truncation notice is required so Claude knows there is more content and can request it. Silent truncation causes Claude to make incorrect assumptions about file completeness.

**Do not apply the cap to `write_file` or `replace_in_file` content.** These operations must operate on the full content the model provides. The cost concern is on read returns entering the conversation history.

### Command Output Truncation (Related)

The same unbounded-return problem applies to `runCommand()`. Long test output or compiler errors can balloon context. A similar cap (e.g., last 200 lines of output, with a head notice) is appropriate but is lower priority than file reads.

---

## 4. Cost Estimation Improvements

**Confidence:** HIGH — derived directly from current `AnthropicCostEstimator.php` analysis.

### Current State

`AnthropicCostEstimator::forModel()` takes raw `inputTokens` and `outputTokens` and applies a single rate per direction. This is correct for uncached calls but will undercount savings once caching is enabled.

### Required Changes When Caching Is Added

The `ModelUsage` data object and `forModel()` factory need to accept the three-way token split:

```php
// New signature (backward-compatible with defaults)
public static function forModel(
    string $model,
    int $inputTokens,
    int $outputTokens,
    int $cacheWriteTokens = 0,
    int $cacheReadTokens = 0,
): ModelUsage
```

Update cost formula:

```php
$inputCost  = ($inputTokens / 1_000_000) * $inputRate;
$writeCost  = ($cacheWriteTokens / 1_000_000) * ($inputRate * 1.25);
$readCost   = ($cacheReadTokens / 1_000_000) * ($inputRate * 0.10);
```

Store raw cache token counts in `ModelUsage` so the format string can surface them:

```
"12,450 input, 1,200 cached write, 8,600 cached read, 892 output, $0.0021 est."
```

This makes the "did caching help?" question answerable at a glance in run output.

### Token Tracking in the Executor Loop

The executor already accumulates `$totalInputTokens` and `$totalOutputTokens`. Add:

```php
$totalCacheWriteTokens = 0;
$totalCacheReadTokens = 0;

// Inside the loop, after $response
if (isset($response->usage)) {
    $totalInputTokens       += $response->usage->inputTokens ?? 0;
    $totalOutputTokens      += $response->usage->outputTokens ?? 0;
    $totalCacheWriteTokens  += $response->usage->cacheCreationInputTokens ?? 0;
    $totalCacheReadTokens   += $response->usage->cacheReadInputTokens ?? 0;
}
```

The `?? 0` fallback ensures this works with SDK versions that do not yet expose cache fields.

---

## 5. Existing Stack — No Changes Required

The existing stack is the right choice for all improvements. No new major dependencies are needed.

| Technology | Version | Role | Status |
|------------|---------|------|--------|
| PHP | 8.2+ | Runtime | No change |
| Laravel Zero | 12.x | CLI framework | No change |
| `anthropic-ai/sdk` | ^0.8.0 | Anthropic API client | No change; verify cache fields present |
| `guzzlehttp/guzzle` | 7.x | HTTP transport | No change; used for GitHub, not Anthropic directly |
| `symfony/process` | bundled | Command execution | No change |
| `symfony/yaml` | bundled | Config parsing | No change |

**No new Composer dependencies required for any of the three improvements.** Retry logic is a pure PHP pattern. Prompt caching is a parameter change. File truncation is `file()` + `array_slice()`.

---

## Sources

- Anthropic prompt caching documentation: https://docs.anthropic.com/en/docs/build-with-claude/prompt-caching (verify at implementation time — TTL, minimum tokens, and tool-level cache_control availability)
- Anthropic API errors reference: https://docs.anthropic.com/en/api/errors (verify HTTP 529 "overloaded" status and retry-after header behavior)
- `anthropic-ai/sdk` PHP source: https://github.com/anthropics/anthropic-sdk-php (check exception class names and whether usage response exposes cache token fields in 0.8.x)
- Cache token field names (`cacheCreationInputTokens`, `cacheReadInputTokens`): derived from Anthropic API response schema as of training cutoff August 2025. Verify against actual SDK response object before implementing cost tracking.

**Confidence summary:**

| Area | Confidence | Basis |
|------|------------|-------|
| cache_control API shape | HIGH | Stable API, consistent with documentation patterns as of training cutoff |
| Cache TTL (5 minutes) | HIGH | Documented, unlikely to change without notice |
| Cache token field names | MEDIUM | Known from training data; verify against installed SDK version |
| Tool-level cache_control | MEDIUM | Supported by API; whether SDK passes through arbitrary keys needs code inspection |
| Retry HTTP status codes | HIGH | Standard HTTP semantics; 529 is Anthropic-specific but documented |
| PHP retry implementation | HIGH | Standard pattern, no library required |
| File truncation pattern | HIGH | Direct analysis of existing code; no external dependency |

# Architecture Patterns: Resilience, Logging, Caching

**Project:** Copland — autonomous overnight GitHub issue resolver
**Dimension:** Adding resilience layer, structured logging, prompt caching, file size management
**Researched:** 2026-04-02
**Note:** WebSearch and WebFetch unavailable. Findings drawn from direct code analysis +
  training knowledge (cutoff Aug 2025). Anthropic API specifics flagged with confidence level.

---

## Current Architecture (Baseline)

Before documenting what to add, the relevant current shape:

```
RunCommand
  └── RunOrchestratorService.run()          ← 8-step pipeline, $this->log[]
        ├── ClaudeSelectorService.selectTask()   ← single API call, no retry
        ├── ClaudePlannerService.planTask()      ← single API call, no retry
        └── ClaudeExecutorService.executeWithPolicy()
              └── while(true) { $client->messages->create() }  ← agentic loop, no retry
```

**What exists that the new layer builds on:**
- `RunOrchestratorService.$this->log[]` — plain string array, flushed via `pushLog()`
- `RunProgressSnapshot` — mutable snapshot for SIGINT cost reporting
- `ModelUsage` / `AnthropicCostEstimator` — per-stage token accounting, combine() exists
- `ExecutorRunState` — per-run in-memory state (thrashing detection)
- All three Claude services construct `$this->client` directly in `__construct()`
- `$progressCallback` threading already exists from command → orchestrator → executor

---

## Question 1: Resilience and Recovery from Partial Failures

### Pattern in Similar Systems (MEDIUM confidence)

Autonomous coding agents and batch AI processors converge on two patterns:

**Pattern A — Retry at the call site (most common)**
Wrap the individual API call. On transient error (429, 502, 503, network timeout), sleep
and retry with exponential backoff. Do not retry on 400 (bad request), 401 (auth), or
policy violations — those are permanent failures.

**Pattern B — Checkpoint + resume (more complex)**
After each successful pipeline stage, serialize state. On failure, reload last checkpoint
and resume from that stage. This is what `CONCERNS.md` calls out as the ideal for the
executor loop ("round 8/12 partial failure").

For Copland's codebase today, **Pattern A is the right first move**. Pattern B would
require serializing `$messages[]` and `ExecutorRunState` after each round, which is a
meaningful refactor. Pattern A is a small wrapper that closes the highest-value gap.

### Concrete Architecture: Where to Put Retry

**The retry layer belongs in a new `AnthropicApiClient` wrapper, not in each service.**

Rationale: All three services (`ClaudeSelectorService`, `ClaudePlannerService`,
`ClaudeExecutorService`) call `$this->client->messages->create(...)` directly. Adding
retry in three places creates drift. A single wrapper enforces consistency.

```
NEW: app/Support/AnthropicApiClient.php
  - Wraps the Anthropic\Client
  - Implements withRetry(callable $call, int $maxAttempts = 3): mixed
  - Backoff: 1s, 2s, 4s (jitter optional, not required for cron use)
  - Retries on: 429, 500, 502, 503, 504, ConnectException, TimeoutException
  - Does not retry on: 400, 401, 403, PolicyViolationException
  - Logs each retry attempt via an injected callback or to error_log()
```

All three Claude services receive this wrapper instead of constructing `Anthropic\Client`
directly. The constructor change is small:

```
Before: $this->client = new Client(apiKey: $this->config->claudeApiKey())
After:  $this->client = new AnthropicApiClient($this->config->claudeApiKey())
```

The three `->create(...)` call sites become `$this->client->createWithRetry(...)`.

**Why not inject Anthropic\Client and wrap calls in each service?**
That would require modifying three services and keeping them in sync. The wrapper
encapsulates the retry contract in one place and tests in one place.

### Component Boundary

| Component | Responsibility | Communicates With |
|-----------|---------------|-------------------|
| `AnthropicApiClient` | Retry/backoff wrapper around SDK client | All three Claude services |
| `ClaudeSelectorService` | Unchanged except uses new client | `AnthropicApiClient` |
| `ClaudePlannerService` | Unchanged except uses new client | `AnthropicApiClient` |
| `ClaudeExecutorService` | Unchanged except uses new client | `AnthropicApiClient` |

### Retry Configuration

Expose via `GlobalConfig` so `.copland.yml` can override:
```yaml
api_retry_attempts: 3        # default
api_retry_base_delay_ms: 1000  # exponential base
```

---

## Question 2: Structured Logging for Unattended Batch Processes

### Pattern in Similar Systems (MEDIUM confidence)

Unattended batch processes and overnight agents converge on two logging concerns:

1. **Machine-readable log file** — JSON Lines (one JSON object per line, newline-delimited).
   Readable with `jq`, grep-able, appendable without locking.
2. **Human-readable console output** — the existing `$progressCallback` → `$this->line()` path.
   Already works for interactive use; cron captures it to syslog or stdout redirect.

The mistake to avoid is using a full PSR-3 logging framework (Monolog). For a personal
CLI tool with no web interface, Monolog is overengineering. The right answer is a thin
`RunLogger` class that writes JSON Lines to a single log file.

### Concrete Architecture

```
NEW: app/Support/RunLogger.php
  - Opened at run start: ~/.copland/logs/runs.jsonl (append mode)
  - Each call to log(string $event, array $context = []) appends one line:
      {"ts":"2026-04-02T02:31:00Z","event":"executor.tool_call","tool":"write_file","path":"src/Foo.php","round":3}
  - close() flushes file handle at run end (or in finally block)
  - Static events defined as constants or docblock (no magic strings from callers)
```

**Key log events to emit:**

| Event key | When | Context |
|-----------|------|---------|
| `run.start` | RunOrchestratorService enters run() | repo, timestamp |
| `run.end` | run() returns RunResult | status, issue_number, pr_url, total_cost_usd, duration_s |
| `selector.response` | selector returns | decision, issue_number, input_tokens, output_tokens |
| `planner.response` | planner returns | decision, branch_name, input_tokens |
| `executor.round_start` | top of executor while loop | round_number |
| `executor.tool_call` | each tool dispatch | tool_name, path/command, is_error |
| `executor.api_retry` | AnthropicApiClient retries | attempt_number, http_status |
| `executor.round_end` | after tool results appended | round_number, input_tokens, output_tokens |
| `run.failure` | any unrecoverable failure | failure_reason, stage |

### Integration Point

`RunLogger` is constructed in `RunCommand.handle()` alongside `RunProgressSnapshot`, and
passed into `RunOrchestratorService` the same way `$progressCallback` and `$snapshot` are
passed today. The orchestrator passes it further into `ClaudeExecutorService` for
round-level events.

```
RunCommand
  └── $logger = new RunLogger()   // opens ~/.copland/logs/runs.jsonl
      └── RunOrchestratorService::run(..., $logger)
            └── ClaudeExecutorService::executeWithRepoProfile(..., $logger)
```

**No new dependency injection framework needed.** The passing pattern already exists for
`$snapshot` — this mirrors it exactly.

### Log File Management

- Single append-only file: `~/.copland/logs/runs.jsonl`
- Rotation: not needed at cron-once-per-night scale. At 1KB/run × 365 runs = 365KB/year.
- Review: `tail -n 20 ~/.copland/logs/runs.jsonl | jq .` for last 20 events.
- The log is not deleted on success — it is the audit trail.

### Component Boundary

| Component | Responsibility | Communicates With |
|-----------|---------------|-------------------|
| `RunLogger` | Opens file, appends JSON lines, closes | `RunOrchestratorService`, `ClaudeExecutorService`, `AnthropicApiClient` |
| `RunOrchestratorService` | Calls `$logger->log()` at stage boundaries | `RunLogger` |
| `ClaudeExecutorService` | Calls `$logger->log()` per round and per tool | `RunLogger` |

---

## Question 3: Token/Cost Budget Management Across Multi-Stage Pipelines

### Current State

Copland already has per-stage token tracking (`ModelUsage`, `AnthropicCostEstimator`).
The gap is not measurement — it is enforcement. There is no mechanism to abort a run if
the cost is projected to exceed a budget.

### Pattern in Similar Systems (MEDIUM confidence)

Agentic pipelines that run unattended commonly implement a **soft budget with hard cutoff**:

- **Soft limit** — warn when projected cost exceeds X% of budget. Log the warning, do not abort.
- **Hard limit** — abort if cumulative cost exceeds Y. Return a failure result with reason "budget exceeded".

For Copland, the only relevant stage is the executor loop. Selector + planner are single
calls with bounded cost. The executor loop is unbounded (max 12 rounds × file reads).

### Concrete Architecture

**No new class needed.** Budget enforcement belongs in `ClaudeExecutorService` using the
`RunProgressSnapshot` that already exists.

Add two fields to `GlobalConfig` / `.copland.yml`:

```yaml
max_run_cost_usd: 0.50   # hard limit per run (default: no limit)
warn_run_cost_usd: 0.30  # soft warning threshold (default: no limit)
```

In the executor loop, after updating `$snapshot`, check:

```php
if ($config->maxRunCostUsd() !== null) {
    $currentCost = $snapshot->totalEstimatedCost();  // sum selector + planner + executor
    if ($currentCost >= $config->maxRunCostUsd()) {
        // return ExecutionResult failure with reason "budget exceeded ($X)"
    }
}
```

`RunProgressSnapshot::totalEstimatedCost()` is a new method that sums the three nullable
`ModelUsage` objects using the existing `AnthropicCostEstimator::combine()`.

**Why not track at the orchestrator level?**
The orchestrator does not have a loop — it calls executor once and waits. The only place
where cost accumulates incrementally is inside the executor's while loop. That is where
the check must live to be effective.

### Component Boundary

| Component | Change | Why |
|-----------|--------|-----|
| `GlobalConfig` | Add `maxRunCostUsd(): ?float` and `warnRunCostUsd(): ?float` | Source of budget limits |
| `RunProgressSnapshot` | Add `totalEstimatedCost(): ?float` method | Convenience aggregator |
| `ClaudeExecutorService` | Check snapshot cost after each `$response` in the while loop | Only place with incremental cost |
| `AnthropicCostEstimator` | No change needed | `combine()` already handles aggregation |

### Note on Cached Token Pricing

When prompt caching is enabled (see Question 4), cached input tokens cost significantly
less than uncached input tokens (approximately 0.1x cost for cache reads). The existing
`AnthropicCostEstimator` uses a single input rate per model. After adding caching,
it should separate `cacheWriteTokens`, `cacheReadTokens`, and `uncachedInputTokens`.

The API response includes these fields in `usage`:
- `input_tokens` — total input tokens sent
- `cache_creation_input_tokens` — tokens written to cache (billed at 1.25x)
- `cache_read_input_tokens` — tokens served from cache (billed at 0.1x)

`ModelUsage` and `AnthropicCostEstimator` will need to handle these to give accurate cost
estimates when caching is active. This is a direct dependency: add caching first, then
update the cost estimator, otherwise reported costs will be wrong.

---

## Question 4: Anthropic Prompt Caching and Multi-Turn Conversation History

### How Prompt Caching Works (HIGH confidence — well-established behavior as of Aug 2025)

Anthropic's prompt caching caches a prefix of the request up to a marked `cache_control`
boundary. The cache is keyed on the exact token sequence from the start of the request
to the last `cache_control: {type: "ephemeral"}` marker. Cache lifetime is 5 minutes
(ephemeral). Cache hits reduce input token cost to approximately 10% of uncached rate.

**Cache invalidation:** Any change to the token sequence before the cache_control marker
invalidates the cache. This is the critical constraint for multi-turn conversations.

### The Multi-Turn Problem

In a multi-turn conversation, the `messages` array grows each round:

```
Round 1: [user: contract]
Round 2: [user: contract, assistant: tool_use, user: tool_result]
Round 3: [user: contract, assistant: tool_use, user: tool_result, assistant: ..., user: ...]
...
Round 12: [12 exchanges]
```

Every round adds new messages at the end of the array. If the cache_control marker is on
the system prompt, the system prompt prefix is identical across all rounds — cache hits
every round after the first (subject to the 5-minute TTL).

If the cache_control marker is on the last message (common misuse), the cache is
invalidated every round because the last message changes. This produces no benefit.

### Where to Place cache_control in Copland

**Correct placement: on the system prompt only.**

The system prompt (`resources/prompts/executor.md`) is static — it does not change between
rounds. It contains the tool instructions and behavioral rules. This is the ideal cache
target because:

1. It is the largest static prefix (~800 tokens per CONCERNS.md)
2. It is sent on every round unchanged
3. It anchors before the variable `messages` array

The change in `ClaudeExecutorService.executeWithPolicy()` is at line 93:

```php
// Before:
system: $systemPrompt,

// After:
system: [
    [
        'type' => 'text',
        'text' => $systemPrompt,
        'cache_control' => ['type' => 'ephemeral'],
    ]
],
```

This is the "one-line change" referenced in CONCERNS.md (it's actually a small array
restructuring, but the conceptual delta is minimal).

### Cache Hit Rate in Practice

With the executor running 12 rounds:
- Round 1: cache MISS (writes cache) — billed at 1.25x for cache creation
- Rounds 2-12: cache HIT (reads cache) — billed at 0.1x
- Net: 11 out of 12 rounds pay 0.1x for the system prompt prefix

At ~800 tokens system prompt, 12 rounds, Sonnet at $3/MTok:
- Without caching: 800 × 12 × $3/MTok = $0.0000288 per run (small absolute, but 10%+ of executor input)
- With caching: 800 × 1 × $3.75/MTok + 800 × 11 × $0.30/MTok = $0.000003 + $0.00000264 = ~89% reduction on that prefix

The bigger win is from large file reads. A 300-line file read in round 2 re-sends as
conversation history in rounds 3-12. That content is already in messages[], not system[],
so it does not benefit from system prompt caching.

**Important:** Tool definitions passed in `tools:` are also sent every round. The PHP SDK
may allow caching tool definitions alongside the system prompt. If the SDK exposes a
`cache_control` on the tools array, mark the last tool with cache_control as well. This
would cache both system prompt and tool schema together as a single prefix.
**Confidence: MEDIUM** — verify against current SDK version before implementing.

### Cache Interaction With Growing History

The cache key is the token sequence from position 0 to the cache_control boundary. The
`messages[]` array after the boundary is not part of the cache key. This means:

- System prompt cached: UNAFFECTED by growing messages. Cache hits every round.
- Last user message cached: BREAKS every round because messages grows.
- Middle message cached: BREAKS whenever any earlier message changes.

Conclusion: for Copland's agentic loop, **only cache the system prompt**. Do not attempt
to cache messages inside the conversation history.

### Integration Point in the Existing Architecture

```
ClaudeExecutorService.executeWithPolicy()
  Line 50:  $systemPrompt = file_get_contents(...)    // no change
  Line 93:  system: $systemPrompt                     // CHANGE to array form with cache_control
```

No other component needs to change for the caching feature itself. The cost estimator
should be updated afterward to separate cache write / cache read tokens for accurate
reporting (see Question 3 note above).

---

## Component Map: New Components and Integration

### New Components

```
app/Support/AnthropicApiClient.php     ← retry/backoff wrapper
app/Support/RunLogger.php              ← structured JSON Lines logger
```

### Modified Components

```
app/Config/GlobalConfig.php            ← add maxRunCostUsd, warnRunCostUsd, retry config
app/Support/RunProgressSnapshot.php   ← add totalEstimatedCost() method
app/Data/ModelUsage.php                ← add cacheWriteTokens, cacheReadTokens fields
app/Support/AnthropicCostEstimator.php ← update cost calculation for cache tokens
app/Services/ClaudeSelectorService.php ← use AnthropicApiClient instead of Client
app/Services/ClaudePlannerService.php  ← use AnthropicApiClient instead of Client
app/Services/ClaudeExecutorService.php ← use AnthropicApiClient, add cache_control,
                                         add budget check, add RunLogger calls
app/Commands/RunCommand.php            ← construct RunLogger, pass to orchestrator
app/Services/RunOrchestratorService.php ← accept RunLogger, call at stage boundaries
```

### Full Dependency Graph After Changes

```
RunCommand
  ├── new RunLogger()                          // new
  ├── new RunProgressSnapshot()
  └── RunOrchestratorService::run(..., $logger, $snapshot)
        ├── ClaudeSelectorService
        │     └── AnthropicApiClient           // new (replaces Anthropic\Client)
        ├── ClaudePlannerService
        │     └── AnthropicApiClient           // new
        └── ClaudeExecutorService
              ├── AnthropicApiClient           // new
              ├── cache_control on system[]    // new
              └── budget check vs $snapshot   // new
```

---

## Recommended Build Order

The four improvements have the following dependency constraints:

1. `AnthropicApiClient` (retry wrapper) — **no dependencies**, highest value per effort.
   Build first. Unblocks reliable overnight runs immediately.

2. `RunLogger` (structured logging) — **no dependencies on other new components**.
   Build second. Independent of caching and budget enforcement. Requires small plumbing
   through the call stack but touches no business logic.

3. Prompt caching (`cache_control` on system prompt) — **depends on ModelUsage update**
   to report cache tokens accurately. The caching change itself is trivial; accurate cost
   reporting requires ModelUsage and AnthropicCostEstimator to understand cache fields.
   Build third (two sub-tasks: add caching, then update cost model).

4. File size cap on `readFile()` — **independent of all above**, but benefits from RunLogger
   being in place (log truncation events). Build fourth or alongside step 3.

5. Budget enforcement — **depends on RunProgressSnapshot::totalEstimatedCost()** which
   depends on accurate cost modeling from step 3. Build last.

**Build sequence:**
```
Phase A: AnthropicApiClient + retry (closes overnight failure risk)
Phase B: RunLogger + log plumbing (closes observability gap)
Phase C: cache_control + ModelUsage cache fields + AnthropicCostEstimator update (closes cost gap)
Phase D: readFile() size cap (closes context bloat gap)
Phase E: Budget enforcement in executor loop (closes runaway cost gap)
```

Phases A and B are independent and could be done in parallel.
Phase E must follow Phase C.

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Retry Inside the Executor Loop Without Backoff
**What:** `sleep(1); $response = $this->client->messages->create(...);` inline in the while loop
**Why bad:** Couples retry logic to loop iteration count; makes thrashing detection timing-sensitive; untestable
**Instead:** Isolated `AnthropicApiClient::createWithRetry()` that is independently testable

### Anti-Pattern 2: Caching the Last Message in the Conversation
**What:** Placing `cache_control` on `$messages[count($messages)-1]` to cache recent context
**Why bad:** Cache is invalidated every round because the last message changes. Produces cache misses on every call, paying cache creation cost with zero cache read benefit.
**Instead:** Cache only the static system prompt prefix

### Anti-Pattern 3: Using Monolog or a PSR-3 Logger
**What:** Adding a full logging framework for a personal CLI tool
**Why bad:** Adds a dependency, requires configuration, produces log files in non-obvious locations, and adds conceptual overhead inconsistent with the "no infrastructure" philosophy
**Instead:** `RunLogger` writing JSON Lines to a single file in `~/.copland/logs/`

### Anti-Pattern 4: Budget Enforcement in RunOrchestratorService
**What:** Checking budget at the orchestrator level between stages
**Why bad:** The orchestrator doesn't have a loop — by the time budget is checked after executor returns, the money is already spent
**Instead:** Budget check inside the executor's while loop, after each API response

### Anti-Pattern 5: Separating Retry Config From GlobalConfig
**What:** Hardcoding retry attempts and backoff in `AnthropicApiClient`
**Why bad:** Cannot tune behavior without code changes; makes overnight config adjustments impossible
**Instead:** Read retry config from GlobalConfig with sensible defaults

---

## Scalability Considerations

| Concern | Now (personal cron) | At 10 repos/night | Notes |
|---------|---------------------|-------------------|-------|
| Log file size | ~1KB/run, trivial | ~3.6MB/year | Single append file; no rotation needed |
| Cache TTL | 5-min ephemeral; 12 rounds in ~60s | Same | Cache stays hot through executor loop |
| Retry delays | Max 7s total (1+2+4) | Same | Does not affect cron schedule at this scale |
| Budget tracking | Per-run, in-memory | Per-run, in-memory | No cross-run budget needed at this scale |

---

## Confidence Assessment

| Topic | Confidence | Basis |
|-------|------------|-------|
| Anthropic cache_control placement (system prompt only) | HIGH | Well-documented in Anthropic docs as of Aug 2025; directly observable from SDK usage |
| Cache TTL is 5 minutes | HIGH | Stable Anthropic documentation |
| cache_creation / cache_read tokens in response.usage | HIGH | Confirmed in SDK response shape |
| Tool definitions cacheable alongside system prompt | MEDIUM | Documented feature but SDK support varies; verify before implementing |
| Retry HTTP status codes (429, 5xx) | HIGH | Standard HTTP semantics; confirmed in Anthropic rate limit docs |
| JSON Lines as structured log format | HIGH | Industry standard for machine-readable append logs |
| Monolog overkill for CLI tool | MEDIUM | Judgment call; reasonable engineers differ |
| Budget enforcement placement in executor loop | HIGH | Direct consequence of where cost accumulates in the code |

---

## Open Questions

1. **Does the current `anthropic-sdk-php` version support passing `cache_control` inside
   the `tools:` array?** The caching docs describe it for system and messages; tool
   definition caching may require a newer SDK. Verify against `composer.json` before
   implementing.

2. **Does cron on macOS inherit `HOME` from the launch environment?** `CONCERNS.md`
   documents that `$_SERVER['HOME']` may not be set in cron/systemd. `RunLogger` will
   write to `~/.copland/logs/` and will fail the same way if HOME is not resolved.
   The HOME resolution bug (CONCERNS.md Bug #2) should be fixed before or alongside
   RunLogger to avoid silent log loss.

3. **Should `AnthropicApiClient` surface retry events to the `RunLogger`?** If the logger
   is constructed before the API client, yes — pass the logger into the client so retries
   are captured in the structured log. If the logger is not yet in place when the client
   is built (e.g., Phase A before Phase B), fall back to `error_log()`.

4. **Is the 5-minute cache TTL sufficient for very slow executor runs?** At 12 rounds
   with complex tool calls and slow network, a run could exceed 5 minutes. In that case,
   cache TTL expiry causes a cache miss on later rounds, which would show up as unexpected
   `cache_creation_input_tokens` charges in later rounds. Monitor during first runs with
   caching enabled.

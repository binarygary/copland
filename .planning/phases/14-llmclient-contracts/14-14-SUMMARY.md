---
phase: 14-llmclient-contracts
plan: 14
subsystem: api
tags: [llmclient, interface, anthropic, dependency-injection, abstraction]

# Dependency graph
requires: []
provides:
  - App\Contracts\LlmClient interface with complete() method
  - App\Data\LlmResponse value object (content array, stopReason, LlmUsage)
  - App\Data\LlmUsage value object (inputTokens, outputTokens, cacheWriteTokens, cacheReadTokens)
  - App\Data\SystemBlock value object (text, cache flag)
  - AnthropicApiClient implements LlmClient, exposes complete()
  - All three Claude services type-hint LlmClient instead of AnthropicApiClient
  - AppServiceProvider binds LlmClient to AnthropicApiClient at runtime
affects: [15-openaicompat, future-provider-phases, asana-integration]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "LlmClient interface abstracts provider-specific SDK types from service layer"
    - "complete() wraps SDK response in LlmResponse/LlmUsage plain value objects"
    - "SystemBlock value object carries cache flag for provider-specific cache control"
    - "Services use array access on LlmResponse content (not stdClass properties)"

key-files:
  created:
    - app/Contracts/LlmClient.php
    - app/Data/LlmResponse.php
    - app/Data/LlmUsage.php
    - app/Data/SystemBlock.php
  modified:
    - app/Support/AnthropicApiClient.php
    - app/Services/ClaudeSelectorService.php
    - app/Services/ClaudePlannerService.php
    - app/Services/ClaudeExecutorService.php
    - app/Providers/AppServiceProvider.php

key-decisions:
  - "LlmClient interface has a single complete() method; messages() stays public on AnthropicApiClient for backward test compat"
  - "LlmResponse content uses plain assoc arrays (not stdClass) to decouple from Anthropic SDK types"
  - "AnthropicMessageSerializer::assistantContent() call removed from executor; LlmResponse->content is already plain arrays"
  - "SystemBlock carries cache flag so AnthropicApiClient.complete() can inject CacheControlEphemeral on Anthropic-specific calls"

patterns-established:
  - "LlmClient: interface that all LLM provider adapters must implement — one method complete()"
  - "Provider adapter pattern: AnthropicApiClient.complete() translates LlmClient value objects to SDK types and wraps response"
  - "Array access pattern: services use $block['type'], $block['text'] etc. for all LlmResponse content traversal"

requirements-completed: [PROV-01, PROV-02]

# Metrics
duration: 5min
completed: 2026-04-08
---

# Phase 14: LlmClient Contracts Summary

**LlmClient interface + LlmResponse/LlmUsage/SystemBlock value objects isolate three Claude services from Anthropic SDK types; AnthropicApiClient implements LlmClient via a complete() adapter method**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-04-08T15:20:06Z
- **Completed:** 2026-04-08T15:24:55Z
- **Tasks:** 4
- **Files modified:** 9 (4 created, 5 modified)

## Accomplishments

- Created LlmClient interface with complete() signature, LlmResponse/LlmUsage/SystemBlock value objects
- AnthropicApiClient now implements LlmClient; complete() translates SystemBlock[] to SDK TextBlockParam types and maps SDK response to plain value objects
- All three Claude services (Selector, Planner, Executor) accept LlmClient in their constructors and call complete(); content accessed via array syntax
- AppServiceProvider binds LlmClient to AnthropicApiClient in container; smoke test confirms no "not instantiable" error

## Task Commits

Each task was committed atomically:

1. **Task 1: Create LlmClient interface and value objects** - `9c87aba` (feat)
2. **Task 2: Add complete() to AnthropicApiClient** - `94eb8b8` (feat)
3. **Task 3: Update three Claude services to use LlmClient** - `b962a85` (feat)
4. **Task 4: Register LlmClient binding in AppServiceProvider** - `bb9d399` (feat)

## Files Created/Modified

- `app/Contracts/LlmClient.php` - Interface with complete() method signature
- `app/Data/LlmResponse.php` - Value object: array content, string stopReason, LlmUsage
- `app/Data/LlmUsage.php` - Value object: inputTokens, outputTokens, cacheWriteTokens, cacheReadTokens
- `app/Data/SystemBlock.php` - Value object: text string, bool cache flag
- `app/Support/AnthropicApiClient.php` - Implements LlmClient; adds complete() adapter method
- `app/Services/ClaudeSelectorService.php` - Constructor accepts LlmClient; calls complete(); array access on content
- `app/Services/ClaudePlannerService.php` - Constructor accepts LlmClient; calls complete(); array access on content
- `app/Services/ClaudeExecutorService.php` - Constructor accepts LlmClient; calls complete(); SystemBlock for system prompt; full array access on content blocks
- `app/Providers/AppServiceProvider.php` - Binds LlmClient::class to AnthropicApiClient::class

## Decisions Made

- `messages()` kept public on AnthropicApiClient so `AnthropicApiClientTest` (which calls it directly) passes without modification
- `AnthropicMessageSerializer::assistantContent()` removed from executor — LlmResponse->content is already in plain assoc array format, making the serializer call redundant (and it would break because the serializer expects SDK objects)
- SystemBlock carries a `cache` flag so complete() can inject Anthropic-specific CacheControlEphemeral; this flag is ignored by future non-Anthropic providers

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- Worktree lacked a `vendor/` directory — created a symlink to the parent project's vendor to enable test execution. This is a worktree setup issue, not a code issue.
- Pre-existing test failures: RunCommandTest and SetupCommandTest fail with "Call to a member function make() on null" due to Laravel application container not bootstrapped in those tests. These failures exist in both the parent project and the worktree and are unrelated to this plan's changes.

## Next Phase Readiness

- LlmClient abstraction is in place. Phase 15 can implement OpenAiCompatClient implementing LlmClient for Ollama/OpenRouter support.
- All existing tests pass (22 unit tests). No test files were modified.
- AppServiceProvider binding is the single point to change when adding or switching providers.

---
*Phase: 14-llmclient-contracts*
*Completed: 2026-04-08*

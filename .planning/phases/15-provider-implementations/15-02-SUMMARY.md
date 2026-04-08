---
phase: 15-provider-implementations
plan: "02"
subsystem: provider-client-implementations
tags: [llm, openai-compat, factory, tdd, ollama, openrouter, app-service-provider]
dependency_graph:
  requires: [15-01, 14-llmclient-contracts]
  provides: [OpenAiCompatClient, LlmClientFactory, per-service-container-bindings]
  affects: [AppServiceProvider, RunCommand (plan 03)]
tech_stack:
  added: []
  patterns: [static-factory, bidirectional-message-translation, per-service-binding]
key_files:
  created:
    - app/Support/OpenAiCompatClient.php
    - app/Support/LlmClientFactory.php
    - tests/Unit/OpenAiCompatClientTest.php
    - tests/Unit/LlmClientFactoryTest.php
  modified:
    - app/Providers/AppServiceProvider.php
decisions:
  - OpenAiCompatClient wraps any openai-php/client instance; provider-specific config (headers, URL) is baked in by LlmClientFactory, not injected per-call
  - LlmClientFactory is a final static-only class; no instance needed
  - AppServiceProvider per-service bindings use null RepoConfig; RunCommand wires real RepoConfig in Plan 03
  - is_error=true tool_result blocks have "ERROR:" prepended so error context is preserved for OpenAI-compat providers that lack native is_error field
metrics:
  duration: "~4 minutes"
  completed: "2026-04-08"
  tasks: 2
  files: 5
---

# Phase 15 Plan 02: Provider Client Implementations Summary

**One-liner:** OpenAiCompatClient with bidirectional Anthropic↔OpenAI message translation, LlmClientFactory with D-05 resolution order (repo stage → global stage → repo default → global default → anthropic), and AppServiceProvider updated with per-service bindings replacing the single LlmClient::class binding.

## What Was Built

### Task 1: OpenAiCompatClient (TDD)

- Created `app/Support/OpenAiCompatClient.php` implementing `LlmClient` interface
- Outbound translation (Anthropic → OpenAI):
  - `SystemBlock[]` → single `role=system` message with cache flag stripped (D-11)
  - `role=assistant` with `tool_use` blocks → `role=assistant, content=null, tool_calls=[...]`
  - `role=user` with `tool_result` blocks → multiple `role=tool` messages (one per block)
  - `is_error=true` tool_result blocks get `"ERROR: "` prepended to content
  - Plain string user content passed through unchanged
- Inbound translation (OpenAI → LlmResponse):
  - `$message->content` (string) → `['type' => 'text', 'text' => $content]`
  - `$message->toolCalls` → `['type' => 'tool_use', 'id', 'name', 'input']` blocks
  - `promptTokens/completionTokens` → `LlmUsage` with `cacheWrite=0, cacheRead=0`
- Tool schema delegated to `ToolSchemaTranslator::translateAll()` before sending
- `TOOL_CAPABLE_MODELS` public const array for Ollama probe/warning in RunCommand
- TDD: 6 failing tests written first, then implementation to green

### Task 2: LlmClientFactory + AppServiceProvider update (TDD)

- Created `app/Support/LlmClientFactory.php` (final class, static methods only):
  - `forStage(string $stage, GlobalConfig $global, ?RepoConfig $repo): LlmClient`
  - D-05 resolution: repo stage → global stage → repo default → global default → anthropic fallback
  - `buildAnthropic()`: `new AnthropicApiClient(new \Anthropic\Client(...), ...)` 
  - `buildOllama()`: `OpenAI::factory()->withApiKey('ollama')->withBaseUri(...)` 
  - `buildOpenRouter()`: factory with api_key + HTTP-Referer + X-Title headers (D-19)
  - `ollamaStageConfigs()`: deduplicated list of ollama base_url+model pairs across stages
- Updated `app/Providers/AppServiceProvider.php`:
  - Removed `$this->app->bind(LlmClient::class, AnthropicApiClient::class)` and associated imports
  - Added three per-service bindings for ClaudeSelectorService, ClaudePlannerService, ClaudeExecutorService per D-08
  - RepoConfig is null in container bindings; RunCommand supplies real RepoConfig at runtime (Plan 03)
- TDD: 5 failing tests written first, then factory implementation to green
- Full test suite: 74 tests pass (excluding pre-existing RunOrchestratorServiceTest conflict, pre-existing from Phase 01)
- PHPStan level 5: no errors on all 3 modified/created source files

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] composer install needed in new worktree**

- **Found during:** Task 1 RED phase — pest could not find bootstrap `vendor/autoload.php`
- **Issue:** The worktree `agent-a4c8b2e5` was created without a `vendor/` directory, unlike the Phase 01 worktree which had `composer install` run
- **Fix:** Ran `composer install --no-interaction --quiet` in the worktree root
- **Files modified:** vendor/ (not tracked in git)
- **Commit:** N/A (dependency install, no code change)

**2. [Rule 3 - Blocking] Worktree missing Phase 14 and 15-01 code**

- **Found during:** Initial file exploration — `app/Contracts/LlmClient.php` did not exist
- **Issue:** Worktree branch `worktree-agent-a4c8b2e5` was behind `main` and lacked all Phase 14 and Phase 15-01 commits
- **Fix:** Ran `git rebase main` successfully before starting any task execution
- **Files modified:** None (rebase brought all prior code)
- **Commit:** N/A

## Deferred Items

- **Pre-existing test conflict:** `makePlan()` is declared in both `ClaudeExecutorServiceTest.php` and `RunOrchestratorServiceTest.php`, causing a fatal PHP error when the full test suite is loaded together. This was pre-existing from Phase 01 and is out of scope for this plan.

## Known Stubs

None. All bindings wire real clients. The `null` RepoConfig in AppServiceProvider is intentional and documented — RunCommand will supply the real RepoConfig in Plan 03.

## Self-Check

- [x] `app/Support/OpenAiCompatClient.php` — created, implements LlmClient
- [x] `app/Support/LlmClientFactory.php` — created, final class with forStage() and ollamaStageConfigs()
- [x] `app/Providers/AppServiceProvider.php` — modified, three per-service bindings, bare LlmClient::class binding removed
- [x] `tests/Unit/OpenAiCompatClientTest.php` — created, 6 tests pass
- [x] `tests/Unit/LlmClientFactoryTest.php` — created, 5 tests pass
- [x] Full test suite: 74 tests pass (excluding pre-existing RunOrchestratorServiceTest conflict)
- [x] PHPStan level 5: no errors on OpenAiCompatClient, LlmClientFactory, AppServiceProvider
- [x] `grep -n "implements LlmClient" app/Support/OpenAiCompatClient.php` → line 20 confirmed
- [x] Task 1 commit: c3f4724
- [x] Task 2 commit: 00e392d

## Self-Check: PASSED

---
phase: 15-provider-implementations
verified: 2026-04-08T12:00:00Z
status: passed
score: 21/21 must-haves verified
re_verification:
  previous_status: gaps_found
  previous_score: 7/21
  gaps_closed:
    - "OpenAiCompatClient.php exists on main with full bidirectional message translation"
    - "OpenAiCompatClient translates tool_use messages to OpenAI tool_calls format"
    - "OpenAiCompatClient translates tool_result user messages to role=tool messages"
    - "OpenAiCompatClient returns LlmResponse with Anthropic tool_use content shape"
    - "OpenAiCompatClient strips cache_control from SystemBlocks"
    - "LlmClientFactory.php exists on main with forStage() and D-05 resolution order"
    - "LlmClientFactory.forStage() returns AnthropicApiClient when no llm: config (backwards compat)"
    - "LlmClientFactory adds HTTP-Referer and X-Title in buildOpenRouter()"
    - "AppServiceProvider has three per-service bindings; bare LlmClient::class binding removed"
    - "RunCommand.php calls LlmClientFactory::forStage() per stage (selector, planner, executor)"
    - "RunCommand probes GET {base_url}/api/tags before orchestration loop"
    - "RunCommand throws RuntimeException on ConnectException (Ollama unreachable)"
    - "RunCommand emits warning once per unknown Ollama model"
    - "Probe is deduped by base_url"
    - "When llm: config absent, RunCommand behavior unchanged (AnthropicApiClient via factory)"
  gaps_remaining: []
  regressions: []
---

# Phase 15: Provider Implementations Verification Report

**Phase Goal:** Users can configure Ollama or OpenRouter as their LLM provider (globally or per stage) and Copland routes requests through the correct client at runtime
**Verified:** 2026-04-08T12:00:00Z
**Status:** passed
**Re-verification:** Yes — after cherry-picking worktree commits to main

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|---------|
| 1 | AnthropicApiClient.complete() returns stopReason 'stop' (not 'end_turn') and 'tool_calls' (not 'tool_use') | VERIFIED | Line 80 of AnthropicApiClient.php: `LlmResponseNormalizer::normalize($sdkResponse->stopReason)` |
| 2 | ClaudeExecutorService exits agentic loop when stopReason is 'stop' | VERIFIED | Line 133 of ClaudeExecutorService.php: `$response->stopReason === 'stop'` |
| 3 | ClaudeExecutorService continues loop when stopReason is 'tool_calls' | VERIFIED | Implicit — loop continues past the `=== 'stop'` guard; tool_use block dispatch follows |
| 4 | GlobalConfig.llmConfig() returns parsed llm: block or empty array when absent | VERIFIED | Line 166-168 of GlobalConfig.php: `return $this->data['llm'] ?? []` |
| 5 | RepoConfig.llmConfig() returns parsed llm: block or empty array when absent | VERIFIED | Line 108-110 of RepoConfig.php: `return $this->data['llm'] ?? []` |
| 6 | ToolSchemaTranslator converts Anthropic input_schema format to OpenAI function parameters format | VERIFIED | app/Support/ToolSchemaTranslator.php — translate() renames `input_schema` to `parameters` inside a `function` wrapper; translateAll() batch-translates |
| 7 | openai-php/client is installed as a composer dependency | VERIFIED | composer.json line 22: `"openai-php/client": "^0.19.1"` |
| 8 | OpenAiCompatClient.complete() translates Anthropic tool_use messages to OpenAI tool_calls format before sending | VERIFIED | OpenAiCompatClient.php lines 132-148: role=assistant with tool_use blocks converted to tool_calls array |
| 9 | OpenAiCompatClient.complete() translates tool_result user messages to role=tool messages before sending | VERIFIED | OpenAiCompatClient.php lines 113-129: role=user with tool_result blocks converted to role=tool messages (one per block) |
| 10 | OpenAiCompatClient.complete() returns LlmResponse with content blocks in Anthropic tool_use shape | VERIFIED | OpenAiCompatClient.php lines 170-188: mapContent() returns `['type' => 'tool_use', 'id', 'name', 'input']` blocks |
| 11 | OpenAiCompatClient strips cache_control from SystemBlocks (sends plain text system message) | VERIFIED | OpenAiCompatClient.php lines 97-99: SystemBlocks rendered as `['role' => 'system', 'content' => implode("\n\n", texts)]` — cache flag ignored |
| 12 | OpenAiCompatClient adds HTTP-Referer and X-Title headers when provider is openrouter | VERIFIED | LlmClientFactory.php lines 137-143: buildOpenRouter() uses withHttpHeader('HTTP-Referer',...) and withHttpHeader('X-Title',...) baked into the underlying openai-php/client |
| 13 | LlmClientFactory.forStage() returns AnthropicApiClient when llm: config is absent (backwards-compat) | VERIFIED | LlmClientFactory.php lines 29-33: resolveConfig() falls through to `['provider' => 'anthropic']`; match default branch calls buildAnthropic() |
| 14 | LlmClientFactory.forStage() follows resolution order: repo stage → global stage → repo default → global default → anthropic fallback | VERIFIED | LlmClientFactory.php lines 76-103: resolveConfig() implements all 5 priority levels explicitly |
| 15 | AppServiceProvider registers per-service LlmClient bindings using LlmClientFactory | VERIFIED | AppServiceProvider.php lines 31-47: three separate bind() calls for ClaudeSelectorService, ClaudePlannerService, ClaudeExecutorService; bare LlmClient::class binding absent |
| 16 | RunCommand calls LlmClientFactory::forStage() for each of the three Claude services using the resolved RepoConfig | VERIFIED | RunCommand.php lines 229-231: three forStage() calls (selector, planner, executor) after $repoConfig is constructed |
| 17 | RunCommand probes GET {base_url}/api/tags before starting the orchestration loop when any stage uses Ollama | VERIFIED | RunCommand.php lines 234-242: ollamaStageConfigs() → dedup loop → probeOllama() before $orchestrator->run() |
| 18 | RunCommand exits with a RuntimeException (caught by executeRepo) when Ollama is unreachable | VERIFIED | RunCommand.php lines 323-326: probeOllama() catches ConnectException and throws `new RuntimeException("Ollama is not reachable at {$baseUrl}. Is it running?")` |
| 19 | RunCommand prints a warning (once per model) when any Ollama stage uses a model not on TOOL_CAPABLE_MODELS | VERIFIED | RunCommand.php lines 244-257: warnedModels dedup loop with :latest normalization; $this->warn() called for unknown models |
| 20 | Probe is deduped by base_url so it runs at most once per unique Ollama endpoint | VERIFIED | RunCommand.php lines 235-242: $probedUrls array gates the probeOllama() call; ollamaStageConfigs() itself also deduplicates at factory level |
| 21 | When llm: config is absent, RunCommand behavior is unchanged (implicit Anthropic, no probe) | VERIFIED | ollamaStageConfigs() returns [] when no ollama stages configured; probe loop body never entered; factory returns AnthropicApiClient per truth 13 |

**Score:** 21/21 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Support/LlmResponseNormalizer.php` | Static normalize() mapping end_turn→stop and tool_use→tool_calls | VERIFIED | Exists, 26 lines, substantive; wired in AnthropicApiClient line 80 |
| `app/Support/ToolSchemaTranslator.php` | Static translate() and translateAll() for Anthropic→OpenAI schema | VERIFIED | Exists, 31 lines, substantive; wired in OpenAiCompatClient line 70 |
| `app/Support/OpenAiCompatClient.php` | LlmClient implementation for Ollama and OpenRouter | VERIFIED | Exists, 203 lines, substantive; implements LlmClient (line 20); wired in LlmClientFactory |
| `app/Support/LlmClientFactory.php` | forStage() factory method with D-05 resolution order | VERIFIED | Exists, 147 lines, substantive; wired in AppServiceProvider and RunCommand |
| `app/Commands/RunCommand.php` | Per-stage factory wiring, Ollama probe, capability warning | VERIFIED | Updated — factory wiring at lines 229-231, probe loop at 234-242, warning loop at 244-257, probeOllama() at 310-329 |
| `app/Config/GlobalConfig.php` | llmConfig() getter | VERIFIED | Lines 166-168: `return $this->data['llm'] ?? []` |
| `app/Config/RepoConfig.php` | llmConfig() getter | VERIFIED | Lines 108-110: `return $this->data['llm'] ?? []` |
| `app/Support/AnthropicApiClient.php` | stopReason normalized via LlmResponseNormalizer | VERIFIED | Line 80: `stopReason: LlmResponseNormalizer::normalize($sdkResponse->stopReason)` |
| `app/Providers/AppServiceProvider.php` | Three per-service bindings; bare LlmClient::class binding removed | VERIFIED | Three bind() calls present; no bare LlmClient::class binding |
| `tests/Unit/LlmResponseNormalizerTest.php` | Unit tests for normalization mapping | VERIFIED | 7 tests covering all mapping cases; all pass |
| `tests/Unit/ToolSchemaTranslatorTest.php` | Unit tests for schema translation | VERIFIED | 4 tests; all pass |
| `tests/Unit/OpenAiCompatClientTest.php` | Unit tests for bidirectional message translation | VERIFIED | 6 tests; all pass |
| `tests/Unit/LlmClientFactoryTest.php` | Unit tests for D-05 resolution order | VERIFIED | 5 tests; all pass |
| `tests/Unit/RunCommandOllamaProbeTest.php` | Unit tests for probe logic and warning emission | VERIFIED | 7 tests; all pass |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `app/Support/AnthropicApiClient.php` | `app/Support/LlmResponseNormalizer.php` | `LlmResponseNormalizer::normalize($sdkResponse->stopReason)` | WIRED | Line 80 of AnthropicApiClient.php |
| `app/Services/ClaudeExecutorService.php` | `LlmResponse::stopReason` | `=== 'stop'` check | WIRED | Line 133 of ClaudeExecutorService.php |
| `app/Support/LlmClientFactory.php` | `app/Support/OpenAiCompatClient.php` | `new OpenAiCompatClient` in buildOllama() and buildOpenRouter() | WIRED | Lines 129 and 145 of LlmClientFactory.php |
| `app/Providers/AppServiceProvider.php` | `app/Support/LlmClientFactory.php` | `LlmClientFactory::forStage()` called for each service binding | WIRED | Lines 32, 38, 44 of AppServiceProvider.php |
| `app/Commands/RunCommand.php` | `app/Support/LlmClientFactory.php` | `LlmClientFactory::forStage()` called for selector, planner, executor | WIRED | Lines 229-231 of RunCommand.php |
| `app/Commands/RunCommand.php` | Ollama /api/tags endpoint | GuzzleHttp\Client GET probe in probeOllama() | WIRED | Lines 320-326 of RunCommand.php |
| `app/Support/OpenAiCompatClient.php` | `app/Support/ToolSchemaTranslator.php` | `ToolSchemaTranslator::translateAll($tools)` | WIRED | Line 70 of OpenAiCompatClient.php |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `OpenAiCompatClient` | `$response->choices[0]` | `$this->client->chat()->create($params)` | Yes — real openai-php/client HTTP call | FLOWING |
| `LlmClientFactory` | LlmClient instance | config-driven provider match; real clients constructed | Yes — real AnthropicApiClient or OpenAiCompatClient | FLOWING |
| `RunCommand::runRepo()` | `$selectorClient`, `$plannerClient`, `$executorClient` | `LlmClientFactory::forStage()` with real GlobalConfig + RepoConfig | Yes — resolves config from real YAML files | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command/Check | Result | Status |
|----------|---------------|--------|--------|
| LlmResponseNormalizer::normalize('end_turn') === 'stop' | 7 tests in LlmResponseNormalizerTest all pass | PASS | PASS |
| ToolSchemaTranslator::translate() converts input_schema to parameters | 4 tests in ToolSchemaTranslatorTest all pass | PASS | PASS |
| AnthropicApiClient uses LlmResponseNormalizer | `grep LlmResponseNormalizer::normalize app/Support/AnthropicApiClient.php` | 1 match at line 80 | PASS |
| ClaudeExecutorService checks 'stop' not 'end_turn' | `grep "stopReason === 'stop'" app/Services/ClaudeExecutorService.php` | 1 match at line 133 | PASS |
| OpenAiCompatClient implements LlmClient | `grep "implements LlmClient" app/Support/OpenAiCompatClient.php` | 1 match at line 20 | PASS |
| LlmClientFactory::forStage() resolution order | 5 tests in LlmClientFactoryTest all pass | PASS | PASS |
| AppServiceProvider has no bare LlmClient::class binding | `grep "LlmClient::class" app/Providers/AppServiceProvider.php` | 0 matches | PASS |
| RunCommand has no shared AnthropicApiClient | `grep "new AnthropicApiClient" app/Commands/RunCommand.php` | 0 matches | PASS |
| Ollama probe dedup | 7 tests in RunCommandOllamaProbeTest all pass | PASS | PASS |
| Phase 15 test suite (36 tests) | `XDEBUG_MODE=off ./vendor/bin/pest` on all 7 phase-15 test files | 36 passed, 0 failed | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| PROV-03 | 15-01 | User can set default LLM provider in ~/.copland.yml | SATISFIED | GlobalConfig::llmConfig() returns parsed `llm:` block; LlmClientFactory::resolveConfig() reads `llm.default`; RunCommand wires factory with GlobalConfig |
| PROV-04 | 15-01 | User can override LLM provider per repo in .copland.yml | SATISFIED | RepoConfig::llmConfig() returns parsed `llm:` block; LlmClientFactory::resolveConfig() checks repo config at priorities 1 and 3 before global |
| PROV-05 | 15-02, 15-03 | User can configure different providers for selector, planner, and executor stages independently | SATISFIED | LlmClientFactory::forStage() called separately for each stage; RunCommand passes stage name to factory; resolution order allows per-stage config via `llm.stages.{stage}` |
| OLLAMA-01 | 15-02 | User can configure Ollama as a provider with base URL and model name | SATISFIED | LlmClientFactory::buildOllama() reads `base_url` and constructs OpenAiCompatClient pointed at that URL; model passed as `$model` param to complete() |
| OLLAMA-02 | 15-03 | Copland probes Ollama reachability before starting the orchestration loop and fails fast with clear error | SATISFIED | RunCommand::runRepo() calls probeOllama() for each unique Ollama base_url before $orchestrator->run(); ConnectException → RuntimeException with "Ollama is not reachable at {url}. Is it running?" |
| OLLAMA-03 | 15-03 | Copland warns at startup if configured Ollama model has poor tool-use support | SATISFIED | RunCommand::runRepo() checks TOOL_CAPABLE_MODELS with :latest normalization; $this->warn() called once per unknown model |
| OPENR-01 | 15-02 | User can configure OpenRouter as a provider with API key and model name | SATISFIED | LlmClientFactory::buildOpenRouter() reads `api_key` and constructs OpenAiCompatClient pointed at openrouter.ai/api/v1 |
| OPENR-02 | 15-02 | Copland sends attribution headers (HTTP-Referer, X-Title) on OpenRouter requests | SATISFIED | LlmClientFactory::buildOpenRouter() bakes withHttpHeader('HTTP-Referer','https://github.com/binarygary/copland') and withHttpHeader('X-Title','Copland') into the client (D-19) |

### Anti-Patterns Found

No blockers or stubs found. Previous blockers (missing files, bare LlmClient::class binding, shared AnthropicApiClient in RunCommand) are all resolved.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `tests/Unit/RunOrchestratorServiceTest.php` | 424 | `makePlan()` function redeclared — also declared in ClaudeExecutorServiceTest.php line 137 | Info (pre-existing) | Full suite cannot run in one pass; individual files pass fine. Pre-existing from Phase 01, out of scope for Phase 15. |

### Human Verification Required

None. All Phase 15 truths are verifiable programmatically. Implementation is confirmed to match the plan specifications.

The one item that cannot be verified without a live Ollama or OpenRouter instance is the actual HTTP round-trip through OpenAiCompatClient, but the unit tests mock the openai-php/client and cover all translation logic. This is expected and acceptable for a CLI tool tested at the unit level.

### Re-verification: Gap Closure Summary

The previous verification (score 7/21) identified a single root cause: Plans 02 and 03 produced working code in git worktree branches that was never merged to main. The 14 failed truths all traced to three unmerged commits.

After cherry-picking those commits to main:

- All 14 previously-failed truths now pass
- No regressions in the 7 truths that previously passed
- Test count grew from the Plan 01 baseline (11 tests) to 36 tests across 7 test files
- PHPStan level 5 passes on all modified source files (documented in SUMMARYs)

---

_Verified: 2026-04-08T12:00:00Z_
_Verifier: Claude (gsd-verifier)_

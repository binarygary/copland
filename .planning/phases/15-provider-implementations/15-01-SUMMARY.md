---
phase: 15-provider-implementations
plan: "01"
subsystem: normalization-contracts
tags: [llm, normalization, openai-compat, tdd, config]
dependency_graph:
  requires: [14-llmclient-contracts]
  provides: [stopReason-normalization, tool-schema-translation, llmConfig-getters]
  affects: [AnthropicApiClient, ClaudeExecutorService, OpenAiCompatClient (plan 02)]
tech_stack:
  added: [openai-php/client ^0.19.1]
  patterns: [static-normalizer, schema-translator, config-getter]
key_files:
  created:
    - app/Support/LlmResponseNormalizer.php
    - app/Support/ToolSchemaTranslator.php
    - tests/Unit/LlmResponseNormalizerTest.php
    - tests/Unit/ToolSchemaTranslatorTest.php
  modified:
    - app/Support/AnthropicApiClient.php
    - app/Services/ClaudeExecutorService.php
    - app/Config/GlobalConfig.php
    - app/Config/RepoConfig.php
    - tests/Unit/ClaudeExecutorServiceTest.php
    - composer.json
    - composer.lock
decisions:
  - Normalized stopReason values are: 'stop' (was 'end_turn') and 'tool_calls' (was 'tool_use')
  - LlmResponseNormalizer is a pure static utility with no dependencies
  - ToolSchemaTranslator is a pure static utility; translateAll() batch-translates via array_map
  - LlmUsage is never null so null guard on response.usage was removed from ClaudeExecutorService
metrics:
  duration: "~4 minutes"
  completed: "2026-04-08"
  tasks: 2
  files: 11
---

# Phase 15 Plan 01: Normalization Contracts Summary

**One-liner:** Canonical stopReason normalization (end_turn→stop, tool_use→tool_calls) via LlmResponseNormalizer, Anthropic-to-OpenAI tool schema translation via ToolSchemaTranslator, and llmConfig() getters on both config classes — foundational contracts for Plan 02's OpenAiCompatClient.

## What Was Built

### Task 1: openai-php/client + LlmResponseNormalizer + ToolSchemaTranslator (TDD)

- Installed `openai-php/client ^0.19.1` via composer
- Created `app/Support/LlmResponseNormalizer::normalize()` — maps Anthropic stop reason strings to canonical values that all providers share: `end_turn → stop`, `tool_use → tool_calls`, everything else passes through unchanged
- Created `app/Support/ToolSchemaTranslator::translate()` — converts Anthropic tool definitions (with `input_schema` key) to OpenAI function format (with `parameters` key inside a `function` wrapper). `translateAll()` batch-translates arrays
- TDD: wrote 11 failing tests first, then implemented both classes to make them green

### Task 2: Config getters, AnthropicApiClient normalization, executor loop update

- Added `GlobalConfig::llmConfig()` and `RepoConfig::llmConfig()` — both return `$this->data['llm'] ?? []` following existing getter pattern
- Updated `AnthropicApiClient::complete()` to pass stopReason through `LlmResponseNormalizer::normalize()` before returning the `LlmResponse` object
- Updated `ClaudeExecutorService` agentic loop: `stopReason === 'end_turn'` → `stopReason === 'stop'`
- Updated `ClaudeExecutorServiceTest`: all `fakeResponse()` calls now use canonical `'tool_calls'` and `'stop'` values

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Removed dead null check on LlmUsage in ClaudeExecutorService**

- **Found during:** PHPStan verification after Task 2
- **Issue:** `if ($response->usage !== null)` was flagged by PHPStan level 5 — `LlmUsage` is typed as non-nullable, making the guard permanently true dead code (introduced in Phase 14)
- **Fix:** Removed the `if` wrapper, unconditionally accumulate token counts from `$response->usage`
- **Files modified:** `app/Services/ClaudeExecutorService.php`
- **Commit:** `0c3e7ae`

**2. [Deviation - worktree rebase] Worktree was behind main**

- **Found during:** Initial setup — worktree branch lacked Phase 14 code (LlmClient, LlmResponse, LlmUsage, SystemBlock)
- **Fix:** Rebased `worktree-agent-a661ff52` onto `main` before starting task execution
- **No files modified** — standard worktree lifecycle

## Deferred Items

- **Pre-existing test conflict:** `makePlan()` is declared in both `ClaudeExecutorServiceTest.php` and `RunOrchestratorServiceTest.php`, causing a fatal error when the full test suite runs. This prevents `./vendor/bin/pest --stop-on-failure` from working. All other tests (63/63) pass when excluding `RunOrchestratorServiceTest.php`. This is out of scope for this plan. Logged to deferred-items.

## Self-Check

- [x] `app/Support/LlmResponseNormalizer.php` — created
- [x] `app/Support/ToolSchemaTranslator.php` — created
- [x] `tests/Unit/LlmResponseNormalizerTest.php` — created, 7 tests pass
- [x] `tests/Unit/ToolSchemaTranslatorTest.php` — created, 4 tests pass
- [x] `app/Config/GlobalConfig.php` — llmConfig() added
- [x] `app/Config/RepoConfig.php` — llmConfig() added
- [x] `app/Support/AnthropicApiClient.php` — normalize() applied in complete()
- [x] `app/Services/ClaudeExecutorService.php` — stopReason === 'stop' check
- [x] `tests/Unit/ClaudeExecutorServiceTest.php` — canonical values in fakeResponse() calls
- [x] `composer.json` / `composer.lock` — openai-php/client added
- [x] PHPStan level 5: no errors on all 4 modified source files
- [x] 63 tests pass (excluding pre-existing RunOrchestratorServiceTest conflict)
- [x] Task 1 commit: eec81a1
- [x] Task 2 commit: c0e9f58
- [x] PHPStan fix commit: 0c3e7ae
- [x] Merge commit on main: confirmed

## Self-Check: PASSED

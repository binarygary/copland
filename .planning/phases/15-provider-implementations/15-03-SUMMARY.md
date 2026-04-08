---
phase: 15-provider-implementations
plan: "03"
subsystem: run-command-integration
tags: [llm, factory-wiring, ollama-probe, capability-warning, tdd]
dependency_graph:
  requires: [15-01, 15-02, 14-llmclient-contracts]
  provides: [per-stage-factory-wiring, ollama-probe, ollama-model-warning]
  affects: [RunCommand]
tech_stack:
  added: []
  patterns: [injectable-probe-seam, factory-wiring, dedup-by-url]
key_files:
  created:
    - tests/Unit/RunCommandOllamaProbeTest.php
  modified:
    - app/Commands/RunCommand.php
decisions:
  - RunCommand.$httpProber uses untyped mixed constructor parameter (PHP disallows ?callable as promoted property type)
  - Probe dedup handled at both ollamaStageConfigs() factory level and RunCommand level via $probedUrls array
  - Warning fires after probe loop so probe failures exit before any warnings emit
metrics:
  duration: "~8 minutes"
  completed: "2026-04-08"
  tasks: 1
  files: 2
---

# Phase 15 Plan 03: RunCommand Integration Summary

**One-liner:** RunCommand now calls LlmClientFactory::forStage() per pipeline stage (selector, planner, executor), probes each unique Ollama base_url at /api/tags before orchestration starts, and emits a one-time warning for Ollama models not on TOOL_CAPABLE_MODELS — verified by 7 TDD tests.

## What Was Built

### Task 1: Wire LlmClientFactory into RunCommand and add Ollama probe + warning (TDD)

**TDD RED phase:**
- Created `tests/Unit/RunCommandOllamaProbeTest.php` with 7 failing tests
- Tests cover: no-probe when no Ollama stages, successful probe proceeds, ConnectException becomes RuntimeException, known model emits no warning, unknown model emits one warning, same base_url probed only once, constructor accepts $httpProber parameter
- 2 tests failed (Tests 1 and 7) because RunCommand lacked $httpProber parameter — correct RED state

**TDD GREEN phase — app/Commands/RunCommand.php changes:**
- Removed: single shared `$apiClient = new AnthropicApiClient(...)` block
- Removed: `use Anthropic\Client;` and `use App\Support\AnthropicApiClient;` imports
- Added: `use App\Support\LlmClientFactory;` and `use App\Support\OpenAiCompatClient;`
- Added: `use GuzzleHttp\Client;` and `use GuzzleHttp\Exception\ConnectException;` (Pint moved these from inline to imports)
- Added: `private $httpProber = null` constructor parameter (untyped — PHP 8.2 does not allow `?callable` as promoted property type)
- In `runRepo()` after `$repoConfig = new RepoConfig($path)`:
  - Three per-stage factory calls: `LlmClientFactory::forStage('selector'|'planner'|'executor', $globalConfig, $repoConfig)`
  - Ollama probe loop: `ollamaStageConfigs()` → dedup by base_url → `$this->probeOllama($url)` for each unique URL
  - Model warning loop: dedup by model → `:latest` normalization → `$this->warn(...)` for unknown models
- Updated orchestrator instantiation: `ClaudeSelectorService($globalConfig, $selectorClient)`, `ClaudePlannerService($globalConfig, $plannerClient)`, `ClaudeExecutorService($globalConfig, $executorClient)`
- Added `probeOllama(string $baseUrl): void` private method:
  - Strips `/v1` suffix: `rtrim(preg_replace('#/v1$#i', '', $baseUrl), '/') . '/api/tags'`
  - If `$httpProber` injected: calls it and returns (test seam)
  - Otherwise: creates GuzzleHttp\Client, catches ConnectException → RuntimeException, catches other Throwable → RuntimeException

**All 7 tests GREEN. PHPStan level 5: no errors. Pint: clean.**

## Deviations from Plan

### Deviation — Worktree behind main and 15-02 branch

- **Found during:** Initial setup — worktree lacked Phase 15-01 and 15-02 code
- **Issue:** Worktree branch `worktree-agent-a997d853` was behind `main` and the 15-02 worktree branch. `LlmClientFactory.php` and `OpenAiCompatClient.php` did not exist
- **Fix:** Ran `git rebase main` then `git rebase worktree-agent-a4c8b2e5` to bring all prior phase code
- **Files modified:** None (rebase only)
- **Commit:** N/A

### Deviation — PHP callable type constraint

- **Found during:** First test run (RED phase) after adding `private ?callable $httpProber = null` to constructor
- **Issue:** PHP fatal: "Property App\Commands\RunCommand::$httpProber cannot have type ?callable" — PHP 8.2 forbids `callable` as promoted property type
- **Fix (Rule 1 - Bug):** Changed to untyped `private $httpProber = null` following the existing `$repoRunner` parameter pattern in the same constructor. Functionally identical, type is inferred at runtime
- **Files modified:** `app/Commands/RunCommand.php`
- **Commit:** e5026f0 (folded into task commit)

## Deferred Items

- **Pre-existing test conflict:** `makePlan()` is declared in both `ClaudeExecutorServiceTest.php` and `RunOrchestratorServiceTest.php`, causing a fatal PHP error when both test files load together. This is out of scope and pre-existing from Phase 01.

## Known Stubs

None. All three stages are wired to real LlmClient instances resolved by LlmClientFactory. The factory falls back to AnthropicApiClient when no `llm:` config is present — behavior is identical to pre-Phase-15 for users without Ollama/OpenRouter config.

## Self-Check

- [x] `app/Commands/RunCommand.php` — modified: factory wiring, probe, warning, probeOllama() method
- [x] `tests/Unit/RunCommandOllamaProbeTest.php` — created: 7 tests pass
- [x] No `new AnthropicApiClient` in RunCommand: `grep -n "new AnthropicApiClient" app/Commands/RunCommand.php` — no results
- [x] PHPStan level 5: no errors on RunCommand
- [x] Full test suite (81 tests, excluding pre-existing RunOrchestratorServiceTest conflict): all pass
- [x] Task commit: e5026f0

## Self-Check: PASSED

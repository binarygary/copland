---
phase: quick-260528-d4t
plan: 01
status: complete-pending-smoke
type: execute
tasks_completed: 4
tasks_total: 5
checkpoint_remaining: "Task 5 (human-verify smoke) deferred to user"
files_created:
  - app/Support/ClaudeCodeRunner.php
  - app/Support/ClaudeCodeClient.php
  - resources/schemas/selector.json
  - resources/schemas/planner.json
  - tests/Unit/ClaudeCodeRunnerTest.php
  - tests/Unit/ClaudeCodeClientTest.php
files_modified:
  - app/Support/LlmClientFactory.php
  - app/Data/LlmUsage.php
  - app/Data/ModelUsage.php
  - app/Services/ClaudeSelectorService.php
  - app/Services/ClaudePlannerService.php
  - app/Services/ClaudeExecutorService.php
  - app/Commands/RunCommand.php
  - app/Config/GlobalConfig.php
  - tests/Unit/LlmClientFactoryTest.php
commits:
  - 7e67b67 feat: ClaudeCodeRunner Symfony Process wrapper
  - 58cf5e3 feat: LlmUsage::providerCostUsd + ModelUsage::fromProviderCost
  - 98ed3aa feat: ClaudeCodeClient + LlmClientFactory provider arm
  - 908a907 chore: startup PATH probe + config example
  - 285b18f style: Pint formatting
test_baseline_before: 163 passing (572 assertions)
test_baseline_after: 179 passing (643 assertions)
new_tests: 16
requirements_completed: [CC-01, CC-02, CC-03, CC-04]
---

# Quick 260528-d4t: Add `claude-code` Provider (Shells Out to `claude -p`)

## One-Liner

Adds a `claude-code` LlmClient provider that shells out to the local Claude Code CLI (`claude -p`) per stage, surfaces `total_cost_usd` from the envelope as `providerCostUsd`, and warns at startup when the binary is missing — all strictly additive (Anthropic / Ollama / OpenRouter paths untouched).

## What Was Built

Tasks 1–4 of the plan are complete; Task 5 (human-verify smoke against a real repo with a real `claude` binary) is deferred to the user as designed.

### Task 1 — `ClaudeCodeRunner` (`feat 7e67b67`)
- `app/Support/ClaudeCodeRunner.php` — Symfony Process wrapper using **array-form `Process([...$argv])`** (never `fromShellCommandline`, no shell parsing).
- Builds argv with `claude -p --output-format json --no-session-persistence --add-dir <cwd> --json-schema <inline-schema>`, conditionally appending `--allowedTools`, `--model`, `--max-budget-usd`. **Prompt is the last positional arg.**
- Parses the envelope and returns `['result' => mixed, 'total_cost_usd' => float, 'raw' => array]`.
- Throws `RuntimeException` with descriptive context on: non-zero exit (stderr surfaced), empty stdout, unparseable JSON, missing `result` or `total_cost_usd`.
- Constructor-injected `Closure $processFactory` for test substitution; default builds a real `Process`.
- 7 unit tests cover argv construction, envelope parsing, all error surfaces, and conditional-flag omission. No real `claude` binary invoked.

### Task 2 — `providerCostUsd` plumbing (`feat 58cf5e3`)
- `LlmUsage` gains a 5th optional readonly field `?float $providerCostUsd = null`. Default null preserves every existing instantiation.
- `ModelUsage::fromProviderCost(string $model, float $cost): self` — token-less factory carrying the dollar cost authoritatively.
- `ClaudeSelectorService::usageFromResponse()` and `ClaudePlannerService::usageFromResponse()` now prefer `providerCostUsd` when present, falling back to `AnthropicCostEstimator::forModel()` otherwise.
- `ClaudeExecutorService` accumulates `$totalProviderCostUsd` per round and routes **all 5** prior `AnthropicCostEstimator::forModel(...)` call sites (max-rounds return, success return, thrash return, snapshot, `updateSnapshot`) through a new private `buildUsage()` helper.
- `AnthropicCostEstimator` is **untouched** — the claude-code path bypasses the per-token rate table entirely so a CLI-provided cost never gets contaminated by phantom token math.
- 1 additional test in `ClaudeCodeRunnerTest` covers `ModelUsage::fromProviderCost` (lives there per the plan note).

### Task 3 — `ClaudeCodeClient` + factory + schemas (`feat 98ed3aa`)
- `app/Support/ClaudeCodeClient.php` implements `LlmClient`. Maps one Copland `complete()` to one `ClaudeCodeRunner::runStage()`:
  - prompt = `systemBlocks` (joined `\n\n`, cache flag ignored) + role=user plain-string contents (assistant + tool_result blocks **skipped** — claude-code drives its own internal loop, no round-trip);
  - `$tools` argument **ignored** (claude-code uses native tools — the whitelist comes from the factory);
  - `$maxTokens` ignored (claude-code uses `--max-budget-usd`);
  - returns terminal `stopReason='stop'` with a single text block containing `json_encode($envelope['result'])` (so existing selector/planner `extractJson()` works unchanged) and `LlmUsage` with `providerCostUsd` populated.
- `allowedTools(): array` accessor for test introspection + future debugging.
- `LlmClientFactory::forStage()` gains the `'claude-code' => buildClaudeCode($config, $stage, $repo)` arm. New `claudeCodeStageConfigs()` mirrors `ollamaStageConfigs()` for Task 4's startup probe.
- **Per-stage allowed-tools defaults:**
  - `selector` → `[]`
  - `planner` → `['Read']`
  - `executor` → `['Read', 'Edit', 'Write', 'Bash(git status)', 'Bash(git diff *)']` **plus** `Bash(<first-word> *)` for each entry in the repo's `allowed_commands` (deduped — `composer install` + `composer test` → one `Bash(composer *)`).
  - Per-stage `allowed_tools` config key **REPLACES** the default (does not merge).
- JSON schemas at `resources/schemas/selector.json` (mirrors `SelectionResult`) and `resources/schemas/planner.json` (mirrors `PlanResult`); executor uses an inline `{summary: string}` schema.
- 5 client tests + 3 new factory tests (8 new total) cover prompt assembly, systemBlocks, ignored args, per-stage defaults with repo command mapping, and the override-replaces-not-merges contract.

### Task 4 — Startup warning + config doc (`chore 908a907`, `style 285b18f`)
- `RunCommand::runRepo()` now probes `which <binary>` once per unique `binary_path` resolved by `claudeCodeStageConfigs()`, warning if missing. Sits right after the Ollama capability warning, mirroring its idiom. Avoids `claude --version` (slower, requires auth — defer to first real invocation).
- `GlobalConfig::ensureExists()` default YAML now includes a commented `# llm:` example demonstrating both the all-stages `default:` and per-stage `stages:` shapes for claude-code (including `allowed_tools` override). All real lines remain valid YAML.
- Final commit `style 285b18f` is cosmetic-only Pint formatting (anonymous-class brace placement, phpdoc alignment, ordered imports) applied to the newly-authored files.

## Verification

- `./vendor/bin/pest` → **179 passed (643 assertions)**, up from 163 baseline (+16 new tests, 0 regressions).
- `./vendor/bin/pint <touched-files>` → applied; tests still green after.
- Per-task verify commands (per plan):
  - Task 1: `./vendor/bin/pest tests/Unit/ClaudeCodeRunnerTest.php` → 7 passed.
  - Task 2: full unit suite → no regressions; new `fromProviderCost` test passes.
  - Task 3: `./vendor/bin/pest tests/Unit/ClaudeCodeClientTest.php tests/Unit/LlmClientFactoryTest.php tests/Unit/ClaudeCodeRunnerTest.php` → 21 passed.
  - Task 4: full suite → 179 passed.

## Deferred — Task 5 (Human-Verify Smoke)

Per the execution_context, Task 5 is a `checkpoint:human-verify` and is **deferred to the user**:

1. Confirm `which claude` resolves and `claude --version` succeeds.
2. Add a `llm.default: { provider: claude-code, model: sonnet, max_budget_usd: 0.50 }` stanza to `~/.copland.yml`.
3. Temporarily remove `claude` from PATH and run `php ./copland run` — confirm the "Claude Code binary 'claude' not found" startup warning fires.
4. Restore the binary and run `php ./copland run <real-repo-slug>` — confirm selector/planner/executor each emit a cost line driven by `total_cost_usd` (token counts displayed as `0 input, 0 output`), and that no Anthropic API quota is consumed for this run.

Steps 1, 5–6 of the plan checkpoint cover backup/restore of the user's `~/.copland.yml`, which Copland is forbidden from touching per the execution_context.

## Deviations from Plan

**None.** All 4 autonomous tasks were executed exactly as written, with the only structural decision being the split of Task 4 into two commits (substantive `chore` + cosmetic `style`) to keep the Pint formatting pass auditable separately from the wiring change. Both commits are still attributable to Task 4.

## Known Stubs

**None.** Every code path introduced has either a unit test or a manual-smoke step in the checkpoint. No placeholder data, no "coming soon" UI, no unwired components.

## Self-Check: PASSED

- `app/Support/ClaudeCodeRunner.php` → FOUND
- `app/Support/ClaudeCodeClient.php` → FOUND
- `resources/schemas/selector.json` → FOUND
- `resources/schemas/planner.json` → FOUND
- `tests/Unit/ClaudeCodeRunnerTest.php` → FOUND
- `tests/Unit/ClaudeCodeClientTest.php` → FOUND
- Commit `7e67b67` → FOUND
- Commit `58cf5e3` → FOUND
- Commit `98ed3aa` → FOUND
- Commit `908a907` → FOUND
- Commit `285b18f` → FOUND
- Test suite: 179 passed (no failures, no skipped)

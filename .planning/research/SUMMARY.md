# Project Research Summary

**Project:** Copland — autonomous overnight GitHub issue resolver
**Domain:** Unattended LLM agentic CLI (PHP, personal cron tool)
**Researched:** 2026-04-02
**Confidence:** HIGH

## Executive Summary

Copland already has the hard infrastructure in place: issue selection, planning, git worktree isolation, policy enforcement, and draft PR creation. This milestone is not greenfield development — it is hardening an existing system to be trustworthy for unattended overnight use. The four improvements — API retry/backoff, structured run logging, prompt caching, and file read size caps — are each well-understood, independently implementable, and have clear insertion points in the existing codebase. No new major dependencies are required for any of them.

The recommended build order is reliability first, then observability, then cost optimization. An overnight agent that crashes silently is worse than one that costs slightly more, so retry/backoff and the HOME env fix ship before the logging and caching work. The structured log and prompt caching can then follow in parallel since they share no dependencies. Budget enforcement is the final addition and depends on accurate cache-aware cost modeling being in place first.

The primary risk is not technical complexity — every change is small — but interaction effects between improvements. Specifically: the file read size cap and prompt caching address different parts of the token cost problem and must both be present before the budget enforcement check will be meaningful. The HOME resolution fix is a prerequisite for any cron-based feature (logging, multi-repo, launchd setup) to work at all, making it the highest-priority single-line fix in the codebase.

---

## Key Findings

### Recommended Stack

No new dependencies are required. All improvements are implemented with existing tools: pure PHP patterns for retry, array restructuring for prompt caching, `file()` + `array_slice()` for read truncation, and a thin file-writing class for structured logging. The existing `anthropic-ai/sdk ^0.8.0` supports the `cache_control` parameter on the `system` array; tool-level caching needs verification against the installed SDK version before relying on it.

**Core technologies:**
- PHP 8.2 / Laravel Zero: CLI framework — no change; all new code follows existing patterns
- `anthropic-ai/sdk ^0.8.0`: Anthropic API client — verify `cacheCreationInputTokens` / `cacheReadInputTokens` fields are exposed in the installed version's response object before implementing cost tracking
- `guzzlehttp/guzzle` 7.x: HTTP transport — surface `ConnectException` for network-level retry; `retry-after` header available on 429 responses via `$e->getResponse()->getHeaders()`

**Critical version note:** The `anthropic-ai/sdk` PHP SDK passes arbitrary keys on tool definitions through to the API, but this should be verified by inspecting the installed `src/` before implementing tool-level `cache_control`. System prompt caching works unconditionally since `system` is a top-level array.

### Expected Features

**Must have (table stakes):**
- Persistent run log to file — cron output is lost if not redirected; morning review requires a file
- Run start/end timestamps with outcome summary — essential for answering "did it work and why?"
- Cost-per-run line in log — data already exists in `ModelUsage` objects; just needs writing to the log
- Multi-repo sequential runner with fail-and-continue — single cron entry iterates a `repos:` list in `~/.copland.yml`; one repo failure must not stop subsequent repos
- macOS launchd plist — `cron` is deprecated on macOS; launchd handles sleep/wake and explicit `HOME`/`PATH` env vars correctly
- HOME env var fix — `$_SERVER['HOME']` is unset under launchd; must fall back to `posix_getpwuid()` before any cron feature can work

**Should have (differentiators):**
- Cache savings surfaced in cost display — shows actual vs would-have-paid once prompt caching is active
- Per-issue cost breakdown in log — enables "was this issue worth automating?" analysis over time
- Executor round count and duration in run summary — signals thrashing vs. clean runs
- Log rotation (keep last N runs) — prevents unbounded disk use from nightly files

**Defer (v2+):**
- `copland cron:install` command — the launchd plist is simple enough to copy-paste; a wizard is lower priority than getting it documented
- Log rotation — add once the log file has existed long enough to measure actual size
- Weekly/monthly cost totals — the log file is sufficient to derive totals manually at this scale

**Anti-features (explicitly out of scope):**
- Web dashboard, Slack notifications, structured JSON for machine consumption, run resumption/checkpointing, parallel multi-repo execution

### Architecture Approach

The recommended approach introduces two new support classes (`AnthropicApiClient` as a retry wrapper and `RunLogger` as a structured log writer) while modifying eight existing components. All new components follow the injection pattern already used for `RunProgressSnapshot` — constructed in `RunCommand.handle()` and passed through the call stack. No new framework, DI container, or logging library is needed. The retry wrapper centralizes backoff logic that would otherwise drift across three call sites.

**Major components:**

1. `app/Support/AnthropicApiClient` (new) — retry/backoff wrapper around the Anthropic SDK client; all three Claude services use this instead of constructing `Anthropic\Client` directly; exposes `createWithRetry()` with configurable attempts and delays read from `GlobalConfig`
2. `app/Support/RunLogger` (new) — opens `~/.copland/logs/runs.jsonl` in append mode at run start; emits one JSON line per event (`run.start`, `run.end`, `selector.response`, `executor.round_end`, etc.); closed in a `finally` block; passed through orchestrator to executor for round-level events
3. `ClaudeExecutorService` (modified) — receives `cache_control` on system prompt array (line 93), budget check after each API response using `RunProgressSnapshot::totalEstimatedCost()`, `RunLogger` calls per round and per tool, and `AnthropicApiClient` instead of direct SDK client
4. `ModelUsage` + `AnthropicCostEstimator` (modified) — add `cacheWriteTokens` and `cacheReadTokens` fields; update cost formula to bill cache writes at 1.25x and cache reads at 0.10x input rate; update format string to surface savings
5. `GlobalConfig` (modified) — add `maxRunCostUsd`, `warnRunCostUsd`, `api_retry_attempts`, `api_retry_base_delay_ms`, and `max_file_read_lines` config fields with documented defaults
6. `RunCommand` (modified) — constructs `RunLogger`; passes to orchestrator; adds multi-repo loop over `repos:` list from global config with per-repo try/catch

**Key patterns to follow:**
- Retry: exponential backoff with jitter (1s, 2s + jitter), 3 attempts, respect `retry-after` header on 429, never retry 400/401/403/422
- Caching: `cache_control` on system prompt only — placing it on messages invalidates every round
- Logging: append-mode JSON Lines to a single file; flush per event, not at run end (to capture mid-run crashes)
- File truncation: line-based cap (default 300) with explicit truncation notice appended; never byte-based; never silent

### Critical Pitfalls

1. **No retry on transient API errors destroys the entire run** — a 429 or 5xx at executor round 8 wastes all selector + planner token spend and leaves the issue untouched; fix by wrapping `messages->create()` in `AnthropicApiClient::createWithRetry()` with exponential backoff; distinguish retryable (429, 5xx, network) from non-retryable (400, 401, 403) before retrying

2. **Unbounded context growth causes O(n²) token cost** — every large file read is re-transmitted on all subsequent rounds; a 10KB file in round 2 sends 11 times across 12 rounds; cap `readFile()` at 300 lines with truncation notice; also cap `runCommand()` output at 200 lines; do NOT silently truncate — the notice is required so Claude knows to request more

3. **HOME env var unset in cron/launchd breaks config loading before reaching any API call** — `GlobalConfig` and `PlanArtifactStore` both use `$_SERVER['HOME']`; replace with `getenv('HOME') ?: posix_getpwuid(posix_geteuid())['dir']`; this is a prerequisite for all cron-based features; add a `copland doctor` diagnostic command to verify resolution

4. **Caching the wrong location invalidates the cache every round** — placing `cache_control` on the last message in `$messages[]` (a common mistake) produces a cache miss every round because the messages array grows; the marker must go on the static system prompt only; all 12 executor rounds then benefit after the first-round cache write

5. **Executor `success: true` does not mean correct output** — `end_turn` signals the model is done, not that the implementation works; the `VerificationService` checks git metadata, not code quality; require the executor to run `commands_to_run` test commands and treat non-zero exit as verification failure; verify the tool call log contains at least one `run_command` per planned test command

---

## Implications for Roadmap

Based on combined research, the architecture file's suggested build order maps cleanly to phases. Phases A and B are independent and can be executed in either order or in parallel. Phase C depends on nothing but should be followed immediately by the cost estimator update. Phase D is independent. Phase E requires Phase C to be complete for accurate cost data.

### Phase 1: Reliability Hardening

**Rationale:** The highest-value, lowest-effort change. An overnight agent that crashes silently is worthless. This closes the three failure modes that prevent the tool from being trusted at all: API errors killing runs, HOME resolution breaking startup, and fragile command allowlist causing policy-violation retry loops.
**Delivers:** Overnight runs survive transient API errors; cron/launchd environments load config correctly; executor doesn't burn rounds on policy-violation retries
**Addresses:** API retry/backoff (PROJECT.md active requirement), HOME env fix (FEATURES.md table stakes prerequisite)
**Avoids:** Pitfall 1 (no retry), Pitfall 6 (HOME env), Pitfall 9 (command allowlist exact match), Pitfall 10 (API response structure not validated)
**Key work:** Introduce `AnthropicApiClient` wrapper, fix `GlobalConfig` HOME resolution, switch command allowlist to prefix matching, add `$response->content[0]->type` check before accessing `->text`

### Phase 2: Observability — Structured Run Log

**Rationale:** Without a persistent log, overnight failures are invisible. This is the primary deliverable for "reviewable record of decisions." Depends on Phase 1 (HOME fix must land first or the log file path resolution fails identically).
**Delivers:** `~/.copland/logs/runs.jsonl` with per-event JSON Lines; `~/.copland/logs/nightly.log` human-readable summary; morning review is answerable from a single file
**Addresses:** Persistent run log, timestamps, outcome summary, cost-per-run in log (all FEATURES.md table stakes)
**Avoids:** Pitfall 5 (no run audit trail), Pitfall 7 (orphaned worktree accumulation — surfaced via log)
**Key work:** Introduce `RunLogger`, thread it through `RunCommand` → `RunOrchestratorService` → `ClaudeExecutorService`; emit structured log events at each stage boundary; flush per-event (not at run end) to capture mid-run crashes

### Phase 3: Cost Optimization — Prompt Caching + File Read Cap

**Rationale:** Two independent changes that together address the token cost problem. The file read cap closes the O(n²) context growth issue; prompt caching cuts the static system prompt cost by ~89% across rounds 2-12. Must be followed immediately by the cost estimator update so reported costs reflect actual savings.
**Delivers:** System prompt cached across all executor rounds; file reads capped at 300 lines with truncation notice; cost display shows cache write/read token breakdown; `AnthropicCostEstimator` bills cache tokens at correct rates
**Addresses:** Prompt caching (PROJECT.md active), file read size cap (PROJECT.md active), cost-per-run summary (PROJECT.md active)
**Avoids:** Pitfall 2 (unbounded context growth), Pitfall 4 (incorrect caching placement)
**Key work:** Change `system:` parameter in `ClaudeExecutorService` line 93 to array with `cache_control`; add line cap in `readFile()`; update `ModelUsage` and `AnthropicCostEstimator` for three-way token split; verify SDK exposes `cacheCreationInputTokens` / `cacheReadInputTokens` fields

### Phase 4: Multi-Repo + Cron Setup

**Rationale:** Depends on Phases 1 and 2 being complete. Multi-repo requires the log to disambiguate per-repo entries. HOME fix must be in place or launchd runs fail. Fail-and-continue loop is straightforward once single-repo runs are reliable.
**Delivers:** `repos:` list in `~/.copland.yml`; `copland run` iterates sequentially with per-repo try/catch; launchd plist documented in README (and optionally `copland cron:install`)
**Addresses:** Multi-repo sequential runner (FEATURES.md table stakes), macOS launchd plist (FEATURES.md table stakes)
**Avoids:** Pitfall 5 (silent skip_all vs. crash), multi-repo failure propagation (phase warning from PITFALLS.md)
**Key work:** Add `repos:` config to `GlobalConfig`; add multi-repo loop in `RunCommand`; write launchd plist documentation; add `copland doctor` diagnostic command

### Phase 5: Budget Enforcement + Test Coverage

**Rationale:** Budget enforcement depends on accurate cost modeling from Phase 3. Test coverage for `ClaudeExecutorService` and `RunOrchestratorService` is independent but blocked in practice until the other phases stabilize the interfaces.
**Delivers:** `max_run_cost_usd` / `warn_run_cost_usd` in `GlobalConfig`; budget check in executor loop via `RunProgressSnapshot::totalEstimatedCost()`; Pest test coverage for executor multi-round sequences
**Addresses:** Test coverage (PROJECT.md active)
**Avoids:** Pitfall 4 (runaway cost), executor test coverage gap (PITFALLS.md phase warning on mock response factories)
**Key work:** Add `totalEstimatedCost()` to `RunProgressSnapshot`; add budget check in executor while loop; build Pest mock response factory for multi-round sequences; test key failure paths (429 retry, context overflow, HOME resolution)

### Phase Ordering Rationale

- Phase 1 before all others because HOME resolution is a prerequisite for any file I/O feature (logging, launchd) and retry is the highest-value reliability change
- Phase 2 before Phase 4 because multi-repo log entries require per-repo context that is only meaningful once the structured log exists
- Phase 3 is independent of Phases 2 and 4 but must precede Phase 5 because budget enforcement requires accurate cached-token cost modeling
- Phase 5 last because its two concerns (budget enforcement and test coverage) both benefit from settled interfaces in Phases 1-4

### Research Flags

Phases with standard patterns (no additional research needed):
- **Phase 1 (Retry/HOME fix):** Retry HTTP semantics are stable; PHP `posix_getpwuid()` is documented; command prefix matching is a simple string operation
- **Phase 2 (Structured logging):** JSON Lines append pattern is well-established; no library needed
- **Phase 4 (Multi-repo/launchd):** launchd plist format is documented; sequential iteration with try/catch is straightforward

Phases needing targeted implementation verification before coding:
- **Phase 3 (Prompt caching):** Verify that the installed `anthropic-ai/sdk ^0.8.0` exposes `cacheCreationInputTokens` and `cacheReadInputTokens` on the usage response object — inspect `vendor/anthropic-ai/sdk/src/` before implementing cost tracking. Also verify whether the SDK passes through arbitrary keys on tool definitions (needed for tool-level `cache_control`).
- **Phase 5 (Test coverage):** The executor's `while(true)` loop and tool dispatch table require a mock response factory capable of producing multi-round sequences — design this factory before writing tests.

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | No new dependencies; all changes are parameter adjustments or small wrappers in a well-understood codebase |
| Features | HIGH | Grounded in direct codebase analysis; table stakes list derived from first principles and existing CONCERNS.md |
| Architecture | HIGH | Component map is based on direct code inspection; insertion points are specific (file + line referenced); the only MEDIUM item is tool-level cache_control SDK support |
| Pitfalls | HIGH | Critical pitfalls are all grounded in actual code paths (line numbers referenced); Anthropic API error codes are stable |

**Overall confidence:** HIGH

### Gaps to Address

- **SDK cache field names:** `cacheCreationInputTokens` and `cacheReadInputTokens` are derived from training knowledge of the API response schema. Verify these field names against the actual installed SDK response object (`vendor/anthropic-ai/sdk/`) before implementing `AnthropicCostEstimator` changes. Fallback: treat all input tokens as uncached if fields are absent — costs will be slightly wrong but no breakage.

- **Tool-level `cache_control` SDK support:** The API supports `cache_control` on tool definitions, but whether the PHP SDK version (`^0.8.0`) passes arbitrary keys through to the API without stripping them needs a one-line inspection of the installed SDK source. If it strips unknown keys, tool caching silently fails — treat as a MEDIUM priority feature after system prompt caching is confirmed working.

- **Cache TTL for slow executor runs:** The 5-minute ephemeral TTL is sufficient for most runs (12 rounds in ~60 seconds), but complex runs on large repos could approach or exceed 5 minutes. If cache miss charges appear in later rounds of a run (`cacheCreationInputTokens` non-zero in rounds beyond 1), the TTL is expiring. Monitor during first production runs with caching enabled; no action required until observed.

- **`posix_getpwuid()` availability:** The HOME fix uses `posix_getpwuid(posix_geteuid())`. Verify `posix` extension is enabled in the PHP environment where Copland runs (`php -m | grep posix`). If not available, fall back to `$_ENV['HOME'] ?? getenv('HOME') ?? null` with an explicit diagnostic message.

---

## Sources

### Primary (HIGH confidence — direct codebase inspection)
- `/Users/garykovar/projects/codeable/copland/app/Services/ClaudeExecutorService.php` — executor loop structure, readFile implementation, cache_control insertion point (line 93)
- `/Users/garykovar/projects/codeable/copland/app/Services/RunOrchestratorService.php` — log array structure, pipeline stages, finally block cleanup
- `/Users/garykovar/projects/codeable/copland/.planning/codebase/CONCERNS.md` — documented known bugs: HOME env, context growth, prompt caching opportunity
- `.planning/research/STACK.md`, `FEATURES.md`, `ARCHITECTURE.md`, `PITFALLS.md` — full research detail

### Secondary (HIGH confidence — stable documented behavior)
- Anthropic Messages API: `cache_control` parameter, `cache_creation_input_tokens` / `cache_read_input_tokens` in usage response, error HTTP status codes (429, 5xx, 400)
- macOS launchd: `EnvironmentVariables` plist key for HOME/PATH, `StartCalendarInterval`, `StandardOutPath`
- PHP `posix_getpwuid()`: POSIX extension for HOME directory resolution without environment variable

### Tertiary (MEDIUM confidence — verify at implementation time)
- `anthropic-ai/sdk ^0.8.0` PHP SDK: cache field names on response object, tool-definition arbitrary key pass-through behavior — inspect installed vendor source before relying on these

---
*Research completed: 2026-04-02*
*Ready for roadmap: yes*

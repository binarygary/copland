# Codebase Concerns

**Analysis Date:** 2026-04-02

## Tech Debt

### 1. Fragile Guardrail Enforcement

**Issue:** Guardrails in write_file are parsed as free-text heuristics, not structured rules
**Files:** `app/Services/ClaudeExecutorService.php` (lines 300-303)
**Impact:** False positives/negatives in write protection. A guardrail like "Do not unblock the restricted table" would match "block" and trigger, blocking unrelated writes. Conversely, malformed guardrails silently fail.
**Current pattern:**
```php
str_contains(strtolower($guardrail), 'block') && str_contains($guardrail, $normalizedPath)
```
**Fix approach:** Restructure guardrails as explicit `blocked_write_paths` array in plan JSON rather than parsing free-form text. Treat existing guardrails array as advisory only.

### 2. No Retry Logic on API Transient Failures

**Issue:** Any 429, 5xx, or network error from Anthropic API immediately fails the entire run
**Files:** `app/Services/ClaudeExecutorService.php` (line 90), `ClaudePlannerService.php` (line 52), `ClaudeSelectorService.php` (line 42)
**Impact:** Overnight unattended runs waste all accumulated tokens on selector/planner when executor hits a transient API error in round 8/12. This is a full loss.
**Current pattern:** Direct `.create()` calls with no backoff
**Fix approach:** Wrap `$this->client->messages->create()` with exponential backoff (2-3 retries, 1s/2s/4s delays). Prioritize executor loop since selector/planner are single calls and cheaper.

### 3. File Read Size Unbounded

**Issue:** `readFile()` returns entire file content with no size limit
**Files:** `app/Services/ClaudeExecutorService.php` (lines 285-292)
**Impact:** Large file reads (>1000 lines) are appended to conversation history and re-sent on every round. A 10KB file read in round 2 costs input tokens 11 times (rounds 2-12). This multiplies cost significantly on large repos.
**Current behavior:** Whole file returned, no truncation notice
**Fix approach:** Add configurable line cap (default 300 lines) with truncation notice. Example: `return implode("\n", array_slice($lines, 0, 300)) . "\n\n[truncated — file has 4520 lines total]"`

### 4. Command Allowlist Exact Match is Brittle

**Issue:** `assertCommandAllowed()` uses strict string equality after trim()
**Files:** `app/Support/ExecutorPolicy.php` (lines 41-50)
**Impact:** If planner generates `php artisan test --filter FooTest` but executor calls `php artisan test --filter FooTest ` (trailing space), or any argument variation, validation fails. Current trim() helps with whitespace but not semantic equivalence.
**Current pattern:** Exact match on trimmed string
**Fix approach:** Implement prefix matching. Validate that command *starts with* one of the repo-level allowed command prefixes (e.g., `php artisan`, `npm`, `composer`). Use plan's `commands_to_run` as an additional restriction, not the sole authority.

### 5. Unhandled Promise/Optional Fields on API Responses

**Issue:** JSON extraction assumes `response->content[0]->text` exists, returns null silently
**Files:**
  - `ClaudeSelectorService.php` (line 50)
  - `ClaudePlannerService.php` (line 60)
**Impact:** If API response format changes or text block is missing, service returns empty string, passes to `extractJson('')`, and throws generic JSON error. Root cause is obscured.
**Current pattern:** `$text = $response->content[0]->text ?? '';`
**Fix approach:** Explicitly check `response->content[0]->type === 'text'` before accessing text. Throw with context: "Expected text block in response, got {$response->content[0]->type}".

## Known Bugs

### 1. GitHubService Issue Pagination Silently Capped

**Issue:** `getIssues()` fetches only 50 issues per page with no pagination loop
**Files:** `app/Services/GitHubService.php` (lines 43-52)
**Impact:** Repos with >50 `agent-ready` issues silently ignore extras. Not a current blocker (rare to have that many), but silent data loss.
**Trigger:** Repository with >50 open issues labeled `agent-ready`
**Fix approach:** Either add comment documenting limitation, or implement link-header pagination loop to fetch all pages.

### 2. Home Directory Resolution Uses $_SERVER Super Global

**Issue:** `$_SERVER['HOME']` is shell-dependent and may not exist in all environments
**Files:**
  - `app/Support/PlanArtifactStore.php` (line 88)
  - `app/Config/GlobalConfig.php` (line 24)
**Impact:** Throws "HOME is not set" in cron/systemd environments where HOME is not inherited. Fails entire run.
**Current pattern:** `$_SERVER['HOME'] ?? null` with exception
**Fix approach:** Fall back to `getenv('HOME')` or `posix_getpwuid(posix_geteuid())['dir']` for more robust resolution.

### 3. File Mutation Helper Assumes Single Occurrence

**Issue:** `replaceOnce()` raises error if more than 1 match, forces caller to provide more specific string
**Files:** `app/Support/FileMutationHelper.php` (lines 21-22)
**Impact:** Executor can get stuck in loop trying variations of old text if the exact string appears >1 time. No fallback to replace first occurrence.
**Current behavior:** Throws `PolicyViolationException` with count
**Workaround:** Executor must learn to request more context and provide larger strings
**Fix approach:** Add optional parameter `$strategy = 'once'|'first'|'all'` to allow first-match replacement when exact deduplication fails.

## Security Considerations

### 1. Path Traversal via `..` in Normalized Paths — Mitigated

**Risk:** Executor could escape workspace with `../../../../etc/passwd`
**Files:** `app/Support/ExecutorPolicy.php` (lines 88-119)
**Current mitigation:** Path normalization correctly rejects `..` escapes, throws `PolicyViolationException("Path escapes workspace")` if segments empty after reducing. Tests confirm this works.
**Assessment:** Well-protected. No action needed.

### 2. Absolute Paths Correctly Rejected

**Risk:** Executor could read/write system files like `/etc/passwd` or `/tmp/malware`
**Files:** `app/Support/ExecutorPolicy.php` (lines 96-98)
**Current mitigation:** Throws exception on leading `/`
**Assessment:** Well-protected. No action needed.

### 3. GitHub Token Exposure Risk — Low

**Risk:** `gh auth token` call in GitHubService exposes token in process environment
**Files:** `app/Services/GitHubService.php` (lines 27-34)
**Current mitigation:** Token is read into memory, not logged, not persisted. Subprocess inherits env but doesn't leak it.
**Assessment:** Acceptable for CLI tool context. Recommend: add note that token should have minimal scopes (issues, PR read/write).

### 4. Command Injection via Shell Execution

**Risk:** Executor uses `Process::fromShellCommandline()` which invokes shell, allowing injection if command is unsanitized
**Files:** `app/Services/ClaudeExecutorService.php` (line 338)
**Current mitigation:**
  - All commands validated against plan's `commands_to_run` list
  - Plan is generated by Claude (trusted), not user-supplied
  - Policy checks command is in allowed list
**Assessment:** Safe in current architecture. Claude generates the plan; executor validates. Not a risk unless plan source changes.

## Performance Bottlenecks

### 1. Executor System Prompt Sent on Every Round

**Issue:** Executor system prompt + tool definitions (~800 tokens) transmitted on every loop iteration
**Files:** `app/Services/ClaudeExecutorService.php` (lines 50, 93-95)
**Impact:** 12-round execution pays for system prompt 12 times. At ~120 tokens/round, that's 1440 wasted input tokens per run (~10% of executor cost).
**Estimated savings:** 60-70% of executor input tokens with prompt caching.
**Fix approach:** Convert system prompt to cached block:
```php
system: [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]],
```
One-line change.

### 2. No Model Selection Based on Scope

**Issue:** Executor always uses `claude-sonnet-4-6` (~15x cost of Haiku) regardless of scope
**Files:** `app/Services/ClaudeExecutorService.php` (line 30), `app/Config/GlobalConfig.php` (line 60)
**Impact:** Small-scope issues (single file, <50 lines) overpay 15x. A daily run on 5 issues with half at small scope costs ~$0.20-0.30 unnecessarily.
**Fix approach:** Add `executor_model` field to planner's JSON output. Planner chooses based on `max_lines_changed` and file count. Or derive in `executeWithRepoProfile()`: `model = maxFilesChanged <= 1 && maxLinesChanged <= 50 ? 'claude-haiku' : 'claude-sonnet-4-6'`.

### 3. Conversation History Unbounded

**Issue:** Message history array grows with every tool call; no windowing or summarization
**Files:** `app/Services/ClaudeExecutorService.php` (lines 63, 116, 192)
**Impact:** Round 12 sends 12 full message exchanges. Large files or verbose tool output bloat history. No practical limit yet, but degrades with complexity.
**Fix approach:** Consider sliding window (keep last 6 exchanges + initial contract) or summary of older rounds if history grows >50KB.

## Fragile Areas

### 1. ExecutorRunState Thrashing Detection Logic

**Files:** `app/Support/ExecutorRunState.php` (lines 68-83)
**Why fragile:** Hardcoded thresholds (2 malformed writes, 5 rounds with no progress, 6 list_directory calls) are magic numbers with no config override.
**Safe modification:** Always run `ExecutorRunStateTest` first. Thresholds should be configurable via `ExecutorPolicy` constructor.
**Test coverage:** Good — unit tests exist for all three abort conditions.

### 2. ClaudeExecutorService Tool Dispatch

**Files:** `app/Services/ClaudeExecutorService.php` (lines 238-264)
**Why fragile:** Match statement on tool name; adding new tools requires modifying this switch and `buildTools()`. No extension point.
**Safe modification:** Abstract tool handlers into tool object with `handle()` method. Create tool registry.
**Test coverage:** None — no tests on `dispatchTool()` or the full agentic loop.

### 3. Plan Parsing and Validation

**Files:** `app/Services/ClaudePlannerService.php` (lines 88-99), `app/Services/PlanValidatorService.php`
**Why fragile:** Planner JSON shape is validated piecemeal in separate `PlanValidatorService`. If planner omits a field, it silently defaults. Missing `branch_name` is caught in validator, but others are not.
**Safe modification:** Validate all required fields immediately after JSON extraction in planner. Fail fast, not later at runtime.
**Test coverage:** PlanValidatorService has tests, but ClaudePlannerService JSON parsing does not.

## Scaling Limits

### 1. Worktree Cleanup on Failure

**Issue:** If executor fails mid-round, workspace cleanup runs in finally block but may fail silently
**Files:** `app/Services/RunOrchestratorService.php` (lines 224-232)
**Current capacity:** Single worktree per run. Typical duration 30-60s.
**Limit:** If cleanup takes >2s or throws, exception is caught and logged only. Next run creates new worktree (no reuse). Disk can accumulate failed worktrees.
**Scaling path:** Add scheduled cleanup job (e.g., daily) to prune worktrees >24h old. Monitor disk usage.

### 2. Plan Artifact Storage

**Issue:** Plans stored to `~/.copland/runs/owner__repo/last-plan.json` with archive rotation
**Files:** `app/Support/PlanArtifactStore.php`
**Current capacity:** One `last-plan.json` + one `issue-{number}.json` per issue. Linear disk growth.
**Limit:** After 1000 issues, ~1MB of artifacts (small). No immediate scaling risk.
**Scaling path:** Add retention policy (keep last N issues) if plan archive grows large.

## Test Coverage Gaps

### 1. ClaudeExecutorService Has Zero Tests

**Issue:** Highest-risk component (writes code, runs commands, opens PRs) is untested
**Files:** `app/Services/ClaudeExecutorService.php`
**Risk:** Any change to tool dispatch, error handling, or policy enforcement could break undetected.
**Priority:** **High** — add integration test with mocked Anthropic client
**Path:** Create `tests/Feature/ClaudeExecutorServiceTest.php` with:
  - Mock all 5 tools (read_file, write_file, replace_in_file, run_command, list_directory)
  - Mock response sequence: tool_use -> tool_result -> end_turn
  - Assert tool calls logged, policy enforced, result success/summary populated

### 2. RunOrchestratorService Has Zero Tests

**Issue:** Full 8-step workflow is orchestrated but never tested
**Files:** `app/Services/RunOrchestratorService.php`
**Risk:** Early-exit paths (selector skip, planner decline, validation fail, exec fail, verification fail) untested. Cleanup behavior untested.
**Priority:** **High** — add unit test with all 8 services mocked
**Path:** Create `tests/Unit/RunOrchestratorServiceTest.php` with:
  - Mock `GitHubService`, `IssuePrefilterService`, `ClaudeSelectorService`, `ClaudePlannerService`, `PlanValidatorService`, `WorkspaceService`, `GitService`, `ClaudeExecutorService`, `VerificationService`
  - Test each early-exit path returns correct status and reason
  - Test cleanup runs even when executor fails (finally block)
  - Test GitHub comment posted on success/failure
  - Test issue label removed on success

### 3. ExecutorPolicy and ExecutorRunState Have Tests, But Gaps Remain

**Issue:** Tests exist but do not cover all edge cases
**Files:**
  - `tests/Unit/ExecutorPolicyTest.php` — tests path normalization
  - `tests/Unit/ExecutorRunStateTest.php` — tests thrashing detection
**Gaps:**
  - ExecutorPolicy: no test for blocked path inheritance (`.git` always blocked)
  - ExecutorPolicy: no test for `visibleEntries()` filtering
  - ExecutorRunState: no test for directory listing budget per round (budget is per-run, not per-round)
**Priority:** Medium — add missing test cases

### 4. No Tests for JSON Parsing Resilience

**Issue:** Planner and selector JSON parsing fails with generic error if response format is unexpected
**Files:** `app/Services/ClaudePlannerService.php` (lines 88-99), `ClaudeSelectorService.php` (lines 68-80)
**Gaps:** No tests for:
  - Empty response (null content)
  - Missing text block (only tool_use block)
  - Malformed JSON (trailing comma, comments)
  - Missing required field (decision)
**Priority:** Medium — add test cases for each scenario

### 5. File I/O Error Handling Untested

**Issue:** `file_get_contents()`, `file_put_contents()`, `mkdir()` calls assume success
**Files:**
  - `app/Services/ClaudeExecutorService.php` (lines 285-331)
  - `app/Config/GlobalConfig.php` (lines 47-70)
  - `app/Support/PlanArtifactStore.php` (lines 50-104)
**Gaps:** No tests for:
  - Permission denied on file write
  - Disk full on file write
  - Directory does not exist (mkdir fails)
  - Symlink traversal attacks (not checked)
**Priority:** Low — rare in practice, but high-value if tested. Use temp filesystem in tests.

## Dependencies at Risk

### 1. Anthropic PHP SDK Version Management

**Risk:** SDK pinned to a version in `composer.lock`; upstream breaking changes could break if not tested
**Files:** `composer.json`, `composer.lock`
**Current state:** Using `anthropic-sdk-php` ~1.0. No major version constraint documented.
**Impact:** If SDK releases 2.0 with breaking API changes, `composer update` breaks silently.
**Migration plan:** Audit SDK releases quarterly. Pin major version in composer.json if not already. Test SDK updates in CI before deploying.

### 2. Symfony Process Component

**Risk:** `Process::fromShellCommandline()` invokes shell, which is inherently risky if command is unsanitized
**Files:** `app/Services/GitHubService.php`, `app/Services/ClaudeExecutorService.php`
**Current state:** All commands are pre-validated against allowlist. Safe for current use.
**Migration plan:** If shell execution becomes untrusted (e.g., user-supplied commands), migrate to `Process` array form with explicit args (no shell). Requires refactoring of GitService commands.

### 3. Laravel Zero Framework Version

**Risk:** Laravel Zero bundles Laravel; major version upgrades break dependencies
**Files:** `composer.json`, `vendor/laravel-zero/`
**Current state:** Version pinned in composer.lock; updates require explicit `composer update`
**Impact:** Outdated version may have security patches behind. Regular updates recommended.
**Recommendation:** Add CI job to weekly test latest Laravel Zero release (non-blocking).

## Missing Critical Features

### 1. No Resumption on Partial Failure

**Issue:** If executor completes 8/12 rounds and hits a transient API error, entire run is lost
**Files:** `app/Services/ClaudeExecutorService.php` (full agentic loop)
**Impact:** No way to resume from round 8. Must start from scratch, wasting selector + planner tokens.
**Blocks:** Cost optimization on long-running issues, retry strategies
**Fix approach:** Serialize message history + run state to `.copland/checkpoints/run-{id}.json` after each round. On retry, load and resume from last checkpoint.

### 2. No Execution Timeout per Tool Call

**Issue:** Tool calls (especially `run_command`) have no individual timeout
**Files:** `app/Services/ClaudeExecutorService.php` (lines 334-345)
**Impact:** A hanging command (e.g., `npm install` with network issue) blocks entire run indefinitely
**Current:** Process timeout set to 120s globally
**Blocks:** Reliable execution on unreliable networks
**Fix approach:** Add `max_tool_timeout` to `ExecutorPolicy`. Wrap each tool dispatch in timeout handler.

### 3. No Verification of Executor Output

**Issue:** Executor's summary is free-form text; no structured output about what was changed
**Files:** `app/Services/ClaudeExecutorService.php` (line 125), `app/Services/RunOrchestratorService.php` (line 207)
**Impact:** Verification cannot validate that executor's claimed changes match actual git diff
**Blocks:** Confidence in output correctness, audit trail
**Fix approach:** Require executor to output structured JSON with list of changed files and line counts. Verify against `git diff` in verification step.

---

## Summary Table

| # | Item | Severity | Files | Effort |
|---|------|----------|-------|--------|
| **Tech Debt** |
| 1 | Guardrail text heuristic fragile | High | `ClaudeExecutorService.php` | Small |
| 2 | No API retry/backoff | High | `ClaudeExecutorService.php`, `Planner`, `Selector` | Small |
| 3 | File read size unbounded | Medium | `ClaudeExecutorService.php` | Small |
| 4 | Command allowlist exact match brittle | Medium | `ExecutorPolicy.php` | Small |
| 5 | Unhandled optional fields in responses | Medium | `Planner`, `Selector` | Small |
| **Known Bugs** |
| 6 | GitHubService pagination capped at 50 | Low | `GitHubService.php` | Trivial |
| 7 | HOME env var resolution fragile | Low | `PlanArtifactStore.php`, `GlobalConfig.php` | Small |
| 8 | replaceOnce() no fallback strategy | Low | `FileMutationHelper.php` | Small |
| **Test Gaps** |
| 9 | ClaudeExecutorService untested | Critical | new test | Medium |
| 10 | RunOrchestratorService untested | Critical | new test | Medium |
| 11 | JSON parsing resilience untested | Medium | new tests | Small |
| 12 | File I/O error handling untested | Medium | new tests | Medium |

*Concerns audit: 2026-04-02*

# Improvements Backlog

Findings from a full codebase review. Ordered by priority within each section.

---

## Cost Savings

### 1. Prompt caching on the executor loop — highest ROI

The executor system prompt + tool definitions (~800 tokens) are sent on every round of the
agentic loop. For a 12-round execution, you pay for those tokens 12 times. Adding
`cache_control` to the system prompt caches it across rounds at ~10% of normal input token
cost after the first call.

One-line change in `ClaudeExecutorService::executeWithPolicy()`:

```php
// Before
system: $systemPrompt,

// After
system: [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]],
```

Estimated savings: 60–70% of executor input token cost per run.

### 2. Cap file read size

`readFile` returns raw file contents with no size limit. If Claude reads a large file, that
content is appended to the conversation history and re-sent on every subsequent round.
A few large reads in a 12-round execution can multiply input token costs significantly.

Add a configurable line cap (e.g. 300 lines) with a truncation notice:

```php
$lines = explode("\n", file_get_contents($fullPath));
if (count($lines) > $maxReadLines) {
    return implode("\n", array_slice($lines, 0, $maxReadLines))
        . "\n\n[truncated — file has " . count($lines) . " lines total]";
}
```

### 3. Haiku executor for small-scope issues

The executor uses `claude-sonnet-4-6`, which is 15x more expensive than haiku. For issues
with small declared scope (e.g. `max_lines_changed <= 50`, single file), haiku can handle
implementation adequately. Options:

- Add an `executor_model` field to the planner's JSON output so the planner decides.
- Or derive it from `max_lines_changed` in `executeWithRepoProfile()`.

---

## Quality

### 1. No tests on the most critical paths

`ClaudeExecutorService` (the tool-use loop) and `RunOrchestratorService` (the full
8-step workflow) have no test coverage. These are the highest-risk areas — they run
unattended, write code, and open PRs.

Highest-value tests to add, roughly in order:

**a) `ExecutorRunState` — pure logic, no mocks needed**
- Thrashing detection triggers at 5 rounds with no write/command
- Malformed write abort triggers at 2 occurrences
- `listDirectory` budget exceeded at 6 calls
- `canListDirectory()` blocked until pending reads are cleared

**b) `ExecutorPolicy` — path and command enforcement**
- Path traversal attempts (`../../etc/passwd`) throw `PolicyViolationException`
- Absolute paths throw
- Blocked paths from repo config are rejected on read and write
- Unknown commands throw
- `visibleEntries()` filters blocked paths out of directory listings

**c) `RunOrchestratorService` — orchestration flow**
- Mock all 8 injected services
- Verify early exit at each failure point (no issues, selector skip, planner decline,
  plan validation failure, execution failure, verification failure)
- Verify cleanup runs in `finally` regardless of which step fails
- Verify issue label is removed on success
- Verify GitHub comment posted on both success and failure

### 2. `assertCommandAllowed` exact-match is too brittle

`ExecutorPolicy::assertCommandAllowed()` uses exact string equality. If the planner
generates `php artisan test --filter FooTest` and the executor calls
`php artisan test --filter FooTest ` (trailing space), it fails. `trim()` helps with
whitespace but not argument variations.

The deeper issue: the plan's `commands_to_run` are specific invocations, but the
repo-level `allowed_commands` are prefixes (e.g. `php artisan`). Consider validating
that the command *starts with* one of the repo-level allowed commands, with the plan's
list acting as an additional restriction.

### 3. Guardrail text heuristic in `writeFile` is fragile

`ClaudeExecutorService::writeFile()` (line ~300) checks guardrails with:

```php
str_contains(strtolower($guardrail), 'block') && str_contains($guardrail, $normalizedPath)
```

This text-searches free-prose guardrails for the word "block". A guardrail like
"Do not unblock the restricted table" would false-positive. The check gives false
confidence — it could silently miss real blocks or trigger on unrelated guardrails.

Guardrails that are meant to enforce write restrictions should be structured (e.g. a
separate `blocked_write_paths` array in the plan), not parsed from free text.

### 4. No retry on Anthropic API errors

Any 429 or 5xx from Anthropic throws immediately and fails the entire run, wasting all
selector and planner tokens already spent. Since runs are unattended overnight, a single
transient error is a full loss.

Add exponential backoff with 2–3 retries around the `$this->client->messages->create()`
call in the executor loop. The selector and planner are single calls and cheaper; adding
retry there too is low-effort.

### 5. `getIssues` pagination capped at 50

`GitHubService::getIssues()` hardcodes `per_page: 50` with no pagination. If a repo has
more than 50 `agent-ready` issues, the extras are silently ignored. Not a current problem
but worth either: adding a comment noting the limitation, or implementing cursor-based
pagination.

### 6. Token pricing in `AnthropicCostEstimator` will drift

Rates are hardcoded and matched by model name fragment. Add a comment noting when the
rates were last verified so it's obvious when they need updating.

---

## Summary Table

| # | Item | Files | Effort |
|---|------|-------|--------|
| 1 | Prompt caching on executor | `ClaudeExecutorService.php` | 1 line |
| 2 | File read size cap | `ClaudeExecutorService.php` | ~10 lines |
| 3 | Tests: `ExecutorRunState` & policy | new test files | Small |
| 4 | Tests: `RunOrchestratorService` | new test file | Medium |
| 5 | API retry/backoff | `ClaudeExecutorService.php` | Small |
| 6 | Fix/remove guardrail text heuristic | `ClaudeExecutorService.php` | Small |
| 7 | Haiku executor for small issues | `ClaudeExecutorService.php`, config | Medium |
| 8 | `getIssues` pagination | `GitHubService.php` | Small |
| 9 | Pricing comment in cost estimator | `AnthropicCostEstimator.php` | Trivial |

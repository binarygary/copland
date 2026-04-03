# Domain Pitfalls: Autonomous Overnight Coding Agent

**Domain:** Unattended LLM agentic loop running against live GitHub repos
**Researched:** 2026-04-02
**Overall confidence:** HIGH (grounded in codebase review + verified Anthropic API behavior)

---

## Critical Pitfalls

Mistakes that cause full-run loss, silent bad output, or cascading downstream failures.

---

### Pitfall 1: No Retry on Transient API Errors Destroys the Entire Run

**What goes wrong:** A 429 rate-limit or 5xx server error from the Anthropic API at round 8 of 12 tears down the full run. Every token spent on selector and planner is wasted. The issue is left in its pre-run state (label intact, no PR, no comment). The next cron invocation picks the same issue, spends selector + planner tokens again, and has the same failure probability at the same point in execution.

**Why it happens:** `$this->client->messages->create()` at line 90 of `ClaudeExecutorService.php` has zero error handling. PHP's Anthropic SDK throws on non-2xx responses. The executor loop has no try/catch around the API call itself — only tool dispatch errors are caught.

**Consequences:**
- Overnight runs silently "succeed" from cron's perspective (process exits 0 on logged failure) but produce nothing
- Cost doubles on retry (selector + planner re-run)
- Backlog never clears if the same issue always hits API instability at a predictable token depth
- No indication in the morning that anything went wrong

**Warning signs:**
- Runs completing in under 10 seconds (selector/planner succeed, executor fails immediately)
- Run log shows steps 1-3 OK and then a generic exception message
- Cost reports showing only selector + planner usage, zero executor usage

**Prevention:**
- Wrap `$this->client->messages->create()` in exponential backoff: catch HTTP 429 and 5xx, sleep 1s/2s/4s, retry up to 3 times
- Distinguish retryable errors (rate limit, server error, timeout) from non-retryable (400 invalid request, 401 auth)
- Log each retry attempt so the morning review shows the transient was handled, not hidden

**Phase to address:** Reliability hardening (first improvement phase)

---

### Pitfall 2: Unbounded Context Growth Causes Exponential Token Cost and Silent Truncation

**What goes wrong:** The `$messages` array in `executeWithPolicy()` grows by two entries every round (assistant turn + tool results). Any large tool output — a 500-line file, verbose test output, a stack trace — is appended in full and retransmitted on every subsequent round. A 10KB file read in round 2 is sent to the API 11 times if the executor runs 12 rounds. This is not a hypothetical: the existing `CONCERNS.md` documents exactly this pattern.

The second failure mode is context window overflow. Claude Sonnet's context limit is 200K tokens. A 12-round executor on a file-heavy issue can plausibly accumulate:
- System prompt: ~800 tokens
- Plan contract: ~500 tokens
- 10 read_file results averaging 3KB each: ~37K tokens, each retransmitted N times
- Tool call overhead per round: ~200 tokens

At round 12, the total input can exceed 50-100K tokens. If it hits the context limit, the API returns a 400 error with `context_length_exceeded`. This is not retried by backoff logic (it is a 400, not a 5xx) and terminates the run.

**Why it happens:** Lines 63 and 192 of `ClaudeExecutorService.php` append to `$messages` with no windowing or size check. PHP arrays have no upper bound. The code was designed for short runs and has not been stress-tested on large files.

**Consequences:**
- Token cost scales as O(n²) with round count: round 12 pays for everything from round 1 through 12
- Runs on repos with large files cost 5-10x more than equivalent runs on small files
- Context overflow is a non-retryable 400 that terminates the run after substantial token spend
- Caching has zero effect on dynamically-growing message content; the cached system prompt is a minor savings

**Warning signs:**
- Cost per run increasing over time as you add larger repos to the cron schedule
- Runs against repos with large source files (>500 lines) failing late in execution
- `inputTokens` growing non-linearly across rounds (visible if per-round token counts are logged)

**Prevention:**
- Cap `read_file` output at 300 lines with a truncation notice appended (already identified in CONCERNS.md — implement it)
- Track total input tokens after each round and abort gracefully if approaching 150K (reserve headroom)
- Implement a sliding window: keep the initial contract message + last N exchanges + tool call log summary for older rounds
- For `run_command`, cap output at 200 lines — test runners produce thousands of lines that serve no purpose in the loop

**Phase to address:** Context and cost hardening (first improvement phase)

---

### Pitfall 3: Fragile Guardrail Heuristic Provides False Security

**What goes wrong:** The guardrail check at lines 300-303 of `ClaudeExecutorService.php` uses `str_contains(strtolower($guardrail), 'block')` to decide whether a guardrail applies. This is a heuristic on free-form text, not a policy rule. It has two failure modes:

1. **False positive (over-blocking):** A guardrail like `"Do not unblock the payment processing feature"` contains the substring `block`. Any write to any file will be refused because `$guardrail` contains the path to the file being written... actually no, but any path that also appears in the guardrail text string causes a match. The current check is `str_contains($guardrail, $normalizedPath)` — so if the path `src/Payments/Handler.php` is mentioned in a guardrail for unrelated reasons, writes to it are blocked.

2. **False negative (under-blocking):** A guardrail like `"Prevent modification of the configuration directory"` or `"Ensure config/ files are not altered"` does not contain the word `block`. It silently passes. The executor can write to `config/` without restriction.

**Why it happens:** Guardrails were implemented as free-form planner output before the structured path allow/block system was fully thought through.

**Consequences:**
- Executor writes to files that should be protected (false negative) — silent data loss or repo corruption
- Executor is blocked from writing to legitimate files (false positive) — wastes rounds retrying, hits thrashing abort
- In both cases, the run produces wrong output with no alerting

**Warning signs:**
- Executor log showing repeated `Policy violation: Write to '...' blocked by guardrail` on expected files
- Executor completing but `git diff` showing changes to files the planner said to leave alone
- Planner guardrails containing natural language that describes protection without the word "block"

**Prevention:**
- Replace free-form guardrail text with a structured `blocked_write_paths` array in plan JSON
- The planner should output `"blocked_write_paths": ["config/", "database/migrations/"]` — concrete, machine-readable
- The executor validates writes against this list with prefix matching, not substring heuristic
- Keep the existing `guardrails` text array for display in PR body / run log, but do not parse it for enforcement

**Phase to address:** Reliability hardening (first improvement phase)

---

### Pitfall 4: Executor Success Does Not Mean Correct Output

**What goes wrong:** `ExecutionResult->success = true` is set when `stopReason === 'end_turn'` (lines 121-136 of `ClaudeExecutorService.php`). The model decided it was done. But "end_turn" means the model has nothing more to say — it does not mean the implementation is correct, complete, or even compilable.

Specific failure modes that return `success: true`:
- Executor writes syntactically invalid PHP that fails at runtime but passes no test suite
- Executor implements a partial solution (changes 1 of 3 required files), summarizes confidently, and calls `end_turn`
- Executor encounters a tool error in round 11, recovers by writing a placeholder, and summarizes the placeholder as the implementation
- Executor writes code that changes the right file but introduces a regression in an adjacent function

The `VerificationService` catches file count and line count violations, but does not run tests, does not parse diffs, and does not validate that the executor's claimed changes match the plan's success criteria.

**Why it happens:** Verification is structurally thin (lines 26-47 of `VerificationService.php`). It checks git metadata, not code quality. The executor's `summary` is free-form text that feeds directly into the PR body.

**Consequences:**
- Draft PRs are opened for non-functional implementations
- The human reviewer (future you) sees a confident PR description written by the model, assumes it works, and merges
- Regressions get merged into main from draft PRs that were not carefully reviewed

**Warning signs:**
- PR descriptions that say "implemented X" but the diff shows trivial or partial changes
- Executor completing in 3-4 rounds on tasks the planner estimated as multi-file, multi-step
- Test commands listed in the plan are never called in the tool call log

**Prevention:**
- Require the executor to run its own test commands as part of the plan's `commands_to_run`; fail if exit code is non-zero
- Verify the executor's `toolCallLog` contains at least one `run_command` for each command in `plan->commandsToRun`
- Add a structured executor output: require JSON with list of changed files and short rationale before `end_turn`
- Consider running `php artisan test` unconditionally after execution and treating non-zero exit as verification failure

**Phase to address:** Verification hardening (second improvement phase)

---

### Pitfall 5: No Run Audit Trail Makes Overnight Failures Invisible

**What goes wrong:** The `$log` array in `RunOrchestratorService` is an in-memory string array. It is output to the CLI during an interactive run via `progressCallback`. In a cron run, there is no interactive terminal. Unless cron is configured to capture stdout to a file (not guaranteed), the log is lost when the process exits.

The morning review of overnight work consists of: look at open PRs. If there are no PRs, something went wrong, but there is no record of what or why. The run log, tool call log, selector decision, planner plan, and executor summary are all gone.

**Why it happens:** The system has no structured log writer. GitHub issues get a comment on success or failure (steps 204 and 158 in `RunOrchestratorService.php`), which provides minimal signal. But the full execution trace — which issue was considered, why the planner declined, what tools were called, which files were written — is not persisted anywhere.

**Consequences:**
- Debugging overnight failures requires re-running the same issue interactively and hoping to reproduce
- Silent `skip_all` decisions (selector found nothing suitable) look identical to process crashes
- Cost tracking is impossible without per-run records
- Patterns in failures (same repo always fails in round 8, same issue type always gets declined) are invisible

**Warning signs:**
- You cannot answer "what did Copland do last night?" without examining GitHub issues manually
- An issue has been labeled `agent-ready` for a week but no PR ever appears and no failure comment exists
- Cron reports exit 0 but no PRs were opened

**Prevention:**
- Write a structured JSON run log to `~/.copland/runs/{repo}/{date}-{issue}.json` at run end (both success and failure)
- Include: run timestamp, selected issue, selector decision + reason, planner decision + reason, tool call log, verification result, final status, token counts
- On `skip_all`, write a log entry with the list of considered issues and skip reasons — not just silence
- The GitHub issue comment on failure (already present) is a good fallback signal; keep it

**Phase to address:** Observability (first or second improvement phase)

---

## Moderate Pitfalls

Mistakes that waste money, create maintenance burden, or degrade reliability over time.

---

### Pitfall 6: HOME Environment Variable Not Set in Cron Breaks Config Loading

**What goes wrong:** `$_SERVER['HOME']` is not reliably set in cron/launchd environments on macOS. When the cron job runs, the HOME environment may not be inherited from the user shell profile. `PlanArtifactStore.php` and `GlobalConfig.php` both depend on `$_SERVER['HOME']` to locate `~/.copland/`. If it is missing, the entire run fails before reaching the API — and because there is no persistent log yet (see Pitfall 5), this failure is invisible.

**Warning signs:** Cron job exits non-zero; no output captured; issues stay in `agent-ready` state indefinitely.

**Prevention:** Replace `$_SERVER['HOME'] ?? null` with `getenv('HOME') ?: (posix_getpwuid(posix_geteuid())['dir'] ?? null)`. Use `posix_getpwuid` as the ultimate fallback — it works without any environment variable. Add an explicit startup check that verifies HOME resolution and prints a diagnostic if it fails.

**Phase to address:** Reliability hardening (first improvement phase)

---

### Pitfall 7: Orphaned Git Worktrees Accumulate on Repeated Failure

**What goes wrong:** The `finally` block in `RunOrchestratorService.php` (lines 224-232) catches workspace cleanup failures silently. If cleanup fails — because the worktree is locked, because git is in a detached state, or because a long-running test command left a subprocess holding a file handle — the worktree remains on disk. Each subsequent cron run creates a new worktree. After a week of failures (common during initial setup or API instability), you may have dozens of orphaned branches and worktrees.

**Warning signs:** `git worktree list` shows multiple stale entries; disk usage grows; `git branch` shows many `agent/...` branches that have no corresponding PRs.

**Prevention:**
- On startup, check for worktrees older than 24 hours and log a warning
- Add a `copland:cleanup` command that prunes all agent worktrees with no corresponding open PR
- For the currently failing cleanup, add more specific error logging so the exact failure mode is visible

**Phase to address:** Reliability hardening (first improvement phase)

---

### Pitfall 8: Thrashing Detection Has No Escape for Genuine Complexity

**What goes wrong:** `ExecutorRunState::shouldAbortForThrashing()` aborts if there is no write or command by round 5. This protects against lazy executors but incorrectly terminates runs where the first 4 rounds are legitimately reading context (multi-file understanding, exploration of an unfamiliar codebase structure). Complex issues that require reading 4-6 files before writing will always hit this threshold.

A separate threshold — aborting at 6 `list_directory` calls — is equally blunt. An executor on an unfamiliar repository structure may need to explore 6+ directories to locate the right files for a multi-module change.

**Warning signs:** Executor abort reason is "no implementation progress after 5 rounds" but the tool call log shows legitimate reads; run fails on tasks the planner rated as `3 files, medium complexity`.

**Prevention:**
- Make thresholds configurable per repo or per plan in `ExecutorPolicy`; expose them in `.copland.yml`
- Alternatively, make the "no progress" threshold relative to the plan's `files_to_read` count: if plan says read 4 files, allow 4+2 rounds before expecting a write
- The 6-call directory exploration limit should only trigger if no planned reads have been completed yet

**Phase to address:** Reliability hardening or executor behavior tuning

---

### Pitfall 9: Command Allowlist Exact Match Creates Retry Loops

**What goes wrong:** `ExecutorPolicy::assertCommandAllowed()` uses strict string equality (after trim). If the planner generates `php artisan test` in `commands_to_run` but the executor calls `php artisan test --filter NewFeatureTest` to run the specific new test, the policy throws `PolicyViolationException`. The executor receives the error, attempts to figure out the right command string, and retries — potentially several times, burning rounds and tokens.

This is especially acute for commands where the executor legitimately needs arguments the planner did not anticipate: `composer install --no-dev`, `npm run build -- --watch=false`, etc.

**Warning signs:** Tool call log shows repeated `run_command` calls with slight command variations, all returning policy violations; executor burns 3-4 rounds on command validation failures.

**Prevention:**
- Implement prefix matching: validate that a command starts with one of the allowed prefixes (e.g., `php artisan`, `npm`, `composer`)
- Use the plan's `commands_to_run` list as a starting set but allow argument extension on allowed prefixes
- Document this behavior in the executor system prompt so the model understands it can extend with arguments

**Phase to address:** Reliability hardening (first improvement phase)

---

### Pitfall 10: API Response Structure Assumed, Not Validated

**What goes wrong:** `ClaudeSelectorService.php` and `ClaudePlannerService.php` both access `$response->content[0]->text` with a null-coalescing fallback to empty string. If the API returns a `tool_use` block as the first content item (which is valid in some response patterns), `->text` does not exist and the null-coalesce returns `''`. `extractJson('')` then throws a generic JSON parse error. The root cause — wrong block type in response — is invisible in the error message.

More broadly, if Anthropic updates the SDK response structure (e.g., content array ordering changes), silent failures propagate without useful diagnostics.

**Warning signs:** Selector or planner throwing "JSON parse error" with no indication of what was being parsed; logs showing empty JSON extraction attempts.

**Prevention:**
- Check `$response->content[0]->type === 'text'` before accessing `->text`
- Throw with context: "Expected text block in position 0, got {type}; full response: {json}"
- Add a test case for each service with a mocked response returning a `tool_use` block in position 0

**Phase to address:** Reliability hardening (first improvement phase)

---

## Minor Pitfalls

Operational irritants that compound over time.

---

### Pitfall 11: GitHub Issue Pagination Caps Consideration Set at 50

**What goes wrong:** `GitHubService::getIssues()` fetches exactly one page of 50 issues. Repos with more than 50 open `agent-ready` issues silently have the overflow ignored. The selector never sees them. If the first 50 issues are all blocked or unsuitable, the run skips even though suitable issues exist on page 2.

**Warning signs:** Selector consistently returns `skip_all` even after labeling new issues; issue count in the labeled set is exactly 50.

**Prevention:** Add a comment documenting the 50-issue cap as a known limitation; implement link-header pagination if any repo approaches this volume.

**Phase to address:** Low priority; document the limitation first, implement pagination if needed.

---

### Pitfall 12: `replaceOnce()` Causes Retry Loops on Duplicate Code Patterns

**What goes wrong:** `FileMutationHelper::replaceOnce()` throws a `PolicyViolationException` if the `old` string appears more than once in the file. This is common in PHP: identical method signatures, repeated patterns in tests, duplicated config blocks. The executor receives the error, tries to provide a more specific string, reads the file again to understand context, burns 2-3 rounds, and may still fail if the pattern is genuinely repeated.

**Warning signs:** Executor log showing `replace_in_file` failures with "multiple matches" followed by additional `read_file` calls for the same file; late-round abort for "no progress".

**Prevention:**
- Add a `first_match` strategy option to `replaceOnce()` for cases where replacing the first occurrence is semantically correct
- Improve the error message to include the line numbers of all matches, giving the executor enough context to provide a longer, unique string
- Consider always using `write_file` for full rewrites when `replaceOnce()` fails twice on the same file

**Phase to address:** Executor behavior tuning

---

### Pitfall 13: Draft PR Body Contains Raw Executor Summary

**What goes wrong:** `executionResult->summary` (free-form text produced by the model at `end_turn`) is pasted directly into the GitHub PR body (line 207 of `RunOrchestratorService.php`). The model frequently produces summaries that:
- Claim the implementation is "fully complete" when it is a partial fix
- Reference files or line numbers that do not match the actual diff
- Include disclaimers or caveats that read as uncertain ("this should work, but may need adjustment")
- Are excessively verbose (multi-paragraph wall of text)

**Warning signs:** PRs with PR bodies that contradict the actual diff; PRs with internal contradictions in the description.

**Prevention:**
- Require the executor to output a structured JSON summary before `end_turn` with fields: `changed_files`, `approach`, `test_status`, `caveats`
- Render this structured output as a formatted PR body, not raw text
- Include the tool call count and duration in the PR description as signal of execution complexity

**Phase to address:** Output quality / observability

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation |
|-------------|---------------|------------|
| Add API retry/backoff | Backoff logic must distinguish 400 (client error, do not retry) from 429/5xx (transient, retry) | Check HTTP status code before retrying; log the error type |
| Add structured run logging | JSON log written at end-of-run will miss mid-run crashes; use append-on-each-round if durability matters | Flush log entry after each round, not at run end |
| Add prompt caching | Caching only applies to the static system prompt; message history is not cacheable and still grows O(n²) | Implement context windowing alongside caching; one without the other is incomplete |
| Add file read size cap | Cap must be applied before appending to messages, not as a display filter after the fact | Apply truncation in `readFile()` itself, not in the progress formatter |
| Add test coverage for executor | Mocked API responses must simulate multi-round sequences, not just single-turn; end_turn response must be included | Build a factory for mock response sequences: tool_use → tool_result → tool_use → end_turn |
| Add multi-repo support | Running multiple repos in sequence means one repo's failure must not prevent subsequent repos from running | Each repo run must be wrapped in its own try/catch; failure should be logged and next repo should start |
| Fix cron HOME resolution | Test in a minimal cron environment (not in a shell session) before shipping the fix | Add an explicit diagnostic command: `copland doctor` that prints resolved config path, HOME, and gh auth status |

---

## Sources

- Grounded in direct code review: `ClaudeExecutorService.php`, `RunOrchestratorService.php`, `VerificationService.php`, `ExecutorRunState.php`
- Grounded in codebase analysis: `.planning/codebase/CONCERNS.md` (2026-04-02)
- Anthropic API behavior: context window limits, stop reasons, error codes — verified from training knowledge of Anthropic API docs (HIGH confidence; these are stable API contracts)
- PHP cron environment behavior: `$_SERVER['HOME']` absence in launchd/cron — well-documented macOS/Linux behavior (HIGH confidence)
- GitHub API pagination via link headers — standard GitHub REST API v3 behavior (HIGH confidence)

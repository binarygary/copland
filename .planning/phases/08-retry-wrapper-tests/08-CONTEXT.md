# Phase 8: Retry Wrapper Tests - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Add Pest coverage for `AnthropicApiClient` so retry behavior cannot regress silently.

This phase covers automated tests for retry classification, bounded exponential backoff, and non-retryable failures on the shared retry wrapper itself. It does not rework the retry algorithm into a new architecture, add executor/orchestrator tests, or broaden coverage to every Claude service beyond the wrapper contract they already consume.

</domain>

<decisions>
## Implementation Decisions

### Test Scope
- **D-01:** Test `App\Support\AnthropicApiClient` directly rather than asserting retry behavior indirectly through `ClaudeSelectorService`, `ClaudePlannerService`, or `ClaudeExecutorService`.
- **D-02:** Keep the phase wrapper-focused: service wiring was already validated in Phase 1, so Phase 8 should prove the retry contract itself.
- **D-03:** Cover the three roadmap cases explicitly: retry on `429`, retry on `5xx` / network-style failures, and immediate failure on non-429 `4xx`.

### Retry Timing Strategy
- **D-04:** Do not call real `sleep()` in tests. Introduce a test seam for delay execution so backoff timing can be asserted without slowing the suite.
- **D-05:** Assert the exact backoff sequence derived from the current implementation: base delay doubles per retry attempt (`1`, `2`, `4` for base `1`, bounded by attempt count).
- **D-06:** Timing assertions should verify the requested delay values, not wall-clock elapsed time.

### Failure Simulation
- **D-07:** Use a fake or stub Anthropic client/messages endpoint that can return a scripted sequence of failures and eventual success.
- **D-08:** Simulate HTTP failures with exceptions whose `getResponse()->getStatusCode()` path matches the wrapper’s current status extraction logic.
- **D-09:** Simulate network failures using an exception without a response object so the wrapper exercises the `network_error` path.

### Assertion Boundaries
- **D-10:** Tests should assert attempt counts, thrown exception messages, and whether backoff was invoked for the correct cases.
- **D-11:** Tests do not need to assert Anthropic SDK internals or real HTTP behavior; only the wrapper contract that Copland owns.
- **D-12:** Preserve the wrapper’s current public API (`messages(...)`) unless a minimal injection seam is required for testability.

### the agent's Discretion
- Exact shape of the fake client / fake messages resource, as long as it is lightweight and easy to script per test.
- Whether the delay seam is an injected callable, protected method override, or similar minimal mechanism, as long as production behavior remains unchanged.
- Exact exception helper classes used in tests, as long as they drive the same status extraction and retry classification paths as production.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 8 success criteria for retry on `429` / `5xx`, no retry on `4xx`, backoff timing, and no real HTTP calls.
- `.planning/REQUIREMENTS.md` — `TEST-03` is the governing requirement for this phase.

### Existing Code (direct edit targets)
- `app/Support/AnthropicApiClient.php` — retry wrapper under test.
- `tests/Feature/ClaudeServicesTest.php` — existing constructor wiring smoke test; useful as a pattern boundary, but not the primary target.

### Prior Phase Decisions
- `.planning/phases/01-api-retry-backoff/01-CONTEXT.md` — original retry behavior decisions and the explicit note that Phase 8 will test `AnthropicApiClient`.
- `.planning/phases/01-api-retry-backoff/01-SUMMARY-wave2-api-client.md` — confirms the wrapper contract and current retry behavior.

### Codebase / Research References
- `.planning/codebase/CONCERNS.md` — documents the original retry gap and expected transient vs permanent failure split.
- `.planning/research/STACK.md` — retry classification and backoff expectations that informed the original implementation.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AnthropicApiClient` already centralizes retry behavior behind one `messages(...)` entrypoint.
- The wrapper currently depends on three narrow concepts only: an Anthropic client with `messages->create(...)`, extracted status codes from thrown exceptions, and `sleep()`-driven backoff.
- Existing unit tests use temp directories and lightweight fakes rather than broad framework integration, which fits this phase.

### Established Patterns
- Service constructors already accept injected collaborators when testability matters (`GitService` runner seam, `SetupCommand` runner/home resolvers).
- Pest tests in this repo favor direct object construction and narrow seams over container-heavy mocking for unit scope.
- Phase 1 kept retry policy inside `AnthropicApiClient`, so Phase 8 should respect that same boundary.

### Integration Points
- The only production seam likely needed is a way to observe or replace the delay behavior so tests can verify backoff without sleeping.
- Any fake client should emulate `$client->messages->create(...)` closely enough that the public wrapper API stays unchanged.

</code_context>

<specifics>
## Specific Ideas

- [auto] Add a delay callable or equivalent seam to `AnthropicApiClient` so tests can capture delays instead of sleeping.
- [auto] Create wrapper-focused unit tests under `tests/Unit/AnthropicApiClientTest.php`.
- [auto] Script one success-after-retries sequence and two fail-fast sequences rather than building a full SDK transport harness.
- [auto] Keep all tests offline and deterministic; no real Anthropic or Guzzle network calls.

</specifics>

<deferred>
## Deferred Ideas

- Service-level retry integration tests for selector, planner, or executor.
- `retry-after` header handling and jitter if the production wrapper does not yet implement them.
- Broader executor/orchestrator failure-path coverage — belongs to Phases 9 and 10.

</deferred>

---

*Phase: 08-retry-wrapper-tests*
*Context gathered: 2026-04-03*

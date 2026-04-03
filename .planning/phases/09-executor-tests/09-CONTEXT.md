# Phase 9: Executor Tests - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Add Pest coverage for `ClaudeExecutorService` so tool dispatch, abort conditions, and policy-violation handling are regression-locked.

This phase covers the executor service contract itself: multi-round response handling, tool execution routing, thrashing aborts, and failed tool outcomes when policy rejects a write. It does not add broader orchestrator integration coverage, real Anthropic API tests, or filesystem-heavy end-to-end runs beyond what is needed to exercise executor behavior deterministically.

</domain>

<decisions>
## Implementation Decisions

### Test Scope
- **D-01:** Test `App\Services\ClaudeExecutorService` directly rather than only its lower-level helpers.
- **D-02:** Keep Phase 9 focused on the roadmap cases: tool dispatch, thrashing aborts, and policy-violation handling on write operations.
- **D-03:** Reuse existing lower-level unit coverage for `ExecutorRunState` and `ExecutorPolicy` as supporting evidence, but add service-level tests that prove those concerns integrate correctly inside the executor loop.

### API / Response Simulation
- **D-04:** Use a scripted fake `AnthropicApiClient` that returns a deterministic sequence of assistant responses across rounds.
- **D-05:** Build fake response payloads that mirror the fields `ClaudeExecutorService` actually reads: `content`, `stopReason`, and optional `usage`.
- **D-06:** Keep all tests offline. No real Anthropic client, network I/O, or SDK transport behavior should be involved.

### Tool Execution Strategy
- **D-07:** Exercise real executor tool handlers where practical instead of mocking every private method, so the service test validates actual dispatch behavior.
- **D-08:** Use a temporary workspace for read/write tool cases so file mutations are isolated and deterministic.
- **D-09:** For the write-policy scenario, assert the returned execution result captures the policy failure as a failed tool outcome rather than throwing past the executor loop.

### Abort / Progress Boundaries
- **D-10:** Assert thrashing via the executor’s returned `ExecutionResult`, not only via direct `ExecutorRunState` unit tests.
- **D-11:** Use a scripted no-progress response sequence that forces the executor past the configured abort threshold without any successful write or planned command progress.
- **D-12:** Keep round-count expectations aligned with the current executor defaults and existing `ExecutorRunState` semantics instead of redefining the abort policy in this phase.

### Testability Constraints
- **D-13:** Prefer minimal seams added to `ClaudeExecutorService` only if current construction makes service-level tests impractical.
- **D-14:** Preserve the public executor entrypoints (`execute()` / `executeWithRepoProfile()`) unless a narrow collaborator seam is required to keep tests deterministic.

### the agent's Discretion
- Exact fake response builder shape, as long as it is lightweight and readable for multi-round tests.
- Whether tests call `execute()` or `executeWithRepoProfile()` per scenario, as long as policy limits and round limits are controlled explicitly where needed.
- Whether to add helper methods/test fixtures inside the new test file, as long as the service behavior under test remains clear.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 9 success criteria define tool dispatch, thrashing abort, policy-violation handling, and mock response sequencing.
- `.planning/REQUIREMENTS.md` — `TEST-01` is the governing requirement for this phase.

### Existing Code (direct edit targets)
- `app/Services/ClaudeExecutorService.php` — primary service under test.
- `app/Support/ExecutorRunState.php` — current thrashing semantics already covered at unit level.
- `app/Support/ExecutorPolicy.php` — current policy enforcement behavior already covered at unit level.

### Existing Tests / Patterns
- `tests/Unit/ExecutorRunStateTest.php` — documents current no-progress and malformed-call abort semantics.
- `tests/Unit/ExecutorPolicyTest.php` — documents blocked write and command validation behavior.
- `tests/Unit/AnthropicApiClientTest.php` — recent example of lightweight scripted fake collaborators for service-adjacent behavior.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `ClaudeExecutorService` already isolates Anthropic calls behind `AnthropicApiClient`, which is an easy seam for scripted responses.
- The executor already returns structured `ExecutionResult` objects for success, tool failures, and thrashing aborts.
- Tool policy logic and thrash detection live in dedicated support classes, so Phase 9 can focus on integration inside the executor loop.

### Established Patterns
- Pest unit tests in this repo prefer narrow fakes and temp workspaces over container-heavy integration setups.
- The executor appends assistant and tool-result messages round by round, so scripted multi-round responses are the natural test driver.
- Policy violations are converted into tool-result errors inside the executor rather than crashing the whole run; tests should assert that contract directly.

### Integration Points
- The service reads the executor prompt from `resources/prompts/executor.md`, so tests should avoid brittle assertions tied to prompt text.
- Tool dispatch reaches real file and command helpers inside the service, so temporary workspace setup is the safest path for deterministic coverage.
- Thrashing currently triggers after repeated no-progress rounds or repeated malformed tool patterns; Phase 9 should cover at least the roadmap-required no-progress path.

</code_context>

<specifics>
## Specific Ideas

- [auto] Add service-level tests under `tests/Unit/ClaudeExecutorServiceTest.php`.
- [auto] Create a small fake response factory/helper for multi-round assistant/tool-use sequences.
- [auto] Cover one successful tool-dispatch path, one no-progress thrash abort path, and one blocked-write policy failure path.
- [auto] Keep all scenarios offline and deterministic with temp directories and scripted usage payloads.

</specifics>

<deferred>
## Deferred Ideas

- Full orchestrator flow coverage with selector/planner/verification interactions — belongs to Phase 10.
- Assertions about exact prompt text or Anthropic SDK serialization internals.
- Additional executor edge cases beyond the roadmap contract, such as malformed `run_command` sequences or cache-token accounting.

</deferred>

---

*Phase: 09-executor-tests*
*Context gathered: 2026-04-03*

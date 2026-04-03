# Phase 10: Orchestrator Tests - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Add Pest coverage for `RunOrchestratorService` so the full overnight pipeline and its early-exit paths are regression-locked.

This phase covers orchestrator behavior at the service boundary: the 8-step happy path, planner/selector/verification failure exits, crash handling, and guaranteed cleanup/logging in the `finally` block. It does not add GitHub CLI integration tests, real git/worktree execution, or broader documentation work.

</domain>

<decisions>
## Implementation Decisions

### Test Scope
- **D-01:** Test `App\Services\RunOrchestratorService` directly with mocked collaborators rather than indirectly through `RunCommand`.
- **D-02:** Cover the roadmap-required happy path plus explicit early exits for selector skip, planner decline, validation failure, executor failure/verification failure, and thrown-exception cleanup.
- **D-03:** Keep Phase 10 focused on orchestrator control flow, result shaping, and lifecycle guarantees, not on re-testing selector, planner, executor, or git internals already covered elsewhere.

### Dependency Strategy
- **D-04:** Mock all injected collaborators (`GitHubService`, `IssuePrefilterService`, `ClaudeSelectorService`, `ClaudePlannerService`, `PlanValidatorService`, `WorkspaceService`, `GitService`, `ClaudeExecutorService`, `VerificationService`) so tests perform no real API, filesystem, or git work.
- **D-05:** Stub return objects at the data-contract level (`RunResult`, selection result, plan result, verification result) rather than using broad container wiring.
- **D-06:** Treat `PlanArtifactStore` and `RunLogStore` as side effects that may need a narrow seam or controlled temp-path strategy if they block deterministic tests.

### Control-Flow Assertions
- **D-07:** Assert both final `RunResult` shape and key collaborator calls for each branch so tests prove the orchestrator took the correct path.
- **D-08:** Verify the `finally` cleanup path runs when executor or downstream collaborators throw, not only on the happy path.
- **D-09:** Assert partial/crash logging behavior through the orchestrator contract without requiring real overnight runs.

### Testability Constraints
- **D-10:** Prefer minimal seams added to `RunOrchestratorService` only where direct mocking is impossible, such as internal `new RunLogStore` / `new PlanArtifactStore` construction.
- **D-11:** Preserve the public `run(...)` API unless a narrow collaborator injection is required to isolate storage side effects cleanly.

### the agent's Discretion
- Exact mocking library and fixture style, as long as the tests remain readable and deterministic.
- Whether to group scenarios into one or multiple plan files later, depending on how much seam work the artifact/logging paths require.
- Exact split between result assertions and collaborator interaction assertions, as long as each roadmap branch is proven.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 10 success criteria define the happy path, early exits, `finally` cleanup, and mocked-collaborator requirement.
- `.planning/REQUIREMENTS.md` — `TEST-02` is the governing requirement for this phase.

### Existing Code (direct edit targets)
- `app/Services/RunOrchestratorService.php` — primary service under test.
- `app/Data/RunResult.php` — output contract for orchestrator outcomes.
- `app/Support/RunLogStore.php` — crash/partial logging side effect likely relevant to testability.
- `app/Support/PlanArtifactStore.php` — plan artifact persistence side effect likely relevant to validation-path tests.

### Existing Tests / Patterns
- `tests/Unit/ClaudeExecutorServiceTest.php` — recent example of service-level fake-response testing with narrow seams.
- `tests/Unit/ExecutorPolicyTest.php` and `tests/Unit/ExecutorRunStateTest.php` — examples of focused unit coverage around collaborators the orchestrator composes.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `RunOrchestratorService` already receives almost all of its external collaborators via constructor injection, which is favorable for deterministic unit testing.
- The service centralizes final `RunResult` shaping and partial crash payload generation in one place.
- Cleanup and run-log append behavior are both concentrated in the `finally` block, which gives Phase 10 a single critical lifecycle area to test.

### Established Patterns
- The orchestrator emits step-by-step log lines and returns structured results rather than raw exceptions for most expected failure modes.
- Earlier testing phases favored direct service instantiation with narrow fake collaborators over full framework bootstrapping.
- Phase 9 proved that minimal seams are acceptable when a service otherwise hardcodes a side effect needed for deterministic tests.

### Integration Points
- Internal instantiation of `PlanArtifactStore` and `RunLogStore` is the likely friction point for pure unit tests.
- The happy path touches every major collaborator in order: issues, selection, planning, validation, workspace creation, execution, verification, commit/push, PR creation, and issue updates.
- Crash-path tests need to observe both rethrow behavior and cleanup/logging side effects.

</code_context>

<specifics>
## Specific Ideas

- [auto] Add service-level tests under `tests/Unit/RunOrchestratorServiceTest.php`.
- [auto] Mock collaborator responses for the happy path and each early-exit branch listed in the roadmap.
- [auto] Add a narrow seam for run-log and plan-artifact stores only if internal instantiation makes offline tests brittle.
- [auto] Include one thrown-exception case that proves workspace cleanup still runs from `finally`.

</specifics>

<deferred>
## Deferred Ideas

- Full command-level integration tests through `RunCommand`.
- Real git/worktree integration coverage.
- Additional UX assertions about exact progress log wording beyond the critical decision-path markers.

</deferred>

---

*Phase: 10-orchestrator-tests*
*Context gathered: 2026-04-03*

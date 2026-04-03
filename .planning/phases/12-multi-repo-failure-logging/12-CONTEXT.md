# Phase 12: Multi-Repo Failure Logging - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Close the milestone audit gap where repo-level failures caught before `RunOrchestratorService` starts do not produce structured JSONL run-log records.

This phase covers the pre-orchestrator failure path inside `RunCommand`, the shape of the structured payload written for that path, and regression coverage proving multi-repo fail-and-continue behavior still emits a run-log entry per failed repo. It does not broaden the scope to new status-command features, parallel repo execution, or a redesign of the orchestrator logging model.

</domain>

<decisions>
## Implementation Decisions

### Scope
- **D-01:** Fix the specific gap identified by the milestone audit: exceptions caught in `RunCommand` before `RunOrchestratorService` begins must still append a structured record to `~/.copland/logs/runs.jsonl`.
- **D-02:** Keep the existing multi-repo fail-and-continue behavior intact; this phase adds logging coverage, not new runner control flow.
- **D-03:** Treat this as a real product bug tied to `OBS-01` and `SCHED-02`, not only as audit paperwork.

### Logging Ownership
- **D-04:** Prefer reusing the existing `RunLogStore` payload shape rather than inventing a second ad hoc log format for repo-level failures.
- **D-05:** If a small shared helper is needed to keep `RunCommand` and `RunOrchestratorService` aligned on payload structure, that is acceptable, but avoid refactoring the whole logging stack.
- **D-06:** The pre-orchestrator failure record should still identify the repo, failed status, timestamps, failure reason, and an empty or minimal decision path.

### Test Strategy
- **D-07:** Add focused regression coverage around the failing `RunCommand` path rather than relying only on orchestrator tests.
- **D-08:** Keep the test offline and deterministic by using a temp HOME / fake log store seam / narrow command helper seam as needed.
- **D-09:** The regression should prove both halves of the milestone requirement together: one repo can fail before orchestrator startup, the next repo still runs, and the failed repo gets a JSONL record.

### the agent's Discretion
- Whether the cleanest implementation is a `RunCommand` seam, a shared payload builder, or a small helper method, as long as payload structure remains consistent with existing log records.
- Exact test placement (`Feature` vs `Unit`) so long as it exercises `RunCommand` behavior directly and stays isolated from real user paths.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 12 success criteria define pre-orchestrator logging, payload contents, fail-and-continue preservation, and regression coverage.
- `.planning/REQUIREMENTS.md` — `OBS-01` and `SCHED-02` are the governing requirements for this phase.

### Audit / Gap Source
- `.planning/v1.0-MILESTONE-AUDIT.md` — identifies the missing connection from `RunCommand` pre-orchestrator failures to `RunLogStore`.

### Existing Code (direct edit targets)
- `app/Commands/RunCommand.php` — catches repo-level failures before orchestrator startup.
- `app/Services/RunOrchestratorService.php` — current source of structured run-log payloads for normal and partial runs.
- `app/Support/RunLogStore.php` — persistence target for JSONL logging.
- `tests/Feature/RunCommandTest.php` — existing test surface for user-visible `RunCommand` behavior.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `RunOrchestratorService` already owns the stable payload structure for structured run logs.
- `RunCommand` already centralizes multi-repo iteration and pre-orchestrator exception handling in one loop.
- `RunLogStore` is narrow and can be reused directly if the command gains a small seam or helper path.

### Established Patterns
- Earlier testing phases preferred minimal seams over architectural refactors.
- Service-level and command-adjacent tests in this repo use narrow fakes or temp-path setup rather than hitting real user-scoped filesystem locations.
- The project already treats `runs.jsonl` as the morning-review source of truth, so this path should match existing records as closely as possible.

### Integration Points
- The new logging path should not duplicate or interfere with `RunOrchestratorService` logging for normal runs.
- The `overallExitCode()` and aggregated summary output in `RunCommand` should remain unchanged.
- The future verification-backfill phase will likely cite this implementation as audit evidence, so the path should stay simple and explicit.

</code_context>

<specifics>
## Specific Ideas

- [auto] Add a narrow `RunLogStore` seam or helper to `RunCommand` so tests can assert the pre-orchestrator payload.
- [auto] Reuse the same repo/status/failure/timestamp shape already used in structured run logs.
- [auto] Add a regression test where one configured repo path fails before orchestrator startup and still results in a JSONL log entry.

</specifics>

<deferred>
## Deferred Ideas

- Implementing the `status` command on top of `runs.jsonl`.
- Broader runner refactors or parallel repo execution.
- Reworking the orchestrator logging architecture beyond this specific gap.

</deferred>

---

*Phase: 12-multi-repo-failure-logging*
*Context gathered: 2026-04-03*

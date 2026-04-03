# Phase 3: Structured Run Log - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Add a machine-readable per-run log and formalize the morning-review output path so every run leaves a readable local record without requiring GitHub access.

This phase covers persistent JSON Lines logging under `~/.copland/logs/`, wiring log emission through the run lifecycle so success, skip, failure, and crash paths all produce usable records, and preserving the existing CLI cost summary as a first-class phase requirement. It does not introduce prompt caching, cache-aware pricing, multi-repo scheduling, or broader orchestrator test coverage beyond what is required to make the log contract trustworthy.

</domain>

<decisions>
## Implementation Decisions

### Log Storage
- **D-01:** The run log should be stored at `~/.copland/logs/runs.jsonl`, matching the roadmap and keeping history append-only.
- **D-02:** Path resolution should use `App\Support\HomeDirectory::resolve()` rather than reading `HOME` directly, because Phase 1 already centralized that fallback logic and Phase 3 explicitly depends on it.
- **D-03:** The logger should create the `~/.copland/logs` directory on demand.

### Log Shape
- **D-04:** Each run should append one complete JSON object per line rather than rewriting a state file or storing one file per run.
- **D-05:** Each record should include at minimum: repo, selected issue metadata when available, final status, failure reason when present, timestamps, PR metadata when present, and usage/cost fields already surfaced through `RunResult`.
- **D-06:** The log should preserve the orchestrator step log as structured array data so the morning review can reconstruct what happened without parsing terminal output.

### Lifecycle and Crash Behavior
- **D-07:** Logging should be driven from the run orchestration path, not from the CLI command, because the orchestrator owns the actual success/skip/failure transitions and already aggregates run details.
- **D-08:** The logging write must happen through a `finally`-style path so early returns and thrown exceptions still produce a partial record.
- **D-09:** A crash/exception record should explicitly indicate incompleteness rather than pretending the run reached a normal failed state.

### Cost Summary
- **D-10:** The existing CLI usage summary in `RunCommand::renderUsage()` should be preserved as the phase’s console output baseline rather than replaced.
- **D-11:** Logged usage should reuse the existing `ModelUsage` / `AnthropicCostEstimator` outputs already carried on `RunResult` and `RunProgressSnapshot`, avoiding a second cost representation in this phase.

### the agent's Discretion
- Exact class placement for the run logger (`Support` vs `Services`) as long as filesystem concerns remain isolated and reusable.
- Exact JSON field names beyond the roadmap must-haves, as long as repo, issue, status, timestamps, and cost/usage are easy to inspect with `jq`.
- Whether partial crash records include a dedicated boolean such as `completed` / `partial` or an equivalent explicit status field.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements and Scope
- `.planning/ROADMAP.md` — Phase 3 goal and success criteria for JSON Lines logging and CLI cost output
- `.planning/REQUIREMENTS.md` — `OBS-01` and `OBS-02` define the required behavior
- `.planning/PROJECT.md` — current validated scope and the Phase 1 HOME-resolution dependency

### Existing Runtime Surfaces
- `app/Services/RunOrchestratorService.php` — owns the 8-step run lifecycle and is the primary integration point for structured logging
- `app/Data/RunResult.php` — current structured return contract for run outcomes, issue metadata, usage, and failure reasons
- `app/Commands/RunCommand.php` — already prints selector/planner/executor/total usage and elapsed time
- `app/Support/RunProgressSnapshot.php` — holds partial usage details during in-flight execution and SIGINT handling
- `app/Support/HomeDirectory.php` — shared HOME resolver required for stable log path resolution

### Cost and Usage Surfaces
- `app/Data/ModelUsage.php` — current structured model usage contract
- `app/Support/AnthropicCostEstimator.php` — existing formatting and combination logic for usage/cost summaries
- `app/Services/ClaudeExecutorService.php` — populates live executor usage into `RunProgressSnapshot`

### Existing Verification/Behavior Patterns
- `app/Services/VerificationService.php` — current verification boundary after executor completion
- `.planning/codebase/CONVENTIONS.md` — service/support patterns and error-handling expectations
- `.planning/codebase/STRUCTURE.md` — current architectural boundaries for commands, services, support helpers, and data contracts

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `RunOrchestratorService` already accumulates a step-by-step `log` array and returns it on `RunResult`, so Phase 3 can persist existing structured data instead of inventing a second logging stream.
- `RunCommand` already renders per-component and total usage via `AnthropicCostEstimator::format()` and `combine()`, so the CLI output requirement is largely present.
- `RunProgressSnapshot` already captures partial selector/planner/executor usage for SIGINT handling, which is likely the best source for incomplete-run cost metadata.
- `HomeDirectory::resolve()` already solves the path-resolution problem called out in the roadmap dependency note.

### Established Patterns
- Filesystem persistence helpers live under `App\Support` when they encapsulate a concrete storage concern.
- Runtime summary data is carried in typed data objects (`RunResult`, `ModelUsage`) rather than ad hoc arrays passed around commands.
- The orchestrator uses early returns for skip/fail branches and a `finally` block for workspace cleanup, so any guaranteed logging mechanism must fit that control flow.

### Integration Points
- The log write path likely belongs near the end of `RunOrchestratorService::run()` so it can see the final `RunResult` or exception context.
- Partial/crash logging likely needs a mutable run-context structure assembled before the first early return so it is available even if the run never reaches a normal `RunResult`.
- `RunCommand` should continue to own terminal presentation, but the persisted usage data should come from the same structured result/snapshot fields instead of recomputation.

</code_context>

<specifics>
## Specific Ideas

- Prefer a dedicated `RunLogStore` or similarly scoped helper that appends one JSON object plus newline to `runs.jsonl`.
- Store timestamps in ISO 8601 / `DATE_ATOM` format to keep `jq` output readable and consistent with existing plan artifacts.
- Include both high-level outcome fields and the orchestrator step log array so morning review answers both "did it work?" and "where did it stop?"
- Treat CLI cost output as an invariant to preserve while adding the persistent log, not as a separate redesign task.

</specifics>

<deferred>
## Deferred Ideas

- `copland status` / log-reading commands — explicitly deferred to later observability work
- Cache-aware pricing fields — belongs to Phases 4 and 5
- Multi-repo log aggregation UX — belongs after Phase 6 introduces multi-repo runs
- Full orchestrator test matrix — explicitly deferred to Phase 10

</deferred>

---
*Phase: 03-structured-run-log*
*Context gathered: 2026-04-03*

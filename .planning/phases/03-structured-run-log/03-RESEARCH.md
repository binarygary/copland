# Phase 3: Structured Run Log - Research

**Date:** 2026-04-03
**Phase:** 3
**Status:** Complete

## Research Question

What do we need to know to plan and implement a crash-safe structured run log for Copland without over-expanding scope beyond Phase 3?

## Current State

- `RunOrchestratorService` already owns the full run lifecycle and accumulates a human-readable step log in `$this->log`.
- `RunResult` already carries most outcome metadata needed for persistence: status, selected issue fields, failure reason, log array, per-model usage, and executor duration.
- `RunCommand` already prints selector/planner/executor/total cost summary via `AnthropicCostEstimator::format()` and `combine()`.
- `RunProgressSnapshot` already captures partial usage during execution and on SIGINT.
- `HomeDirectory::resolve()` already provides the global path-resolution primitive Phase 3 depends on.

## Recommended Architecture

### 1. Use a dedicated append-only log store

Create a helper such as `App\Support\RunLogStore` that:
- Resolves `~/.copland/logs/runs.jsonl` via `HomeDirectory::resolve()`
- Creates the parent directory on demand
- Appends exactly one JSON object plus newline per run
- Accepts structured arrays rather than raw JSON strings from callers

This keeps filesystem behavior isolated and testable.

### 2. Keep orchestration as the source of truth

`RunOrchestratorService` should build a mutable run-log context from the start of `run()`:
- `repo`
- selected issue metadata when known
- start timestamp
- step log entries
- usage/cost data as they become available

At the end, or on early return, the orchestrator should assemble a final persisted payload from that context plus the `RunResult`.

### 3. Handle crash paths explicitly

The existing control flow uses multiple early returns plus a `finally` block for workspace cleanup. To satisfy the roadmap’s partial-log requirement, logging should also happen through a guaranteed finalization path.

Recommended approach:
- Track a local `$result = null`
- Track `$startedAt`
- On normal paths, assign a `RunResult` before returning
- In `catch`, convert the exception into a structured partial/crash payload, then rethrow or return a failed result depending on design choice
- In `finally`, append one log entry using either the final `RunResult` or the exception/partial context

This avoids duplicating log writes across every return branch.

### 4. Reuse existing usage structures

Do not invent a second cost model in Phase 3. Persist nested usage blocks derived from existing `ModelUsage` objects:
- selector
- planner
- executor
- total

`AnthropicCostEstimator::combine()` is already the canonical total-cost combiner.

### 5. Treat OBS-02 as a regression-locking exercise

The CLI already prints a cost summary. Phase 3 should preserve that behavior and, if practical, add focused regression coverage rather than redesigning output.

## Risks and Mitigations

### Risk: Logging only on success/failure return paths misses thrown exceptions
Mitigation: write from a guaranteed finalization path with explicit exception context.

### Risk: Log serialization drifts from the actual runtime result contract
Mitigation: build the stored record from `RunResult`, `RunProgressSnapshot`, and the orchestrator’s existing log array rather than recomputing data.

### Risk: Oversized persisted payloads if every transient detail is logged
Mitigation: persist high-level step log lines and structured outcome metadata, not raw executor transcripts or tool payloads.

### Risk: HOME/path handling regresses under launchd or non-shell environments
Mitigation: route all log-path resolution through `HomeDirectory::resolve()` and add filesystem-level tests around the store helper.

## Recommended Plan Decomposition

### Wave 1

1. Add `RunLogStore` with tested JSONL append behavior and nested usage serialization.
2. Add focused command/output regression coverage so the existing cost summary remains explicit and protected.

### Wave 2

3. Wire normal orchestrator success/skip/fail outcomes into structured persisted log records.
4. Harden exception/finally handling so thrown runs still append partial records and cleanup remains intact.

This ordering keeps persistence primitives stable before threading them through orchestration control flow.

## Verification Strategy

- Unit-test `RunLogStore` against a temporary HOME directory and assert newline-delimited JSON output.
- Verify logged records contain repo, issue, status, timestamps, and nested usage/cost fields.
- Add focused coverage for `RunCommand` usage summary output if command testing is practical; otherwise keep syntax plus manual verification steps in the execution plans.
- During execution, run a smoke path that produces a persisted log entry and inspect it with `tail -n 1 ~/.copland/logs/runs.jsonl`.

## Deferred

- `copland status` or higher-level log reading UX
- cache-aware cost fields
- multi-repo log rollups
- full orchestrator branch test matrix beyond what Phase 3 needs

---
*Phase: 03-structured-run-log*
*Research completed: 2026-04-03*

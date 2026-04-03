# Phase 3: Structured Run Log - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-03
**Phase:** 03-structured-run-log
**Areas discussed:** Log storage, Log lifecycle, Cost summary handling, Record shape

---

## Log storage

| Option | Description | Selected |
|--------|-------------|----------|
| JSON Lines under `~/.copland/logs/runs.jsonl` | Append one machine-readable record per run in a stable global path | ✓ |
| One JSON file per run | Create many timestamped files instead of a single append-only log | |
| Repo-local logs | Store logs inside each repo checkout | |

**User's choice:** `[auto] JSON Lines under \`~/.copland/logs/runs.jsonl\``
**Notes:** Recommended because the roadmap names this exact path, morning review benefits from append-only history, and Phase 1 already solved HOME resolution for global user-path storage.

---

## Log lifecycle

| Option | Description | Selected |
|--------|-------------|----------|
| Orchestrator-owned finalization write | Build log context during `RunOrchestratorService::run()` and persist it through a guaranteed finalization path | ✓ |
| Command-owned logging | Write logs from `RunCommand` after orchestration returns | |
| Ad hoc writes from each service | Let selector/planner/executor append their own lines independently | |

**User's choice:** `[auto] Orchestrator-owned finalization write`
**Notes:** Recommended because the orchestrator already owns all branch outcomes and early returns, so it is the only place that can reliably log success, skip, failure, and exception paths consistently.

---

## Cost summary handling

| Option | Description | Selected |
|--------|-------------|----------|
| Preserve existing CLI usage summary | Keep `RunCommand::renderUsage()` as the console contract and ensure phase work does not regress it | ✓ |
| Replace with new custom output | Redesign the cost summary while adding logging | |
| Log only, no CLI focus | Treat cost summary as out of scope because it already exists | |

**User's choice:** `[auto] Preserve existing CLI usage summary`
**Notes:** Recommended because OBS-02 is already substantially implemented in the current command flow, so Phase 3 should formalize and preserve that behavior rather than churn it unnecessarily.

---

## Record shape

| Option | Description | Selected |
|--------|-------------|----------|
| Outcome + metadata + step log array | Persist repo, issue, status, timestamps, usage, and the accumulated step log | ✓ |
| Minimal outcome only | Persist only repo, status, and timestamps | |
| Verbose per-tool transcript | Persist every tool payload and raw model exchange | |

**User's choice:** `[auto] Outcome + metadata + step log array`
**Notes:** Recommended because it satisfies the roadmap’s reviewability goal without dragging Phase 3 into executor transcript archival or oversized log payloads.

---

## the agent's Discretion

- Exact helper name and namespace for the JSONL append implementation
- Exact field names for partial/incomplete run markers
- Whether usage fields are flattened or nested in the JSON object, as long as they remain easy to inspect with `jq`

## Deferred Ideas

- Dedicated `copland status` command
- Cache-aware cost fields
- Multi-repo log presentation
- Per-tool transcript archival

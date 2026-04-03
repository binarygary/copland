# Phase 2: Executor Hardening - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-03
**Phase:** 02-executor-hardening
**Areas discussed:** Read limits, Truncation behavior, Write protection contract, Planner and validation wiring

---

## Read limits

| Option | Description | Selected |
|--------|-------------|----------|
| Repo-level line cap | Add a repo-configured line limit with a safe default and apply it in `read_file` | ✓ |
| Global line cap | Put the limit in global config for all repos | |
| Byte cap | Cap by bytes/chars instead of lines | |

**User's choice:** `[auto] Repo-level line cap`
**Notes:** Recommended because executor safety policies are already repo-scoped in `RepoConfig`, and the roadmap requirement is explicitly phrased in lines with default `300`.

---

## Truncation behavior

| Option | Description | Selected |
|--------|-------------|----------|
| First N lines + explicit notice | Return the start of the file and append a truncation notice visible to Claude | ✓ |
| Silent truncation | Return only the first N lines with no notice | |
| Chunking flow | Add pagination/read-next behavior in this phase | |

**User's choice:** `[auto] First N lines + explicit notice`
**Notes:** Recommended because the requirement explicitly says Claude should be able to tell the file was cut, and chunking is unnecessary scope for this hardening phase.

---

## Write protection contract

| Option | Description | Selected |
|--------|-------------|----------|
| Structured `blocked_write_paths` array | Add a machine-readable plan field enforced directly by executor policy | ✓ |
| Continue parsing `guardrails` text | Keep the current string-matching heuristic | |
| Repo-only write blocking | Rely only on repo-level `blocked_paths` and skip plan-scoped restrictions | |

**User's choice:** `[auto] Structured \`blocked_write_paths\` array`
**Notes:** Recommended because the roadmap explicitly calls for structured write protection and the current text-matching guardrail logic is the fragility being removed.

---

## Planner and validation wiring

| Option | Description | Selected |
|--------|-------------|----------|
| Carry `blocked_write_paths` end-to-end | Add the field to planner prompt, normalization, validation, artifacts, and executor use sites | ✓ |
| Executor-only fallback | Infer blocked writes inside executor without changing plan schema | |
| Validator-only enrichment | Keep planner schema unchanged and inject blocked paths later | |

**User's choice:** `[auto] Carry \`blocked_write_paths\` end-to-end`
**Notes:** Recommended because execution should enforce exactly what planning produced, and `PlanResult` is already the structured contract boundary.

---

## the agent's Discretion

- Exact accessor names for repo read-limit config and plan blocked-write paths
- Final truncation footer wording as long as it clearly signals omitted lines

## Deferred Ideas

- Read-next/chunked file browsing
- Cost-aware or token-aware truncation heuristics
- Broader executor test suites beyond the existing safety coverage

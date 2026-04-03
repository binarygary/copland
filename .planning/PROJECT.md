# Copland

## What This Is

Copland is a local PHP CLI that works through labeled GitHub issues overnight. It selects one safe issue, plans the change with Claude, executes it in an isolated git worktree, verifies the result, and opens a draft PR for review.

## Core Value

A reliable overnight agent that opens merge-ready PRs without intervention.

## Current State

- Shipped milestone `v1.0` on 2026-04-03.
- Supports retry-safe Claude calls, executor safety policies, structured `runs.jsonl` logging, prompt caching, cache-aware cost reporting, multi-repo runs, and macOS launchd setup.
- Has direct automated coverage for the Anthropic retry wrapper, executor service, and orchestrator service.
- Has onboarding docs for installation, repo policy setup, overnight automation, and morning review.

## Next Milestone Goals

- Run multiple times per night so Copland can clear more than one issue across a backlog.
- Add a stable `status` command over `~/.copland/logs/runs.jsonl`.
- Surface cache savings more directly in the visible cost summary.

## Validated Capabilities

- ✓ Claude-powered issue selection, planning, and execution pipeline — existing
- ✓ Git worktree isolation per run (no main branch contamination) — existing
- ✓ Policy enforcement (blocked paths, allowed commands, file/line limits) — existing
- ✓ Draft PR creation with GitHub integration — existing
- ✓ Per-repo `.copland.yml` config and global `~/.copland.yml` config — existing
- ✓ SIGINT handling with cost reporting — existing
- ✓ API retry/backoff so transient Anthropic failures retry with configurable backoff — validated in Phase 1 (2026-04-03)
- ✓ Executor file reads capped per repo with truncation notice — validated in Phase 2 (2026-04-03)
- ✓ Structured blocked-write protection enforced end-to-end — validated in Phase 2 (2026-04-03)
- ✓ Structured local run log under `~/.copland/logs/runs.jsonl` — validated in Phase 3 (2026-04-03)
- ✓ Cost-per-run summary surfaced in run output — validated in Phase 3 (2026-04-03)
- ✓ Prompt caching on executor loop — validated in Phase 4 (2026-04-03)
- ✓ Cache-aware cost model for cache-write and cache-read tokens — validated in Phase 5 (2026-04-03)
- ✓ Multi-repo support: single run processes configured repos sequentially — validated in Phase 6 (2026-04-03)
- ✓ `copland setup` installs a working per-user macOS LaunchAgent with explicit HOME — validated in Phase 7 (2026-04-03)
- ✓ `AnthropicApiClient` retry and backoff behavior is covered by automated tests — validated in Phase 8 (2026-04-03)
- ✓ `ClaudeExecutorService` has automated service-level coverage for tool dispatch, thrashing aborts, and blocked writes — validated in Phase 9 (2026-04-03)
- ✓ `RunOrchestratorService` has automated service-level coverage for happy path, early exits, and cleanup — validated in Phase 10 (2026-04-03)
- ✓ README and overnight setup documentation now reflect the shipped Copland workflow — validated in Phase 11 (2026-04-03)

### Out of Scope

- Hosted/server deployment — cron on local machine is the target model
- Web dashboard or UI — CLI-only
- Multi-user or team features — personal tool
- Real-time PR merging — draft PRs only, human reviews before merge

## Context

- Already built and partially working — improvements to existing code, not greenfield
- Codebase mapped: `.planning/codebase/` has full analysis of current state
- Key concerns documented in `IMPROVEMENTS.md` and `.planning/codebase/CONCERNS.md`
- Tech stack: Laravel Zero (PHP 8.2+), anthropic-ai/sdk, Pest tests, symfony/yaml + process
- GitHub auth delegated to `gh` CLI — no credentials stored in Copland
- Currently processes one issue per run; backlog cleared by running multiple times via cron

## Constraints

- **Tech stack**: PHP 8.2+ / Laravel Zero — established, not changing
- **Auth**: Must use `gh` CLI for GitHub auth — no credential storage
- **Safety**: All executor tool calls must be policy-validated before execution
- **Scope**: Max 3 files / 250 lines changed per issue — enforced by planner + verifier

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| One issue per run, cron loops for backlog | Simpler than multi-issue mode, easier to reason about failure | — Pending |
| Prompt caching on executor system prompt | Biggest single cost saving, one-line change | — Pending |
| Haiku for selector, Sonnet for planner+executor | Cost optimization already in place | ✓ Good |
| GitHub as audit log (no local DB) | No infrastructure to manage, PR/issue comments are the history | ✓ Good |
| AnthropicApiClient owns retry behavior for all Claude services | Keeps transient-failure handling consistent across selector, planner, and executor | ✓ Phase 1 |
| Executor write protection uses structured `blocked_write_paths` | Exact path checks are safer and more debuggable than parsing guardrail prose | ✓ Phase 2 |
| Run logging is orchestrator-owned and persisted as append-only JSONL | Keeps normal and partial outcomes on one local review trail without coupling logs to CLI rendering | ✓ Phase 3 |
| Executor prompt caching lives on the system prompt block | Maximizes Anthropic cache reuse across executor rounds while conversation history grows | ✓ Phase 4 |
| Cache-write and cache-read tokens must be billed separately from uncached input | Keeps reported run cost aligned with Anthropic prompt-caching pricing | ✓ Phase 5 |
| Multi-repo runs iterate configured checkout paths sequentially via `chdir()` | Reuses existing single-repo orchestration without weakening repo isolation or log attribution | ✓ Phase 6 |
| Nightly automation uses a per-user LaunchAgent with explicit HOME and PATH | Keeps macOS scheduling native and ensures Copland can resolve user-scoped config under launchd | ✓ Phase 7 |
| AnthropicApiClient retry tests use an injectable delay seam | Makes retry timing assertions deterministic without slowing tests or requiring real sleeps | ✓ Phase 8 |
| ClaudeExecutorService tests inject the system prompt instead of loading it from disk | Keeps service-level executor tests deterministic without changing production prompt behavior | ✓ Phase 9 |
| RunOrchestratorService injects artifact/log stores for service-level tests | Keeps orchestrator tests deterministic and exposed a missing executor-failure early exit | ✓ Phase 10 |
| README and overnight guide document only shipped behavior, including the unimplemented `status` command caveat | Keeps onboarding accurate and prevents docs from outrunning the product | ✓ Phase 11 |

---
*Last updated: 2026-04-03 after v1.0 milestone archival*

# Copland

## What This Is

Copland is a PHP CLI tool that automatically resolves GitHub issues overnight using Claude AI. It runs on a cron schedule, selects safe and well-defined issues from registered repos, plans and executes implementations in isolated git worktrees, and opens draft PRs for review. Built for personal use across a handful of repos.

## Core Value

A reliable overnight agent that opens merge-ready PRs without intervention.

## Requirements

### Validated

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

### Active

- [ ] Prompt caching on executor loop — cut API costs significantly
- [ ] Test coverage on ClaudeExecutorService and RunOrchestratorService
- [ ] Cron setup: run multiple times per night to clear issue backlog
- [ ] Multi-repo support: single cron entry runs all configured repos in sequence
- [ ] README updated for Copland (currently Laravel Zero boilerplate)

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

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd:transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-03 after Phase 3 completion*

# Copland

## What This Is

Copland is a local PHP CLI that works through labeled GitHub issues (or Asana tasks) overnight. It selects one safe issue, plans the change with Claude, executes it in an isolated git worktree, verifies the result, and opens a draft PR for review. Supports Anthropic, Ollama, and OpenRouter as LLM providers, switchable per stage.

## Core Value

A reliable overnight agent that opens merge-ready PRs without intervention.

## Current State

- Shipped milestone `v1.1` on 2026-04-09.
- Multi-provider LLM support: Anthropic (default), Ollama (local, via `OpenAiCompatClient`), and OpenRouter — configurable globally in `~/.copland.yml`, per repo, or per pipeline stage (selector/planner/executor).
- Asana task source: users can configure `task_source: asana` per repo; Copland fetches open Asana tasks, runs the identical code pipeline, and posts the PR link as an Asana comment.
- 132 passing tests (Pest), PHPStan level 5 clean.
- Phase numbering continues from 18 in the next milestone.

## Validated Capabilities

- ✓ Claude-powered issue selection, planning, and execution pipeline — existing
- ✓ Git worktree isolation per run (no main branch contamination) — existing
- ✓ Policy enforcement (blocked paths, allowed commands, file/line limits) — existing
- ✓ Draft PR creation with GitHub integration — existing
- ✓ Per-repo `.copland.yml` config and global `~/.copland.yml` config — existing
- ✓ SIGINT handling with cost reporting — existing
- ✓ API retry/backoff so transient Anthropic failures retry with configurable backoff — v1.0 Phase 1
- ✓ Executor file reads capped per repo with truncation notice — v1.0 Phase 2
- ✓ Structured blocked-write protection enforced end-to-end — v1.0 Phase 2
- ✓ Structured local run log under `~/.copland/logs/runs.jsonl` — v1.0 Phase 3
- ✓ Cost-per-run summary surfaced in run output — v1.0 Phase 3
- ✓ Prompt caching on executor loop — v1.0 Phase 4
- ✓ Cache-aware cost model for cache-write and cache-read tokens — v1.0 Phase 5
- ✓ Multi-repo support: single run processes configured repos sequentially — v1.0 Phase 6
- ✓ `copland setup` installs a working per-user macOS LaunchAgent with explicit HOME — v1.0 Phase 7
- ✓ `AnthropicApiClient` retry and backoff behavior covered by automated tests — v1.0 Phase 8
- ✓ `ClaudeExecutorService` has automated coverage for tool dispatch, thrashing aborts, and blocked writes — v1.0 Phase 9
- ✓ `RunOrchestratorService` has automated coverage for happy path, early exits, and cleanup — v1.0 Phase 10
- ✓ README and overnight setup docs reflect the shipped workflow — v1.0 Phase 11
- ✓ `LlmClient` interface abstracts all three Claude services from the Anthropic SDK — v1.1 Phase 14
- ✓ `OpenAiCompatClient` + `LlmClientFactory` enable Ollama and OpenRouter as drop-in backends — v1.1 Phase 15
- ✓ `TaskSource` interface decouples orchestrator from GitHub; `GitHubTaskSource` wraps existing behavior — v1.1 Phase 16
- ✓ `AsanaService` + `AsanaTaskSource` deliver Asana as a full task source with tag/section filtering and PR comment-back — v1.1 Phase 17

## Out of Scope

- Hosted/server deployment — cron on local machine is the target model
- Web dashboard or UI — CLI-only
- Multi-user or team features — personal tool
- Real-time PR merging — draft PRs only, human reviews before merge
- Provider auto-routing by complexity — adds unpredictability to overnight agent
- Asana OAuth — PAT is correct for a personal tool
- Asana task status sync from GitHub — requires webhooks, out of scope for CLI tool
- OpenRouter cost estimation — pricing varies per model/route; raw token counts reported

## Context

- Already built and partially working — improvements to existing code, not greenfield
- Codebase mapped: `.planning/codebase/` has full analysis of current state
- Tech stack: Laravel Zero (PHP 8.2+), anthropic-ai/sdk, openai-php/client, Pest tests, symfony/yaml + process
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
| One issue per run, cron loops for backlog | Simpler than multi-issue mode, easier to reason about failure | ✓ Good |
| Prompt caching on executor system prompt | Biggest single cost saving, one-line change | ✓ Good |
| Haiku for selector, Sonnet for planner+executor | Cost optimization already in place | ✓ Good |
| GitHub as audit log (no local DB) | No infrastructure to manage, PR/issue comments are the history | ✓ Good |
| AnthropicApiClient owns retry behavior for all Claude services | Keeps transient-failure handling consistent across selector, planner, and executor | ✓ v1.0 Phase 1 |
| Executor write protection uses structured `blocked_write_paths` | Exact path checks are safer and more debuggable than parsing guardrail prose | ✓ v1.0 Phase 2 |
| Run logging is orchestrator-owned and persisted as append-only JSONL | Keeps normal and partial outcomes on one local review trail without coupling logs to CLI rendering | ✓ v1.0 Phase 3 |
| Executor prompt caching lives on the system prompt block | Maximizes Anthropic cache reuse across executor rounds while conversation history grows | ✓ v1.0 Phase 4 |
| Cache-write and cache-read tokens must be billed separately from uncached input | Keeps reported run cost aligned with Anthropic prompt-caching pricing | ✓ v1.0 Phase 5 |
| Multi-repo runs iterate configured checkout paths sequentially via `chdir()` | Reuses existing single-repo orchestration without weakening repo isolation or log attribution | ✓ v1.0 Phase 6 |
| Nightly automation uses a per-user LaunchAgent with explicit HOME and PATH | Keeps macOS scheduling native and ensures Copland can resolve user-scoped config under launchd | ✓ v1.0 Phase 7 |
| AnthropicApiClient retry tests use an injectable delay seam | Makes retry timing assertions deterministic without slowing tests or requiring real sleeps | ✓ v1.0 Phase 8 |
| ClaudeExecutorService tests inject the system prompt instead of loading it from disk | Keeps service-level executor tests deterministic without changing production prompt behavior | ✓ v1.0 Phase 9 |
| RunOrchestratorService injects artifact/log stores for service-level tests | Keeps orchestrator tests deterministic and exposed a missing executor-failure early exit | ✓ v1.0 Phase 10 |
| README and overnight guide document only shipped behavior | Keeps onboarding accurate and prevents docs from outrunning the product | ✓ v1.0 Phase 11 |
| LlmClient interface isolates three Claude services from AnthropicApiClient | Enables provider swapping without touching service internals; `messages()` kept public for backward test compatibility | ✓ v1.1 Phase 14 |
| `openai-php/client` covers both Ollama and OpenRouter behind one `OpenAiCompatClient` | No second HTTP client package needed; tool schema translated Anthropic→OpenAI on the way out | ✓ v1.1 Phase 15 |
| Anthropic `cache_control` blocks stripped before sending to non-Anthropic providers | Prevents provider errors; normalize `stopReason` (`end_turn`→`stop`) for executor loop safety | ✓ v1.1 Phase 15 |
| D-05 resolution order: repo stage → global stage → repo default → global default → anthropic | Gives maximum per-stage control while keeping fallback to Anthropic for unconfigured repos | ✓ v1.1 Phase 15 |
| TaskSource interface uses `string\|int $taskId` for Asana GID compatibility | Avoids PHP int truncation of 64-bit Asana GIDs while preserving GitHub integer issue numbers | ✓ v1.1 Phase 16 |
| Asana GIDs handled as strings throughout pipeline | `SelectionResult`, `RunResult`, `RunProgressSnapshot` all declare `string\|int\|null $selectedTaskId` | ✓ v1.1 Phase 17 |
| configuredRepos() unchanged — Asana keys accessed separately via slug-based getters | Preserves existing repo normalization contract; zero risk to GitHub repos | ✓ v1.1 Phase 17 |

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
*Last updated: 2026-04-09 — v1.1 milestone complete*

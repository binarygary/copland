# Roadmap: Copland

## Milestones

- ✅ **v1.0 Overnight Hardening** — Phases 1-13 shipped 2026-04-03 ([roadmap archive](/Users/binarygary/projects/binarygary/copland/.planning/milestones/v1.0-ROADMAP.md), [requirements archive](/Users/binarygary/projects/binarygary/copland/.planning/milestones/v1.0-REQUIREMENTS.md), [audit](/Users/binarygary/projects/binarygary/copland/.planning/milestones/v1.0-MILESTONE-AUDIT.md))
- 🔄 **v1.1 Multi-Provider & Asana Integration** — Phases 14-17 (active)

---

## v1.1 Multi-Provider & Asana Integration

### Phases

- [x] **Phase 14: LlmClient Contracts** — Introduce `LlmClient` interface, normalize `AnthropicApiClient` to implement it; no behavior change (completed 2026-04-08)
- [x] **Phase 15: Provider Implementations** — `OpenAiCompatClient`, factory, and config wiring for Ollama and OpenRouter (completed 2026-04-08)
- [x] **Phase 16: TaskSource Extraction** — Extract `TaskSource` interface, refactor `GitHubService` into `GitHubTaskSource`; structural refactor, no behavior change (completed 2026-04-08)
- [ ] **Phase 17: Asana Integration** — `AsanaService`, `AsanaTaskSource`, config mapping, and PR link comment-back

### Phase Details

#### Phase 14: LlmClient Contracts
**Goal**: Copland's three Claude services depend on a `LlmClient` interface, not `AnthropicApiClient` directly, and all existing tests pass unchanged
**Depends on**: Nothing (first phase of milestone)
**Requirements**: PROV-01, PROV-02
**Success Criteria** (what must be TRUE):
  1. `LlmClient` interface exists with `complete()` method accepting normalized tool and message parameters; `LlmResponse` and `LlmUsage` value objects exist
  2. `AnthropicApiClient` implements `LlmClient` — all existing prompt caching and retry behavior is preserved
  3. `ClaudeSelectorService`, `ClaudePlannerService`, and `ClaudeExecutorService` accept `LlmClient` in their constructors (not the concrete class)
  4. All existing tests pass green with no modifications
**Plans**: TBD

#### Phase 15: Provider Implementations
**Goal**: Users can configure Ollama or OpenRouter as their LLM provider (globally or per stage) and Copland routes requests through the correct client at runtime
**Depends on**: Phase 14
**Requirements**: PROV-03, PROV-04, PROV-05, OLLAMA-01, OLLAMA-02, OLLAMA-03, OPENR-01, OPENR-02
**Success Criteria** (what must be TRUE):
  1. `~/.copland.yml` accepts a `llm.default` key; `.copland.yml` (per repo) accepts `llm.override`; user can also set provider independently for `selector`, `planner`, and `executor` stages
  2. `OpenAiCompatClient` handles both Ollama (base URL + model name) and OpenRouter (API key + model name) behind the same `LlmClient` interface; tool schema is translated from Anthropic format (`input_schema`) to OpenAI format (`parameters`) on the way out
  3. Copland probes Ollama reachability (`GET /api/tags`) before entering the orchestration loop and exits with a clear error message if Ollama is unreachable
  4. Copland prints a startup warning when the configured Ollama model is not on the known tool-capable model list
  5. OpenRouter requests include `HTTP-Referer` and `X-Title` attribution headers; Anthropic `cache_control` blocks are stripped before sending to non-Anthropic providers
**Plans**: 3 plans
Plans:
- [ ] 15-01-PLAN.md — Install openai-php/client, LlmResponseNormalizer, ToolSchemaTranslator, config getters, stopReason normalization
- [ ] 15-02-PLAN.md — OpenAiCompatClient, LlmClientFactory, AppServiceProvider wiring
- [ ] 15-03-PLAN.md — RunCommand factory wiring, Ollama probe, model capability warning

#### Phase 16: TaskSource Extraction
**Goal**: `RunOrchestratorService` fetches tasks through a `TaskSource` interface rather than calling `GitHubService` directly; existing GitHub behavior and all tests are unchanged
**Depends on**: Phase 15
**Requirements**: (none — structural refactor enabling Phase 17)
**Success Criteria** (what must be TRUE):
  1. `TaskSource` interface exists with methods covering task fetch, task comment, and any failure comment call sites in `RunOrchestratorService`
  2. `GitHubTaskSource` wraps the existing `GitHubService` and implements `TaskSource` — no functional change to GitHub issue behavior
  3. `RunOrchestratorService` is injected with a `TaskSource`; it no longer references `GitHubService` directly
  4. All existing orchestrator and GitHub service tests pass green with no modifications
**Plans**: 3 plans
Plans:
- [x] 16-01-PLAN.md — Create TaskSource interface and GitHubTaskSource delegation wrapper
- [x] 16-02-PLAN.md — Wire TaskSource into RunOrchestratorService and AppServiceProvider
- [x] 16-03-PLAN.md — Update RunOrchestratorServiceTest, create GitHubTaskSourceTest

#### Phase 17: Asana Integration
**Goal**: Users can configure Asana projects as a task source per repo; Copland fetches open Asana tasks, runs the same code pipeline, and posts the resulting PR link back as an Asana comment
**Depends on**: Phase 16
**Requirements**: ASANA-01, ASANA-02, ASANA-03, ASANA-04, ASANA-05
**Success Criteria** (what must be TRUE):
  1. `~/.copland.yml` accepts an `asana` block mapping project GIDs to repo paths; user can specify tag or section filters per project; user can set `task_source: asana` per repo in `.copland.yml`
  2. Copland fetches open tasks from a configured Asana project using the Asana REST API (PAT auth via `~/.copland.yml`) and passes them through the same selector pipeline used for GitHub Issues
  3. When a GitHub PR is opened for an Asana-sourced task, Copland posts a comment to the Asana task containing the PR URL
  4. Asana task GIDs are handled as strings throughout the pipeline without type errors or silent truncation
  5. When no Asana tasks are available (empty project or all filtered out), Copland exits cleanly with an informative message rather than erroring
**Plans**: TBD

### Progress Table

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 14. LlmClient Contracts | 1/1 | Complete   | 2026-04-08 |
| 15. Provider Implementations | 0/3 | Complete    | 2026-04-08 |
| 16. TaskSource Extraction | 3/3 | Complete   | 2026-04-08 |
| 17. Asana Integration | 0/? | Not started | - |

---

## Current Status

- Active milestone: v1.1 Multi-Provider & Asana Integration (Phases 14-17)
- Next step: `/gsd:discuss-phase 14`
- Phase numbering continues from 17 in the next milestone.

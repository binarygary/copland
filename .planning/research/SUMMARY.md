# Research Summary: v1.1 Multi-Provider LLM + Asana Integration

**Project:** Copland v1.1
**Synthesized:** 2026-04-08
**Confidence:** HIGH (architecture from direct code analysis; API surfaces MEDIUM — verify at implementation)

---

## Stack Additions

**One new Composer package:**

| Package | Constraint | Purpose |
|---------|-----------|---------|
| `openai-php/client` | `^0.10` | Ollama + OpenRouter (both OpenAI-compat, one package) |

GuzzleHttp for Asana is already present transitively — no additional package needed.

Do NOT replace `anthropic-ai/sdk` — Anthropic's tools/caching API is not in the OpenAI spec; existing prompt caching would break.

---

## Feature Table Stakes

**LLM Provider Abstraction:**
- `LlmClient` interface with normalized `LlmResponse` / `LlmUsage` value objects
- `AnthropicApiClient` wraps the existing SDK, implements the interface
- `OpenAiCompatClient` wraps `openai-php/client`, implements the same interface (covers both Ollama + OpenRouter)
- Prompt caching (`cache_control`) stripped when routing to non-Anthropic providers
- Tool schema translated: Anthropic (`input_schema`) → OpenAI (`parameters`)
- Cost reporting: $0 for Ollama (local), "n/a" for OpenRouter (pricing varies per model)

**Ollama:**
- Configured via base URL `http://localhost:11434/v1` + model name in `~/.copland.yml`
- Reachability probe before orchestration loop (offline at 2am = silent overnight failure)
- Only tool-capable models work with executor phase — document `qwen2.5-coder`, `llama3.1+` etc.

**OpenRouter:**
- Configured via `https://openrouter.ai/api/v1` + API key in `~/.copland.yml`
- Same `OpenAiCompatClient` as Ollama, different base URL + auth
- Optional attribution headers (`HTTP-Referer`, `X-Title`)

**Asana Task Source:**
- Asana project → repo mapping in `~/.copland.yml` (global config)
- Fetch open tasks from configured Asana project(s) via GuzzleHttp
- Identical pipeline: selection → planning → worktree execution → GitHub PR
- After PR opened: POST story/comment to Asana task with PR link
- After task sent to executor: mark task "In Progress" (or remove ready tag) to prevent re-selection on next run

---

## Architecture Approach

**Two clean seams introduced:**

1. `LlmClient` interface — replaces direct `AnthropicApiClient` coupling in the three service constructors. All new providers slot in behind it without touching `ClaudeSelectorService`, `ClaudePlannerService`, or `ClaudeExecutorService` logic.

2. `TaskSource` interface — replaces direct GitHub Issue fetching in `RunOrchestratorService`. Three call sites change: `getIssues()` (Step 1), `commentOnIssue()` for failures, `commentOnIssue()` for success.

**New components (11):**
- Contracts: `LlmClient`, `LlmResponse`, `LlmUsage`, `TaskSource`
- Implementations: `OpenAiCompatClient`, `RetryingLlmClient`, `LlmClientFactory`
- Asana: `AsanaService`, `AsanaTaskSource`, `TaskSourceFactory`
- GitHub adapter: `GitHubTaskSource` (wraps existing `GitHubService`)

**Modified components (11):**
`AnthropicApiClient`, `ClaudeExecutorService`, `ClaudeSelectorService`, `ClaudePlannerService`, `RunOrchestratorService`, `IssuePrefilterService`, `GlobalConfig`, `RepoConfig`, `RunCommand`, `PlanCommand`, `AnthropicCostEstimator`

**Unchanged (0 changes needed):**
`app/Data/`, `ExecutorPolicy`, `ExecutorRunState`, `GitService`, `WorkspaceService`, `VerificationService`, `PlanValidatorService`

**Config backward compatibility:** `GlobalConfig` reads `llm.anthropic_api_key` first, falls back to existing top-level `claude_api_key` — no migration required for current users.

---

## Critical Pitfalls

**Must address before any provider ships:**

1. **`stopReason` mismatch** — Executor loop checks `stopReason === 'end_turn'` (Anthropic). OpenAI-compat returns `stop`. Every Ollama/OpenRouter run exhausts `maxRounds` and returns `success: false`. Fix: normalize in `LlmResponse`.

2. **Tool schema format** — `buildTools()` uses `input_schema` (Anthropic format). OpenAI-compat expects `parameters`. Tools are silently ignored. Fix: `OpenAiCompatClient` translates schema on the way out.

3. **`success: true` with zero git diff** — Three new failure modes (tool-use-unsupported model, OpenRouter error in 200 body, Ollama offline with bad retry) all surface as `success: true` + no changes. Add a post-execution diff check: empty diff = failure.

**Must address before Ollama ships:**

4. **Ollama not running at 2am** — Ollama is a macOS application, not a system daemon. Add a connectivity probe (`GET /api/tags`) before the orchestration loop; fail fast with a clear error rather than producing a zero-diff "success".

5. **Ollama tool-use capability** — Most Ollama models don't support reliable function calling. The executor is entirely tool-use-dependent. A model that ignores tools returns `success: true` with empty diff. Document supported models; add a startup warning for non-recommended models.

**Must address before Asana ships:**

6. **Asana task GIDs are strings, not ints** — `SelectionResult.selectedIssueNumber` is typed `?int`. Asana GIDs are 16-digit strings. Will cause type error or silent truncation. Fix: introduce `AgentTask` value object or widen the type.

7. **Re-selection on next run** — Without marking a task "in progress" after selection, the same task gets picked every overnight run. Fix: remove ready tag or move to "In Review" section immediately after selection.

---

## Build Order Recommendation

| Phase | Name | Rationale |
|-------|------|-----------|
| 14 | LlmClient contracts + Anthropic normalization | Pure refactor, no behavior change. All existing tests must pass. Prerequisite for everything. |
| 15 | OpenAiCompatClient + factory + config | Ollama + OpenRouter behind the new interface. Test with local Ollama and real OpenRouter key. |
| 16 | TaskSource extraction (GitHub refactor) | Pure structural refactor. Existing orchestrator tests pass unchanged. Prerequisite for Asana. |
| 17 | AsanaService + AsanaTaskSource | Additive feature on top of stable TaskSource interface. Requires real Asana workspace for integration test. |
| 18 | Overnight guards + zero-diff detection | Safety net for new failure modes that surface as false success. |

**Do not combine Phases 14 and 15** — separating the interface from the implementation makes each individually reviewable and reduces risk of breaking existing Anthropic behavior.

---

## Open Questions

Verify before or during implementation:

1. **Asana task body field name:** `notes` vs `description` in `opt_fields` — check current Asana API v1 docs.
2. **OpenRouter `cache_control` behavior:** Does sending Anthropic cache_control blocks to OpenRouter error or silently ignore? Determines whether the adapter must strip them.
3. **`openai-php/client` exact version:** Confirm current stable on Packagist before locking `^0.10`.
4. **Ollama tool-capable model list:** Verify current list at `ollama.com/search?c=tools` before writing capability docs.
5. **OpenRouter model capability flag:** Whether `/api/v1/models` includes a `tools` flag — useful for a startup capability probe.

# Milestones

## v1.1 Multi-Provider & Asana Integration (Shipped: 2026-04-09)

**Phases completed:** 4 phases, 12 plans, 13 tasks
**Timeline:** 2026-04-08 to 2026-04-09
**Archives:** [ROADMAP](milestones/v1.1-ROADMAP.md), [REQUIREMENTS](milestones/v1.1-REQUIREMENTS.md)

**Key accomplishments:**

- `LlmClient` interface + `LlmResponse`/`LlmUsage`/`SystemBlock` value objects decouple the three Claude services from the Anthropic SDK; `AnthropicApiClient` implements it with full prompt caching preserved
- `LlmResponseNormalizer` (end_turn→stop, tool_use→tool_calls), `ToolSchemaTranslator` (Anthropic→OpenAI schema), and `llmConfig()` getters establish canonical provider contracts
- `OpenAiCompatClient` + `LlmClientFactory` enable Ollama and OpenRouter as drop-in backends with D-05 per-stage resolution (repo stage → global stage → repo default → global default → anthropic)
- `RunCommand` wires per-stage factory calls, Ollama reachability probe at `/api/tags`, and model capability warning for non-tool-capable models
- `TaskSource` interface + `GitHubTaskSource` delegation wrapper decouple the orchestrator from the GitHub API, enabling pluggable task sources with zero behavioral change
- `AsanaService`, `AsanaTaskSource`, and config integration deliver Asana as a full task source — fetching open tasks via PAT auth, filtering by tag/section, and posting PR link as comment-back

---

## v1.0 Copland Overnight Hardening (Shipped: 2026-04-03)

**Phases completed:** 13 phases, 23 plans, 45 tasks
**Timeline:** 2026-04-02 to 2026-04-03
**Archives:** [ROADMAP](/Users/binarygary/projects/binarygary/copland/.planning/milestones/v1.0-ROADMAP.md), [REQUIREMENTS](/Users/binarygary/projects/binarygary/copland/.planning/milestones/v1.0-REQUIREMENTS.md), [AUDIT](/Users/binarygary/projects/binarygary/copland/.planning/milestones/v1.0-MILESTONE-AUDIT.md)

**Key accomplishments:**

- Added a shared Anthropic retry/backoff wrapper across selector, planner, and executor flows.
- Hardened executor reads and writes with line caps, structured blocked paths, and validated plan artifacts.
- Added append-only `~/.copland/logs/runs.jsonl` logging with cost reporting and crash-path persistence.
- Added prompt caching plus a cache-aware cost model so executor runs expose real cache savings.
- Added multi-repo execution and a macOS LaunchAgent installer for unattended overnight runs.
- Added direct regression coverage for the retry wrapper, executor, and orchestrator, then backfilled milestone verification to a passing audit.

---

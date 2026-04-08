# Requirements: v1.1 Multi-Provider & Asana Integration

**Milestone:** v1.1
**Status:** Active
**Last updated:** 2026-04-08

---

## Active Requirements

### LLM Provider Abstraction (PROV)

- [ ] **PROV-01**: Copland has a `LlmClient` interface with normalized `LlmResponse` and `LlmUsage` value objects
- [ ] **PROV-02**: `AnthropicApiClient` implements `LlmClient` — existing behavior and prompt caching unchanged
- [ ] **PROV-03**: User can set the default LLM provider in `~/.copland.yml`
- [ ] **PROV-04**: User can override the LLM provider per repo in `.copland.yml`
- [ ] **PROV-05**: User can configure different providers for selector, planner, and executor stages independently

### Ollama (OLLAMA)

- [ ] **OLLAMA-01**: User can configure Ollama as a provider with base URL and model name
- [ ] **OLLAMA-02**: Copland probes Ollama reachability before starting the orchestration loop and fails fast with a clear error if unreachable
- [ ] **OLLAMA-03**: Copland warns at startup if the configured Ollama model is known to have poor tool-use support

### OpenRouter (OPENR)

- [ ] **OPENR-01**: User can configure OpenRouter as a provider with API key and model name
- [ ] **OPENR-02**: Copland sends attribution headers (`HTTP-Referer`, `X-Title`) on OpenRouter requests

### Asana (ASANA)

- [ ] **ASANA-01**: User can map Asana projects to repos in `~/.copland.yml`
- [ ] **ASANA-02**: Copland fetches open tasks from a configured Asana project (same selection pipeline as GitHub Issues)
- [ ] **ASANA-03**: User can filter which Asana tasks Copland picks up by tag or section name (configured in `~/.copland.yml`)
- [ ] **ASANA-04**: Copland adds a comment to the Asana task with the GitHub PR link when a PR is opened
- [ ] **ASANA-05**: User can configure Asana as the task source per repo (alongside GitHub Issues)

---

## Future Requirements

- Mark Asana task "In Progress" immediately after selection to prevent re-selection on consecutive overnight runs *(deferred — re-selection risk, add in v1.2)*
- Provider health check in `copland doctor` command showing connectivity status per configured provider
- Per-stage model cost reporting (show which stage used which provider/model)

---

## Out of Scope

- **Provider auto-routing by complexity** — automatic model selection based on task difficulty; adds unpredictability to overnight agent
- **Asana OAuth** — PAT is correct for a personal tool; OAuth adds unnecessary complexity
- **Asana task status sync from GitHub** — syncing PR state back to Asana requires webhooks; out of scope for CLI tool
- **OpenRouter cost estimation** — pricing varies per model/route and is not statically knowable; raw token counts reported, cost marked "n/a"

---

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| PROV-01 | Phase 14 | Pending |
| PROV-02 | Phase 14 | Pending |
| PROV-03 | Phase 15 | Pending |
| PROV-04 | Phase 15 | Pending |
| PROV-05 | Phase 15 | Pending |
| OLLAMA-01 | Phase 15 | Pending |
| OLLAMA-02 | Phase 15 | Pending |
| OLLAMA-03 | Phase 15 | Pending |
| OPENR-01 | Phase 15 | Pending |
| OPENR-02 | Phase 15 | Pending |
| ASANA-01 | Phase 17 | Pending |
| ASANA-02 | Phase 17 | Pending |
| ASANA-03 | Phase 17 | Pending |
| ASANA-04 | Phase 17 | Pending |
| ASANA-05 | Phase 17 | Pending |

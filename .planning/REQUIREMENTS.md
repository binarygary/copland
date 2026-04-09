# Requirements — v1.2 Onboarding

**Milestone:** v1.2 Onboarding
**Goal:** A guided init experience that takes a new user from zero to a configured, running Copland setup without reading the docs.

---

## v1 Requirements

### Init Command

- [ ] **INIT-01**: User can run `copland init` to start an interactive setup wizard
- [ ] **INIT-02**: User is prompted to choose their LLM provider (Anthropic / Ollama / OpenRouter) with Anthropic as the default
- [ ] **INIT-03**: User is prompted for their API key (Anthropic/OpenRouter) or base URL (Ollama) for the chosen provider, and the value is written to `~/.copland.yml`
- [ ] **INIT-04**: User is prompted to register at least one repo (slug + local path) during init
- [ ] **INIT-05**: Init validates `gh auth token` succeeds before finishing
- [ ] **INIT-06**: Init validates the configured LLM provider is reachable (test call) before finishing
- [ ] **INIT-07**: At the end of init, user is offered the option to run `copland automate` to install the scheduler

### Automate Command

- [x] **AUTO-01**: User can run `copland automate` to install the macOS LaunchAgent (current `copland setup` behavior)
- [x] **AUTO-02**: Running `copland setup` shows a deprecation notice and delegates to `copland automate`

---

## Future Requirements

- Per-stage LLM provider configuration during init (selector/planner/executor)
- Linux systemd service installer via `copland automate`
- Multi-repo registration flow (add more than one repo during init)

## Out of Scope

- GUI or web-based setup wizard — CLI only
- Auto-detection of repos from git remotes — explicit registration only
- Windows support — macOS/Linux only

---

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| INIT-01 | Phase 19 | Pending |
| INIT-02 | Phase 19 | Pending |
| INIT-03 | Phase 19 | Pending |
| INIT-04 | Phase 19 | Pending |
| INIT-05 | Phase 19 | Pending |
| INIT-06 | Phase 19 | Pending |
| INIT-07 | Phase 19 | Pending |
| AUTO-01 | Phase 18 | Satisfied |
| AUTO-02 | Phase 18 | Satisfied |

# Roadmap: Copland

## Milestones

- ✅ **v1.0 Overnight Hardening** — Phases 1-13 shipped 2026-04-03 ([archive](milestones/v1.0-ROADMAP.md))
- ✅ **v1.1 Multi-Provider & Asana Integration** — Phases 14-17 shipped 2026-04-09 ([archive](milestones/v1.1-ROADMAP.md))
- 🚧 **v1.2 Onboarding** — Phases 18-19 (in progress)

---

## Shipped Phases

<details>
<summary>✅ v1.0 Overnight Hardening (Phases 1-13) — SHIPPED 2026-04-03</summary>

- [x] Phase 1: API Retry Backoff — completed 2026-04-03
- [x] Phase 2: Executor Hardening — completed 2026-04-03
- [x] Phase 3: Structured Run Log — completed 2026-04-03
- [x] Phase 4: Prompt Caching — completed 2026-04-03
- [x] Phase 5: Cache-Aware Cost Model — completed 2026-04-03
- [x] Phase 6: Multi-Repo Runner — completed 2026-04-03
- [x] Phase 7: Launchd Setup — completed 2026-04-03
- [x] Phase 8: Retry Wrapper Tests — completed 2026-04-03
- [x] Phase 9: Executor Tests — completed 2026-04-03
- [x] Phase 10: Orchestrator Tests — completed 2026-04-03
- [x] Phase 11: Documentation — completed 2026-04-03
- [x] Phase 12: Multi-Repo Failure Logging — completed 2026-04-03
- [x] Phase 13: Verification Backfill — completed 2026-04-03

</details>

<details>
<summary>✅ v1.1 Multi-Provider & Asana Integration (Phases 14-17) — SHIPPED 2026-04-09</summary>

- [x] Phase 14: LlmClient Contracts — completed 2026-04-08
- [x] Phase 15: Provider Implementations — completed 2026-04-08
- [x] Phase 16: TaskSource Extraction — completed 2026-04-08
- [x] Phase 17: Asana Integration — completed 2026-04-08

</details>

---

## v1.2 Onboarding

### Phases

- [ ] **Phase 18: Automate Command** — Rename `copland setup` to `copland automate`; keep `setup` as a deprecated alias
- [ ] **Phase 19: Init Wizard** — Interactive `copland init` command guiding users from zero to a configured, running setup

### Phase Details

### Phase 18: Automate Command
**Goal**: Users can run `copland automate` to install the macOS LaunchAgent; users running the old `copland setup` command are informed of the rename and the command still works
**Depends on**: Nothing (first phase of milestone)
**Requirements**: AUTO-01, AUTO-02
**Success Criteria** (what must be TRUE):
  1. `copland automate` installs the macOS LaunchAgent with identical behavior to the current `copland setup`
  2. `copland setup` prints a clear deprecation notice ("setup has been renamed to automate") then delegates to `copland automate` and completes successfully
  3. `copland setup` is hidden from `copland --help` via `$hidden = true`; `copland automate` is the visible primary command
**Plans**: 1 plan

Plans:
- [ ] 18-01-PLAN.md — Create AutomateCommand (full logic) + rewrite SetupCommand as hidden deprecated wrapper; update tests

### Phase 19: Init Wizard
**Goal**: A new user can run `copland init` and be guided through every configuration step interactively, ending with a verified, working Copland setup — no documentation required
**Depends on**: Phase 18
**Requirements**: INIT-01, INIT-02, INIT-03, INIT-04, INIT-05, INIT-06, INIT-07
**Success Criteria** (what must be TRUE):
  1. `copland init` starts an interactive wizard using Laravel Prompts; user is prompted for LLM provider choice (Anthropic / Ollama / OpenRouter) with Anthropic as the default
  2. User is prompted for the credential appropriate to their provider (API key for Anthropic/OpenRouter, base URL for Ollama) and the value is written to `~/.copland.yml`
  3. User is prompted to register at least one repo by GitHub slug and local checkout path; the repo entry is written to `~/.copland.yml`
  4. Init validates that `gh auth token` succeeds and exits with a clear error message if it does not
  5. Init makes a test call to the configured LLM provider and exits with a clear error message if the provider is unreachable or the credential is invalid
  6. At the end of a successful init, user is offered the option to run `copland automate` immediately to install the scheduler; choosing yes installs it, choosing no exits cleanly
**Plans**: TBD
**UI hint**: yes

### Progress Table

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 18. Automate Command | 0/1 | Not started | - |
| 19. Init Wizard | 0/TBD | Not started | - |

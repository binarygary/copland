# Roadmap: Copland

## Overview

Copland already resolves GitHub issues overnight — the pipeline exists and works. This milestone hardens it for unattended use: API errors no longer kill runs, large file reads no longer balloon costs, every morning there is a reviewable log, prompt caching cuts executor costs by ~89%, multiple repos run from a single cron entry via launchd, the riskiest services gain test coverage, and the README reflects what the tool actually does. Eleven focused phases, each delivering one complete and verifiable capability.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: API Retry/Backoff** - Introduce AnthropicApiClient wrapper so transient 429/5xx errors no longer kill overnight runs
- [ ] **Phase 2: Executor Hardening** - Cap file reads and enforce structured write protection so context stays bounded and guardrails are reliable
- [ ] **Phase 3: Structured Run Log** - Persist a JSON Lines run log and surface cost in CLI output so every morning is reviewable
- [ ] **Phase 4: Prompt Caching** - Add cache_control to executor system prompt so rounds 2-12 pay ~10% of normal system-prompt input cost
- [ ] **Phase 5: Cache-Aware Cost Model** - Update ModelUsage and AnthropicCostEstimator to track cache-write/read tokens at correct rates
- [ ] **Phase 6: Multi-Repo Runner** - Add repos: list to global config and make copland run iterate all repos sequentially
- [ ] **Phase 7: Launchd Setup** - Add copland setup command that installs a macOS launchd plist for nightly automation
- [ ] **Phase 8: Retry Wrapper Tests** - Pest tests for AnthropicApiClient covering retry logic, backoff, and non-retryable errors
- [ ] **Phase 9: Executor Tests** - Pest tests for ClaudeExecutorService covering tool dispatch, thrashing, and policy violations
- [ ] **Phase 10: Orchestrator Tests** - Pest tests for RunOrchestratorService covering all 8 pipeline steps and early-exit paths
- [ ] **Phase 11: Documentation** - Rewrite README for Copland and add overnight setup guide

## Phase Details

### Phase 1: API Retry/Backoff
**Goal**: Overnight runs survive transient Anthropic API errors without losing selector and planner work
**Depends on**: Nothing (first phase)
**Requirements**: RELY-01
**Success Criteria** (what must be TRUE):
  1. A simulated 429 response during an executor round causes the round to retry with backoff rather than failing the entire run
  2. A 5xx network error is retried up to 3 times before a run is declared failed
  3. A 400 or 401 error is NOT retried — the run fails immediately with a clear reason
  4. Retry attempt count and delay are configurable in ~/.copland.yml
**Plans**: TBD

### Phase 2: Executor Hardening
**Goal**: File reads are bounded and write protection is enforced by structured config, not fragile text parsing
**Depends on**: Nothing (independent hardening)
**Requirements**: RELY-02, RELY-03
**Success Criteria** (what must be TRUE):
  1. Reading a file longer than the configured limit returns the first N lines plus a truncation notice — Claude can see the file was cut
  2. A read of a 2000-line file does not send all 2000 lines into conversation history on every subsequent round
  3. Write protection is enforced against an explicit blocked_write_paths array in the plan, not against free-text guardrail strings
  4. A write to a blocked path is rejected even if the guardrail text would not have matched the old heuristic
**Plans**: TBD

### Phase 3: Structured Run Log
**Goal**: Every run appends a machine-readable event log and displays a cost summary so the morning review requires no GitHub login
**Depends on**: Phase 1 (HOME fix prerequisite for log path resolution)
**Requirements**: OBS-01, OBS-02
**Success Criteria** (what must be TRUE):
  1. After any run (success or failure), ~/.copland/logs/runs.jsonl contains a new entry with repo, issue, status, and timestamps
  2. A mid-run crash still produces a partial log entry — the file is not empty the next morning
  3. The CLI output at run completion shows a cost summary line with selector, planner, and executor token counts and estimated USD
  4. Running tail on the log file and piping to jq shows a structured, readable record of what happened
**Plans**: TBD

### Phase 4: Prompt Caching
**Goal**: Executor system prompt is cached across all 12 rounds so rounds 2-12 pay ~10% of normal system-prompt input cost
**Depends on**: Phase 1 (AnthropicApiClient must exist for this to integrate cleanly)
**Requirements**: COST-01
**Success Criteria** (what must be TRUE):
  1. An executor run of 12 rounds shows cache_creation_input_tokens on round 1 and cache_read_input_tokens on rounds 2-12
  2. The system prompt is sent as an array with cache_control: {type: ephemeral} rather than a bare string
  3. Placing the cache marker on the system prompt (not on messages) means the cache is not invalidated as conversation history grows
**Plans**: TBD

### Phase 5: Cache-Aware Cost Model
**Goal**: Reported per-run costs accurately reflect cache-write and cache-read token rates, not a flat input token rate
**Depends on**: Phase 4 (caching must exist before the cost model has cache tokens to track)
**Requirements**: COST-02
**Success Criteria** (what must be TRUE):
  1. ModelUsage tracks cacheWriteTokens (billed at 1.25x) and cacheReadTokens (billed at 0.1x) as separate fields
  2. AnthropicCostEstimator produces a lower estimated cost on a cached run than on the same run without caching
  3. The cost summary in CLI output reflects the three-way split (uncached input, cache write, cache read) rather than a single input token line
**Plans**: TBD

### Phase 6: Multi-Repo Runner
**Goal**: A single copland run invocation processes all configured repos sequentially, with one repo failure not stopping the others
**Depends on**: Phase 1 (reliable per-repo runs are a prerequisite), Phase 3 (log must exist to disambiguate per-repo entries)
**Requirements**: SCHED-01, SCHED-02
**Success Criteria** (what must be TRUE):
  1. ~/.copland.yml accepts a repos: list of repo slugs
  2. copland run with no argument iterates every repo in the list and attempts a run for each
  3. If one repo fails (API error, no issues, etc.), the runner logs the failure and continues to the next repo
  4. The run log contains separate entries for each repo, identifiable by the repo field
**Plans**: TBD

### Phase 7: Launchd Setup
**Goal**: copland setup installs a working macOS launchd plist so nightly automation requires no manual cron configuration
**Depends on**: Phase 6 (multi-repo runner must work before automating it overnight)
**Requirements**: SCHED-03
**Success Criteria** (what must be TRUE):
  1. Running copland setup creates a launchd plist file at the correct path with HOME set explicitly
  2. The plist uses StartCalendarInterval so it runs nightly at a configurable time
  3. A run launched by launchd (with no shell environment) resolves ~/.copland.yml correctly via the explicit HOME env var in the plist
  4. Running launchctl load on the installed plist registers the job without errors
**Plans**: TBD
**UI hint**: no

### Phase 8: Retry Wrapper Tests
**Goal**: AnthropicApiClient retry behavior is verified by automated tests so changes to retry logic cannot regress silently
**Depends on**: Phase 1 (tests cover the component built there)
**Requirements**: TEST-03
**Success Criteria** (what must be TRUE):
  1. A Pest test confirms that a 429 response triggers retry with exponential backoff up to the configured attempt count
  2. A Pest test confirms that a 5xx response is retried and a 4xx (non-429) is not
  3. A Pest test confirms that backoff timing respects the configured base delay
  4. All retry tests pass without making real HTTP requests
**Plans**: TBD

### Phase 9: Executor Tests
**Goal**: ClaudeExecutorService tool dispatch and abort conditions are verified so the highest-risk component has a safety net
**Depends on**: Phase 2 (hardened executor is the stable interface to test against)
**Requirements**: TEST-01
**Success Criteria** (what must be TRUE):
  1. A Pest test covers tool dispatch: a mock response sequence triggers the correct tool handler and returns the expected result
  2. A Pest test confirms the thrashing abort fires after the configured threshold of unproductive rounds
  3. A Pest test confirms a policy violation on a write call is caught and returned as a failed execution result
  4. Tests use a mock response factory capable of producing multi-round sequences without real API calls
**Plans**: TBD

### Phase 10: Orchestrator Tests
**Goal**: RunOrchestratorService pipeline coverage means all 8 steps and every early-exit path are exercised by tests
**Depends on**: Phase 3 (RunLogger threading must be stable before testing the orchestrator end-to-end)
**Requirements**: TEST-02
**Success Criteria** (what must be TRUE):
  1. A Pest test covers the happy path: selector picks issue, planner succeeds, executor succeeds, PR is opened
  2. Pest tests cover all early-exit paths: selector skip, planner decline, validation fail, executor fail, verification fail
  3. A Pest test confirms the worktree cleanup in the finally block runs even when executor throws
  4. All services injected into the orchestrator are mocked — no filesystem, git, or API calls in tests
**Plans**: TBD

### Phase 11: Documentation
**Goal**: README and overnight setup guide reflect the actual tool so any developer can install, configure, and automate Copland from scratch
**Depends on**: Phases 1-10 (docs are written after all behavior is final)
**Requirements**: DOCS-01, DOCS-02
**Success Criteria** (what must be TRUE):
  1. README.md covers installation, global and per-repo config, the agent-ready label workflow, and all commands — no Laravel Zero boilerplate remains
  2. A new overnight setup guide walks through adding repos, labeling issues, running copland setup, and verifying the launchd job
  3. A developer following only the README can get Copland running against their repo without reading source code
  4. The setup guide includes a "morning review" section showing how to read the runs.jsonl log
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. API Retry/Backoff | 0/TBD | Not started | - |
| 2. Executor Hardening | 0/TBD | Not started | - |
| 3. Structured Run Log | 0/TBD | Not started | - |
| 4. Prompt Caching | 0/TBD | Not started | - |
| 5. Cache-Aware Cost Model | 0/TBD | Not started | - |
| 6. Multi-Repo Runner | 0/TBD | Not started | - |
| 7. Launchd Setup | 0/TBD | Not started | - |
| 8. Retry Wrapper Tests | 0/TBD | Not started | - |
| 9. Executor Tests | 0/TBD | Not started | - |
| 10. Orchestrator Tests | 0/TBD | Not started | - |
| 11. Documentation | 0/TBD | Not started | - |

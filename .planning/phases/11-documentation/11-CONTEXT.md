# Phase 11: Documentation - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Rewrite the project documentation so Copland’s README and setup guidance match the tool that now exists.

This phase covers replacing the Laravel Zero boilerplate README, documenting installation and configuration, explaining the `agent-ready` issue flow, and adding an overnight setup guide for multi-repo runs plus macOS launchd automation. It does not add new product behavior or broaden the docs into a hosted-service manual.

</domain>

<decisions>
## Implementation Decisions

### Documentation Scope
- **D-01:** Replace the current Laravel Zero template `README.md` completely with Copland-specific documentation.
- **D-02:** Document the actual command surface and configuration paths now implemented: global `~/.copland.yml`, per-repo `.copland.yml`, `copland run`, `copland issues`, `copland plan`, and `copland setup`.
- **D-03:** Include the issue workflow centered on the `agent-ready` label so a developer can understand how work enters the overnight pipeline.

### Setup Guidance
- **D-04:** Add an overnight setup guide as a separate document rather than overloading the README with every operational detail.
- **D-05:** The setup guide should include multi-repo configuration, launchd installation via `copland setup`, and a morning-review section based on `~/.copland/logs/runs.jsonl`.
- **D-06:** Keep the initial setup docs aligned with the implemented local-machine model; do not document unsupported hosted or team workflows.

### Accuracy Boundaries
- **D-07:** Documentation must describe current shipped behavior only, including the one-issue-per-run model and draft-PR output.
- **D-08:** Prefer examples grounded in the actual config schema and command behavior already present in the codebase.
- **D-09:** Preserve the distinction between global config (`~/.copland.yml`) and repo-local policy config (`.copland.yml`).

### Structure
- **D-10:** README should optimize for first-run onboarding: what Copland is, prerequisites, installation, configuration, commands, and core workflow.
- **D-11:** The separate overnight setup guide should optimize for operational use: scheduling, verification, and morning review.

### the agent's Discretion
- Exact README section order and tone, as long as it is concise and practical.
- Exact filename/location for the overnight guide, as long as it is easy to find from the README.
- Whether to include a small troubleshooting section if it helps explain launchd or config-resolution behavior clearly.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 11 success criteria define the required README and overnight guide coverage.
- `.planning/REQUIREMENTS.md` — `DOCS-01` and `DOCS-02` are the governing requirements for this phase.

### Existing Code / Behavior to Document
- `README.md` — currently stale Laravel Zero boilerplate to replace.
- `app/Commands/RunCommand.php` — core overnight run entrypoint and multi-repo behavior.
- `app/Commands/SetupCommand.php` — launchd installer behavior to document.
- `app/Config/GlobalConfig.php` — global config schema and example defaults.
- `app/Config/RepoConfig.php` — repo-local policy config to document.
- `app/Support/RunLogStore.php` — morning review path under `~/.copland/logs/runs.jsonl`.

### Prior Phase Outputs
- `.planning/phases/06-multi-repo-runner/06-02-SUMMARY.md` — confirms multi-repo run behavior and `repos:` config.
- `.planning/phases/07-launchd-setup/07-02-SUMMARY.md` — confirms `copland setup` and launchd flow.
- `.planning/phases/10-orchestrator-tests/10-01-SUMMARY.md` — confirms the documented pipeline is regression-covered end to end at the service layer.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- The codebase already has the command and config behavior the docs need; this phase is primarily a translation of implemented behavior into accurate onboarding text.
- `GlobalConfig` contains a useful default YAML template that can anchor the README’s global-config example.
- Launchd setup and run-log paths are now stable enough to document as first-class operational workflows.

### Established Patterns
- Copland is a local CLI for a single user operating across a handful of repos, not a framework starter template.
- The project’s planning docs consistently describe one issue per run, draft PR output, and user-scoped configuration.
- Testing phases 8-10 mean the core behavior being documented is now stable enough to present as canonical.

### Integration Points
- The README should point to the overnight setup guide rather than duplicating every operational verification step.
- The overnight guide should show how repos, labels, launchd setup, and `runs.jsonl` fit together into the daily workflow.
- Documentation must avoid references to unsupported Laravel Zero boilerplate features that Copland does not expose.

</code_context>

<specifics>
## Specific Ideas

- [auto] Rewrite `README.md` from scratch for Copland.
- [auto] Add a dedicated overnight setup guide in a docs-style markdown file and link it from the README.
- [auto] Include concrete YAML examples for both `~/.copland.yml` and repo-level `.copland.yml`.
- [auto] Include a morning review section centered on `~/.copland/logs/runs.jsonl`.

</specifics>

<deferred>
## Deferred Ideas

- Hosted/team deployment docs.
- Deep architecture documentation beyond what an end user needs to run Copland.
- Extended troubleshooting beyond the most likely launchd/config-path issues.

</deferred>

---

*Phase: 11-documentation*
*Context gathered: 2026-04-03*

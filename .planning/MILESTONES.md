# Milestones

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

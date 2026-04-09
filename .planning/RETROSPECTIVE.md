# Retrospective: Copland

---

## Milestone: v1.1 — Multi-Provider & Asana Integration

**Shipped:** 2026-04-09
**Phases:** 4 | **Plans:** 12 | **Timeline:** 1 day (2026-04-08)
**Git range:** v1.0..v1.1 — 62 commits, 89 files, +12,133 / -1,568 lines

### What Was Built

- `LlmClient` interface + value objects (`LlmResponse`, `LlmUsage`, `SystemBlock`) decouple all three Claude services from the Anthropic SDK
- `LlmResponseNormalizer` and `ToolSchemaTranslator` establish canonical provider contracts (stopReason normalization, Anthropic→OpenAI schema translation)
- `OpenAiCompatClient` + `LlmClientFactory` with D-05 resolution order enable Ollama and OpenRouter as drop-in backends
- `RunCommand` wires per-stage factory calls, Ollama reachability probe, and model capability warning
- `TaskSource` interface + `GitHubTaskSource` delegation wrapper make the orchestrator task-source-agnostic
- `AsanaService` + `AsanaTaskSource` deliver Asana as a full task source with tag/section filtering and PR comment-back

### What Worked

- **Phase sequencing**: Each phase delivered exactly what the next depended on — 14 (interface) → 15 (clients) → 16 (TaskSource) → 17 (Asana). No phase needed to go back and fix what a prior phase broke.
- **TDD discipline**: Most plans followed RED→GREEN→refactor. Tests caught real issues (dead null guard on LlmUsage, PHP callable type constraint) before they became bugs.
- **Injectable seams**: `$httpProber` in RunCommand, `$runner` in GitService — consistent pattern made Ollama probe fully testable without network.
- **Static utility classes**: `LlmResponseNormalizer` and `ToolSchemaTranslator` as pure static utilities kept the provider abstraction boundary clean.

### What Was Inefficient

- **REQUIREMENTS.md checkboxes never updated during execution**: All 15 requirements were delivered but none were checked off until milestone completion. Future phases should update REQUIREMENTS.md as plans complete.
- **Plan naming inconsistency**: Phase 14 used `14-14-SUMMARY.md` (wrong numbering) instead of `14-01-SUMMARY.md`, which broke `gsd-tools summary-extract` and produced garbage MILESTONES.md accomplishments requiring manual correction.
- **Stale ROADMAP.md at archive time**: Phase 17 and Phase 15 plan checkboxes were still `[ ]` when the tools snapshotted the roadmap, requiring post-archive edits.

### Patterns Established

- **D-05 resolution order** (repo stage → global stage → repo default → global default → anthropic fallback) is now the canonical provider resolution pattern — document and reuse in future provider work
- **Delegation wrappers over rewrites**: `GitHubTaskSource` wraps `GitHubService` rather than refactoring it — zero behavioral risk, clean interface boundary
- **String|int for external IDs**: Asana GIDs (64-bit) require `string|int` throughout the pipeline — apply this pattern to any future external task source integration

### Key Lessons

- Mark REQUIREMENTS.md checkboxes after each plan completes, not at milestone end — reduces reconciliation work
- Verify SUMMARY.md YAML `one_liner:` field exists in consistent format before archiving — the extraction tool is fragile against format variation
- Injectable callable seams (`private $probe = null`) work around PHP 8.2's `?callable` property restriction — document this as a project convention

### Cost Observations

- Model mix: Sonnet for planner + executor, Haiku for selector — unchanged from v1.0
- Sessions: ~1 day of execution
- Notable: Phase 15 plans ran in ~4–8 minutes each; consistent with v1.0 velocity

---

## Cross-Milestone Trends

| Milestone | Phases | Plans | Timeline | Files Changed |
|-----------|--------|-------|----------|---------------|
| v1.0 Overnight Hardening | 13 | 23 | 2 days | — |
| v1.1 Multi-Provider & Asana | 4 | 12 | 1 day | 89 |

**Observations:**
- v1.1 maintained v1.0 velocity (~10 min/plan) despite higher technical complexity
- Phased abstraction (interface first, then implementations) scales well — each phase was independently verifiable

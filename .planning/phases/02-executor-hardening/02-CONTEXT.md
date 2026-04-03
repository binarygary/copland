# Phase 2: Executor Hardening - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Harden the executor so file reads are bounded and write protection is enforced by structured data instead of brittle guardrail text parsing.

This phase covers executor read/write behavior, the plan contract fields needed to carry structured write restrictions, and the repo-level configuration needed to tune read limits. It does not add new user-facing features, logging, caching, or multi-repo scheduling.

</domain>

<decisions>
## Implementation Decisions

### Read Limits
- **D-01:** `read_file` should enforce a line-count cap, not a byte cap, because the roadmap requirement is defined in lines and the executor already works with plain text file contents.
- **D-02:** The default cap is `300` lines.
- **D-03:** The cap should be configurable from repo-level `.copland.yml`, not global config, because executor safety limits are repository-specific like `allowed_commands`, `blocked_paths`, and `max_executor_rounds`.
- **D-04:** When a file exceeds the cap, the executor returns the first `N` lines plus an explicit truncation notice telling Claude that content was cut and how many lines were omitted.

### Truncation Behavior
- **D-05:** Truncation should happen only in the `read_file` tool path; do not introduce chunked follow-up reads or pagination in this phase.
- **D-06:** The truncation notice must be appended to the returned content so it becomes part of the model-visible context, rather than being logged separately.
- **D-07:** The notice should prioritize clarity over formatting flair: mention the configured limit and that more lines exist beyond the returned excerpt.

### Write Protection Contract
- **D-08:** Replace the current free-text guardrail write blocking with an explicit `blocked_write_paths` array carried in the plan contract.
- **D-09:** `blocked_write_paths` should be normalized and enforced in the executor with the same path semantics as other policy checks.
- **D-10:** The executor must reject writes to a blocked path even when the path also appears in `files_to_change`.
- **D-11:** Existing repo-level `blocked_paths` remains the baseline filesystem protection for reads/listing; `blocked_write_paths` is an additional plan-scoped write restriction, not a replacement for the repo config.

### Planner and Validation Wiring
- **D-12:** The planner contract must surface `blocked_write_paths` as structured JSON, not buried in `guardrails`.
- **D-13:** `PlanResult`, artifact storage, plan validation, and executor enforcement all need to carry the new field end-to-end so planning and execution stay aligned.
- **D-14:** `guardrails` can remain for human-readable context, but execution must stop depending on text matching inside that field for correctness.

### the agent's Discretion
- Exact naming of the repo-level read-limit accessor and plan-level blocked-write accessor.
- Whether truncation formatting includes omitted-line counts, total-line counts, or both, as long as the notice clearly tells Claude the read was cut.
- Whether `blocked_write_paths` defaults to an empty array in `PlanResult`, planner output normalization, and stored artifacts, as long as the structured field is always present to downstream code.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements and Scope
- `.planning/ROADMAP.md` — Phase 2 goal and success criteria for capped reads and structured write protection
- `.planning/REQUIREMENTS.md` — `RELY-02` and `RELY-03` define the required behavior and acceptance criteria
- `.planning/PROJECT.md` — project-level safety goals and current validated scope

### Existing Executor Surfaces
- `app/Services/ClaudeExecutorService.php` — current `read_file`, `write_file`, `replace_in_file`, and tool contract handling
- `app/Support/ExecutorPolicy.php` — existing path normalization and policy enforcement rules
- `app/Support/ExecutorRunState.php` — executor exploration controls that must continue to work after read truncation changes
- `app/Config/RepoConfig.php` — repo-level policy/config pattern to extend for read limits

### Planning and Validation Flow
- `app/Services/ClaudePlannerService.php` — planner prompt inputs and plan contract parsing
- `app/Services/PlanValidatorService.php` — current structured validation point for planner output
- `app/Data/PlanResult.php` — shared plan contract object that likely needs a new field
- `app/Support/PlanArtifactStore.php` — persists plan artifacts and must stay in sync with the plan contract
- `resources/prompts/planner.md` — planner JSON schema and contract examples
- `resources/prompts/executor.md` — executor-facing contract language

### Existing Tests and Patterns
- `tests/Unit/ExecutorPolicyTest.php` — current policy assertions and expected failure style
- `tests/Unit/ExecutorRunStateTest.php` — ensures executor safety regressions are visible during refactoring
- `.planning/codebase/CONVENTIONS.md` — code style, error handling, and service/config patterns
- `.planning/codebase/STRUCTURE.md` — service/support/config boundaries and integration points

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `App\Support\ExecutorPolicy` — already normalizes paths, blocks `.git`, and throws `PolicyViolationException`; best place to extend structured write protection semantics
- `App\Config\RepoConfig` — already owns repo-specific executor controls (`max_executor_rounds`, `allowed_commands`, `blocked_paths`) and is the right place to add read-limit config
- `App\Services\PlanValidatorService` — existing checkpoint for validating planner JSON before execution
- `App\Support\PlanFieldNormalizer` and `App\Data\PlanResult` — existing contract parsing pipeline for planner output

### Established Patterns
- Repo-level safety settings live in `.copland.yml` and are exposed through typed `RepoConfig` accessors
- Executor failures surface as `Policy violation: ...` strings when enforcement throws `PolicyViolationException`
- Planner and executor exchange a structured JSON contract with explicit arrays (`files_to_change`, `commands_to_run`, `guardrails`)
- Unit tests already cover `ExecutorPolicy` and `ExecutorRunState`, so hardening changes should extend those test suites rather than invent a new test style

### Integration Points
- `RunCommand` assembles `repoProfile`, which feeds both planner input and executor policy construction
- `ClaudePlannerService` and `resources/prompts/planner.md` determine whether `blocked_write_paths` arrives as structured data
- `ClaudeExecutorService::readFile()` is the direct insertion point for truncation behavior
- `ClaudeExecutorService::writeFile()` and `replaceInFile()` are the direct insertion points for structured blocked-write enforcement

</code_context>

<specifics>
## Specific Ideas

- Prefer a plain truncation footer such as `...[truncated after 300 lines; 842 more lines omitted]` over a separate metadata channel so Claude sees the cutoff immediately.
- Keep read truncation deterministic: always first `N` lines, not heuristics about “important” regions.
- Preserve the existing repo-policy split: repo config defines baseline executor constraints; the plan contract adds per-plan restrictions.

</specifics>

<deferred>
## Deferred Ideas

- Read chunk continuation or “read next page” behavior — defer unless the capped-read approach proves insufficient in practice
- Token-budget enforcement or cost-aware truncation heuristics — belongs with later cost/caching phases
- Expanded executor test coverage for the hardened behavior — already scoped to Phases 8-10

</deferred>

---
*Phase: 02-executor-hardening*
*Context gathered: 2026-04-03*

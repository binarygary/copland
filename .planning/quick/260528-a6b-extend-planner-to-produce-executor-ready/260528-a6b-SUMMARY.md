---
phase: quick-260528-a6b
plan: 01
status: complete
branch: quick/planner-exact-diffs
tasks_completed: 4
tasks_total: 4
tests: 172 passed (596 assertions)
pint: passed on all newly-authored / substantially-changed files
---

# Quick 260528-a6b — Extend Planner to Emit Exact-Text Diffs

## One-liner

Copland planner now runs a bounded agentic `read_file` loop and emits an
exact `changes: [{file, old, new, reason}]` array that the executor applies
verbatim through `replace_in_file`, replacing the failure mode where weak
executor models had to invent precise old-text strings.

## Commits

- `04e45a9` — feat(quick-260528-a6b): add PlanResult changes field + validator coverage
- `d856970` — feat(quick-260528-a6b): planner agentic loop with read_file + changes emission
- `de70a02` — feat(quick-260528-a6b): surface plan->changes in executor contract + prompt

## Changes per task

### Task 1 — PlanResult + PlanValidatorService

- `app/Data/PlanResult.php`: added `public readonly array $changes = []`
  immediately before the optional `$usage` parameter so all existing
  named-arg call sites stay compatible.
- `app/Services/PlanValidatorService.php`: appended a `foreach ($plan->changes
  as $index => $change)` block that enforces structural rules — `file` must
  be a non-empty string and present in `files_to_change`; `old` must be a
  non-empty string; `new` must be a string (may be empty for deletions);
  `reason` must be a non-empty string. Empty/missing `changes` produces no
  errors (back-compat).
- `tests/Unit/PlanValidatorServiceTest.php` (new) — 5 tests: empty array,
  well-formed entry, file-not-in-files_to_change, empty `old`, missing
  `reason`.

### Task 2 — Planner agentic loop + prompt

- `app/Services/ClaudePlannerService.php`: rewrote `planTask` as a bounded
  agentic loop modelled on `ClaudeExecutorService::executeWithPolicy`.
  Single tool `read_file(path)` enforced by `ExecutorPolicy` (blocked
  paths, `read_file_max_lines`); workspace root from
  `$repoProfile['workspace_path'] ?? getcwd()`; round cap from
  `$repoProfile['max_planner_rounds'] ?? 6`; raises `RuntimeException`
  when exceeded. Tokens accumulate across rounds. Final `changes` is
  forwarded verbatim (typed array) into `PlanResult`; structural
  validation stays in the validator.
- `resources/prompts/planner.md`: added two sections — "Reading files
  before planning" (must `read_file` every `files_to_change` entry
  before emitting JSON) and "changes array" (`file`/`old`/`new`/`reason`
  contract with verbatim, uniquely-occurring `old` text). Added
  `"changes": []` to the JSON skeleton.
- `tests/Unit/ClaudePlannerServiceTest.php` (new) — 3 tests: planner
  reads then emits changes; planner throws after exceeding
  `max_planner_rounds`; planner accepts an empty `changes` array. Test
  file boots Laravel via `Tests\TestCase` so `base_path()` resolves.

### Task 3 — Executor contract surface + prompt

- `app/Services/ClaudeExecutorService.php`: added
  `'changes' => $plan->changes` to the JSON contract message. Key is
  always present (empty array when omitted).
- `resources/prompts/executor.md`: inserted an "Applying changes" section
  above Rules instructing the agent to apply each `changes` entry via
  `replace_in_file` with verbatim old/new strings before any other file
  mutation, and added a matching Rules bullet.
- `tests/Unit/ClaudeExecutorServiceTest.php`: the messages-fake now
  exposes `public array $captured = []` of incoming params;
  `makeExecutor` is rebuilt on top of a new `makeExecutorAndFake` helper
  that also returns the fake; `makePlan` accepts a `$changes` argument;
  a new test asserts the contract JSON includes
  `"file": "src\/file.txt"`, `"old": "foo"`, `"new": "bar"`, and
  `"reason": "rename foo to bar"`.

### Task 4 — Full-suite regression + lint

- `./vendor/bin/pest` → **172 passed (596 assertions)**.
- `./vendor/bin/pint --test` on all newly-authored or substantially-
  changed PHP files (`PlanResult`, `PlanValidatorService`,
  `ClaudePlannerService`, `ClaudeExecutorService`, `PlanValidatorServiceTest`,
  `ClaudePlannerServiceTest`) → **passed**.
- `grep -rn "new PlanResult(" app/ tests/` confirms all call sites use
  named arguments; no positional construction needed updating.

## Deviations from plan

- **Tests required Laravel bootstrapping.** The plan placed planner
  tests in `tests/Unit/` (Pest's default Unit suite does not boot
  Laravel via `Tests\TestCase`), but `ClaudePlannerService::planTask`
  calls `base_path()` to load `resources/prompts/planner.md`. Resolved
  inline (Rule 3 — blocking) by adding `uses(TestCase::class);` at the
  top of `tests/Unit/ClaudePlannerServiceTest.php` so the framework
  container is available. No production code changes needed.
- **JSON contract uses escaped slashes.** `json_encode(..., JSON_PRETTY_PRINT)`
  emits `"src\/file.txt"` (no `JSON_UNESCAPED_SLASHES` flag is set
  anywhere else in this file), so the Task 3 contract-message test
  asserts the escaped form rather than `"src/file.txt"`. Behavior is
  unchanged; only the test assertion accounts for the encoding.
- **Pre-existing pint findings left alone.** Running pint across the
  whole repo flags a number of files I did not touch (RepoConfig,
  AnthropicApiClient, LlmResponse, AsanaService, several test files,
  etc.). Per the SCOPE BOUNDARY rule I did not auto-fix unrelated
  style debt. The one pint finding inside a file I edited
  (`tests/Unit/ClaudeExecutorServiceTest.php`) is a pre-existing
  `\RuntimeException` literal at the leading-backslash position that
  also exists in `HEAD~3:tests/Unit/ClaudeExecutorServiceTest.php`;
  the diff Pint would produce touches only that pre-existing line, not
  the code I added.

## Known stubs

None — every new piece of behavior is wired through real production
code paths and covered by tests.

## Threat flags

None — no new endpoints, no new auth surface, no schema changes. The
planner gains a single read-only tool gated by the existing
`ExecutorPolicy`, which already enforces dotfile/blocked-path rules.

## Self-Check: PASSED

- Created files exist:
  - `tests/Unit/PlanValidatorServiceTest.php` — FOUND
  - `tests/Unit/ClaudePlannerServiceTest.php` — FOUND
  - `.planning/quick/260528-a6b-extend-planner-to-produce-executor-ready/260528-a6b-PLAN.md` — FOUND (input)
- Commits present in `git log --oneline`:
  - `04e45a9` — FOUND
  - `d856970` — FOUND
  - `de70a02` — FOUND
- Field referenced in all four target files (`grep "plan->changes\|'changes'\|public readonly array \$changes"`):
  - `app/Data/PlanResult.php` — FOUND (declaration)
  - `app/Services/ClaudePlannerService.php` — FOUND (parsing into PlanResult)
  - `app/Services/PlanValidatorService.php` — FOUND (validation loop)
  - `app/Services/ClaudeExecutorService.php` — FOUND (contract message)

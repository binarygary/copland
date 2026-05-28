---
phase: 260527-tuq
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/VerificationService.php
  - app/Services/GitService.php
  - tests/Unit/GitServiceTest.php
  - tests/Unit/VerificationServiceTest.php
autonomous: true
requirements:
  - QUICK-260527-TUQ-01
  - QUICK-260527-TUQ-02

must_haves:
  truths:
    - "When the executor produces zero file changes, VerificationService::verify() returns passed=false with a clear failure message identifying the no-change condition."
    - "When git commit (or any GitService::run() call) fails with output only on stdout (e.g. 'nothing to commit, working tree clean'), the thrown RuntimeException message includes the stdout content rather than an empty trailing string."
    - "Existing GitService and VerificationService success paths continue to work unchanged."
  artifacts:
    - path: "app/Services/VerificationService.php"
      provides: "Empty-change-set failure guard before bound checks"
      contains: "Executor produced no file changes"
    - path: "app/Services/GitService.php"
      provides: "run() error message falls back to stdout when stderr is empty"
    - path: "tests/Unit/VerificationServiceTest.php"
      provides: "Pest coverage for empty-changeset failure"
    - path: "tests/Unit/GitServiceTest.php"
      provides: "Pest coverage for stdout-fallback error message"
  key_links:
    - from: "VerificationService::verify"
      to: "GitService::changedFiles"
      via: "count() of returned array; empty array now triggers failure"
      pattern: "changedFiles\\("
    - from: "GitService::run"
      to: "execute() result['stdout']"
      via: "fallback when result['stderr'] is empty"
      pattern: "stderr.*\\?:.*stdout|result\\['stdout'\\]"
---

<objective>
Fix two related bugs in the executor verification + git-shell-out path so that a run with zero file changes fails fast with a meaningful message instead of crashing one step later at `git commit` with an empty error string.

Purpose: Today, when ClaudeExecutorService produces no edits:
1. `VerificationService::verify()` passes (it only checks upper bounds on file/line counts), then
2. `GitService::commit()` runs `git commit -m ...`, which exits non-zero with "nothing to commit, working tree clean" on **stdout**, then
3. `GitService::run()` builds `RuntimeException("git commit failed: " . $result['stderr'])` where stderr is empty — so the user sees `git commit failed: ` with a trailing blank.

Fix #1 (VerificationService) catches the common case at the right layer with a clear message. Fix #2 (GitService) ensures that any future git command that errors with stdout-only output (commit, push, rebase, etc.) is surfaced cleanly.

Output: Two small scoped patches in `app/Services/` plus Pest unit tests covering both behaviors. No dependency, no refactor beyond the two named methods.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@.planning/STATE.md
@app/Services/VerificationService.php
@app/Services/GitService.php
@app/Data/VerificationResult.php
@app/Data/PlanResult.php
@app/Data/ExecutionResult.php
@tests/Unit/GitServiceTest.php

<interfaces>
<!-- Extracted from codebase; executor should use these directly. -->

VerificationService::verify signature (app/Services/VerificationService.php):
  public function verify(
      array $repoProfile,
      string $workspacePath,
      PlanResult $plan,
      ExecutionResult $result
  ): VerificationResult

  Constructor: __construct(private GitService $git)
  Returns: new VerificationResult(empty($failures), $failures)

VerificationResult (app/Data/VerificationResult.php):
  readonly bool $passed
  readonly array $failures

GitService::run (app/Services/GitService.php, lines 111-118), private:
  private function run(array $command, string $cwd, string $errorMessage): void
  Current body throws: new RuntimeException("{$errorMessage}: " . $result['stderr']);
  $result has keys: 'stdout', 'stderr', 'exitCode' (see execute() lines 131-145).

GitService test runner injection pattern (tests/Unit/GitServiceTest.php):
  $git = new GitService(function (array $command, string $cwd): array {
      return ['stdout' => '...', 'stderr' => '...', 'exitCode' => N];
  });
  The runner closure intercepts execute() entirely. This is the test seam.

VerificationService is NOT runner-injectable today; its only dependency is GitService.
For the new test, inject a GitService configured with a runner that returns an empty
`git diff --name-only HEAD` (stdout=''), exitCode 0, so `changedFiles()` returns [].
Then call verify() with a successful ExecutionResult and assert passed=false plus the
expected failure string.

ExecutionResult shape (used by verify): properties include readonly bool $success and
readonly string $summary. Constructing one in-test requires only those plus whatever
other readonly fields its constructor mandates — read app/Data/ExecutionResult.php
once to confirm before writing the test.

PlanResult shape: needs maxFilesChanged (int) and maxLinesChanged (int) at minimum
for verify() to function. Construct a minimal PlanResult in-test by reading
app/Data/PlanResult.php once; only the fields verify() touches must be sensible.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Add empty-changeset guard to VerificationService::verify and test it</name>
  <files>app/Services/VerificationService.php, tests/Unit/VerificationServiceTest.php</files>
  <behavior>
    - When ExecutionResult->success is true AND `$this->git->changedFiles($workspacePath)` returns an empty array, verify() returns a VerificationResult with passed=false and a failure message containing the exact substring "Executor produced no file changes".
    - When ExecutionResult->success is true AND changedFiles returns at least one file (and counts are within plan caps and no blocked paths matched), verify() still returns passed=true (existing happy path preserved).
    - When ExecutionResult->success is false, verify() still short-circuits as it does today (the new guard does not fire for failed executions).
  </behavior>
  <action>
    In `app/Services/VerificationService.php`, after computing `$changedFiles` and `$fileCount` (currently line 27-28), insert an empty-changeset guard BEFORE the existing upper-bound check on `$fileCount`. The guard: if `$fileCount === 0`, append a failure message exactly matching the substring "Executor produced no file changes" (e.g. "Executor produced no file changes; nothing to commit") and return the VerificationResult immediately (mirroring the early-return pattern already used for the `! $result->success` branch on lines 21-25). Returning early avoids running the rest of the checks against an empty file set and matches the existing style. Preserve the existing throw-style for error context (descriptive, per CLAUDE.md). Do NOT add any new fields to VerificationResult.

    Create `tests/Unit/VerificationServiceTest.php` as a Pest test file (no class — use the `it(...)` style matching `tests/Unit/GitServiceTest.php`). Cover three cases:
      1. `it('fails when the executor produces no file changes', ...)` — construct a GitService with a runner that returns `['stdout' => '', 'stderr' => '', 'exitCode' => 0]` for `['git', 'diff', '--name-only', 'HEAD']`. Build a minimal PlanResult and a successful ExecutionResult. Assert `$result->passed` is false and that one of `$result->failures` contains "Executor produced no file changes".
      2. `it('passes when the executor produces in-bound file changes', ...)` — runner returns `['stdout' => "app/Foo.php\n", 'stderr' => '', 'exitCode' => 0]` for the name-only diff and `['stdout' => " app/Foo.php | 2 +-\n 1 file changed, 1 insertion(+), 1 deletion(-)\n", 'stderr' => '', 'exitCode' => 0]` for the `['git', 'diff', '--stat', 'HEAD']` diff. Assert `$result->passed` is true.
      3. `it('short-circuits when execution itself failed', ...)` — pass a non-success ExecutionResult; runner should NOT be invoked for diff commands. Assert `$result->passed` is false and the failure message starts with "Execution did not succeed".

    For PlanResult / ExecutionResult construction, read `app/Data/PlanResult.php` and `app/Data/ExecutionResult.php` once to confirm constructor signatures before instantiating them — pass only sensible defaults (e.g. maxFilesChanged=3, maxLinesChanged=250, summary='ok' for success path).

    Run `./vendor/bin/pint app/Services/VerificationService.php tests/Unit/VerificationServiceTest.php` after editing to keep style consistent.
  </action>
  <verify>
    <automated>./vendor/bin/pest tests/Unit/VerificationServiceTest.php</automated>
  </verify>
  <done>
    - `app/Services/VerificationService.php` contains an empty-changeset guard that adds a failure containing "Executor produced no file changes" and returns early when `$fileCount === 0` and execution succeeded.
    - `tests/Unit/VerificationServiceTest.php` exists with three Pest tests covering: empty changeset → fail, in-bound changeset → pass, failed execution → short-circuit. All three pass.
    - Pint reports no style changes needed on the modified files.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: GitService::run falls back to stdout when stderr is empty, plus test</name>
  <files>app/Services/GitService.php, tests/Unit/GitServiceTest.php</files>
  <behavior>
    - When a command run via `GitService::run()` exits non-zero with non-empty stderr, the thrown RuntimeException message contains the stderr text (existing behavior preserved).
    - When a command run via `GitService::run()` exits non-zero with empty stderr but non-empty stdout, the thrown RuntimeException message contains the stdout text instead of an empty trailing string.
    - When a command run via `GitService::run()` exits non-zero with both stderr and stdout empty, the message ends cleanly (no double colon, no dangling whitespace beyond what's already there); a literal empty trailer is acceptable but the implementation should not introduce extra punctuation.
  </behavior>
  <action>
    In `app/Services/GitService.php`, modify the private `run()` method (currently lines 111-118). Replace the message-building line `throw new RuntimeException("{$errorMessage}: ".$result['stderr']);` so that it prefers `stderr` but falls back to `stdout` when `stderr` is empty. Implementation: trim `$result['stderr']`, and if the trimmed value is `''`, use `trim($result['stdout'])` instead; otherwise use `$result['stderr']`. Keep the existing `"{$errorMessage}: "` prefix and the `RuntimeException` type — this matches the descriptive-context style required by CLAUDE.md. Do NOT modify the private `output()` method on lines 120-129 (it has the same shape but only ever runs read-only commands like `git status --porcelain` where stderr is the right source; leaving it alone keeps the patch minimally scoped per constraints).

    Add a new Pest test to `tests/Unit/GitServiceTest.php` following the existing closure-runner pattern (do not introduce a new test class). Two cases:
      1. `it('surfaces stdout when commit fails with stderr empty', ...)` — runner intercepts `['git', 'add', '-A']` returning exitCode 0, and `['git', 'commit', '-m', '...']` returning `['stdout' => "nothing to commit, working tree clean\n", 'stderr' => '', 'exitCode' => 1]`. Wrap `$git->commit('/tmp/repo', 'msg')` in `expect(fn() => ...)->toThrow(RuntimeException::class, 'nothing to commit')`.
      2. `it('still surfaces stderr when present', ...)` — runner makes the same commit fail with `['stdout' => '', 'stderr' => "fatal: not a git repository\n", 'exitCode' => 128]`. Assert `toThrow(RuntimeException::class, 'fatal: not a git repository')`.

    Pick any reasonable commit message string for the test; the runner matches the four-element array `['git', 'commit', '-m', $msg]` shape — use a wildcard via a fall-through `default` arm or match on `$command[0..1]` prefix if exact-match closures get unwieldy. Easiest: just use a known literal in both the test setup and the `commit()` call.

    Run `./vendor/bin/pint app/Services/GitService.php tests/Unit/GitServiceTest.php` after editing.
  </action>
  <verify>
    <automated>./vendor/bin/pest tests/Unit/GitServiceTest.php</automated>
  </verify>
  <done>
    - `app/Services/GitService.php::run()` falls back to `trim($result['stdout'])` when `trim($result['stderr'])` is empty, preserving the existing `RuntimeException` type and `"{$errorMessage}: "` prefix.
    - `tests/Unit/GitServiceTest.php` has two new Pest tests covering the stdout-fallback path and the existing stderr-present path; both pass.
    - Full suite (`./vendor/bin/pest`) is green — no regressions to existing branch-prep tests in this file.
    - Pint reports no style changes needed on the modified files.
  </done>
</task>

</tasks>

<verification>
After both tasks complete, run the full Pest suite to confirm no regressions elsewhere (RunOrchestratorServiceTest exercises VerificationService indirectly):

```
./vendor/bin/pest
./vendor/bin/pint --test
```

Manual sanity check (optional, not required for plan completion): trigger a copland dry-run that produces zero file changes and confirm the error surfaced is "Executor produced no file changes; nothing to commit" instead of "git commit failed: ".
</verification>

<success_criteria>
- VerificationService fails fast with "Executor produced no file changes" when the executor makes zero edits on a successful run.
- GitService::run() never throws a RuntimeException whose message ends with an empty trailer when stdout contains the actual git output.
- `./vendor/bin/pest` exits 0 with the two new tests in `VerificationServiceTest.php` and the two new tests in `GitServiceTest.php` passing.
- `./vendor/bin/pint --test` reports no style violations on the four touched files.
- No new dependencies added to `composer.json`; no refactoring outside `VerificationService::verify` and `GitService::run`.
</success_criteria>

<output>
Create `.planning/quick/260527-tuq-fix-silent-git-commit-failed-when-execut/260527-tuq-SUMMARY.md` when done.
</output>

---
phase: 19-prototype-recovery-console-launcher
plan: 02
type: execute
wave: 2
depends_on:
  - 19-01
files_modified:
  - app/Commands/ConsoleCommand.php
  - tests/Feature/ConsoleCommandTest.php
autonomous: true
requirements:
  - GODOT-03
validation_strategy: not_applicable

must_haves:
  truths:
    - "`copland console` is a registered Laravel Zero command (visible via `php copland list`)"
    - "`copland console` runs two preflight checks (project.godot present, Godot.app locatable) before any shell-out (D-07, D-08)"
    - "On preflight pass, `copland console` invokes `open -a Godot --args --path <abs>/console-godot/` and exits cleanly (D-04, D-06: no `-W`, no wait)"
    - "On `console-godot/project.godot` missing: command emits targeted error mentioning `console-godot/` and exits non-zero, without invoking `open` (D-07, D-08)"
    - "On Godot.app missing: command emits targeted error mentioning Godot 4.2+ install hint and exits non-zero, without invoking `open` (D-07, D-08)"
    - "Path to `console-godot/` is resolved via Laravel Zero's `base_path()`, not `getcwd()` (D-09)"
    - "Pest tests exercise success path, missing-project failure, missing-Godot failure — all via injected `$runner` and `$projectFileChecker` closures (no real Godot launch)"
  artifacts:
    - path: "app/Commands/ConsoleCommand.php"
      provides: "Laravel Zero command `console` with preflight + open shell-out"
      contains: "class ConsoleCommand extends Command"
      min_lines: 60
    - path: "tests/Feature/ConsoleCommandTest.php"
      provides: "Pest tests for the success path and both preflight failure paths"
      contains: "use App\\Commands\\ConsoleCommand;"
  key_links:
    - from: "app/Commands/ConsoleCommand.php"
      to: "Symfony\\Component\\Process\\Process"
      via: "private runShellCommand(array): array seam (mirrors AutomateCommand::runShellCommand)"
      pattern: "Symfony.Component.Process.Process"
    - from: "app/Commands/ConsoleCommand.php"
      to: "base_path()"
      via: "default projectRootResolver closure (D-09)"
      pattern: "base_path\\("
    - from: "tests/Feature/ConsoleCommandTest.php"
      to: "ConsoleCommand"
      via: "constructor closure injection (runner, projectRootResolver, projectFileChecker)"
      pattern: "new ConsoleCommand"
---

<objective>
Add `app/Commands/ConsoleCommand.php` — a Laravel Zero `console` command that runs two preflight checks (project file present, Godot.app locatable) and then launches Godot via `open -a Godot --args --path <abs>/console-godot/`, fire-and-forget. Add `tests/Feature/ConsoleCommandTest.php` covering the success path plus both preflight failure paths with a mocked runner so no real Godot process is spawned. Closes GODOT-03.

Purpose: This is the only PHP work in Phase 19. The Godot prototype itself was restored in plan 19-01; this plan adds the operator-facing launcher so users can run `copland console` instead of opening Godot manually. The command is intentionally thin (preflight + one shell-out) and follows `AutomateCommand`'s injectable-runner pattern verbatim so it is fully unit-testable without launching a GUI.

Output: One new command class, one new Pest test file, both passing `./vendor/bin/pest` and `./vendor/bin/pint` (or `pint --test`).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/STATE.md
@.planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md
@.planning/phases/19-prototype-recovery-console-launcher/19-PATTERNS.md
@.planning/codebase/CONVENTIONS.md
@.planning/codebase/TESTING.md
@app/Commands/AutomateCommand.php
@app/Commands/StatusCommand.php
@app/Services/GitService.php
@tests/Feature/AutomateCommandTest.php
@tests/Unit/GitServiceTest.php

<!-- Plan 19-01 SUMMARY is NOT loaded — this plan does not consume any types or decisions from 19-01. -->
<!-- The only cross-plan dependency is that the restored console-godot/project.godot must exist on disk for an end-to-end manual run (Pest tests stub it). -->

<interfaces>
<!-- Key contracts the executor reuses verbatim from existing code. Extracted from analog files; do NOT re-explore the codebase. -->

From `app/Commands/AutomateCommand.php` (the structural analog):

- Class header pattern:
  - `namespace App\Commands;`
  - `class AutomateCommand extends LaravelZero\Framework\Commands\Command`
  - `protected $signature = '...';`
  - `protected $description = '...';`
  - `public function handle(): int` returning `self::SUCCESS` (0) or `self::FAILURE` (1)

- Injectable-runner pattern (`__construct` lines 20-57, used as exact template for `ConsoleCommand`):
  - Constructor accepts nullable callable seams: `private $runner = null, private $projectRootResolver = null, ...`
  - Each seam defaults to a real implementation via `??=` in the constructor body
  - `projectRootResolver` default: `static fn (): string => base_path();`
  - `runShellCommand(array $command): array` returning `['stdout' => string, 'stderr' => string, 'exitCode' => int]`

- Shell-out return shape (also used by `app/Services/GitService.php` lines 131-145 — project-wide convention):
  ```
  ['stdout' => string, 'stderr' => string, 'exitCode' => int]
  ```
  (Always coalesce `Process::getExitCode()` with `?? 1` — `null` means the process never started, must surface as failure.)

From `app/Commands/StatusCommand.php` (17-line minimal command — confirms auto-discovery requires only `extends Command` + `$signature` + `$description` + `handle()`).

From `tests/Feature/AutomateCommandTest.php`:
- `use Symfony\Component\Console\Tester\CommandTester;`
- Test harness: `$command->setLaravel($this->app);` is MANDATORY before `new CommandTester($command);` — without it, `$this->line()` / `$this->error()` inside `handle()` will fail because IO bindings are uninitialized.
- Captured-commands pattern: `runner: function (array $command) use (&$commands): array { $commands[] = $command; return [...]; }`
- Display assertions: `$tester->getDisplay()` → `expect($display)->toContain('...')`.

From `tests/Unit/GitServiceTest.php` lines 8-20 (canonical match-based runner mock — preferred over `if` chains for multi-command preflight per PATTERNS.md):
- `match ($command) { ['mdfind', ...] => [...], default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)) }`
- The `default => throw` arm catches stray shell-outs loudly (TESTING.md "What to Mock" guidance).
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create app/Commands/ConsoleCommand.php with injectable preflight + open shell-out</name>
  <read_first>
    - app/Commands/AutomateCommand.php (full — structural analog; copy import/constructor/runShellCommand shape)
    - app/Commands/StatusCommand.php (minimal command shape — confirms only Command + signature + description + handle are needed)
    - app/Services/GitService.php (lines 120-146 — confirm the shell-out return shape convention `['stdout', 'stderr', 'exitCode']`)
    - .planning/phases/19-prototype-recovery-console-launcher/19-CONTEXT.md (D-04..D-09 — exact command shapes and decisions)
    - .planning/phases/19-prototype-recovery-console-launcher/19-PATTERNS.md (`Pattern Assignments` → ConsoleCommand sections)
    - .planning/codebase/CONVENTIONS.md (error handling: `$this->error()` for command diagnostics, `RuntimeException` only for true operational failures; naming: PascalCase class file, camelCase methods)
  </read_first>
  <files>app/Commands/ConsoleCommand.php</files>
  <action>
    Create a new file `app/Commands/ConsoleCommand.php` (PascalCase per CONVENTIONS.md). Class shape (mirror `AutomateCommand` exactly except for the bits called out below):

    Imports and header:
    - `namespace App\Commands;`
    - `use LaravelZero\Framework\Commands\Command;`
    - `use Symfony\Component\Process\Process;`
    - Class `ConsoleCommand extends Command`
    - `protected $signature = 'console';` (no arguments, no options — per CONTEXT.md "Claude's Discretion" and CONTEXT.md launch mechanism)
    - `protected $description = 'Launch the Copland Console (Godot 4.2+ GUI pointed at ~/.copland/tasks/)';`

    Constructor with three nullable callable seams (per CONTEXT.md "Claude's Discretion" and PATTERNS.md "Constructor + injectable seam pattern"):
    - `private $runner = null` — defaults to inline Process execution (see `runShellCommand` below)
    - `private $projectRootResolver = null` — defaults to `static fn (): string => base_path();` per D-09 (MUST be `base_path()`, NOT `getcwd()`)
    - `private $projectFileChecker = null` — defaults to `static fn (string $path): bool => file_exists($path);` so tests can simulate missing `project.godot` without writing fixtures (PATTERNS.md "Constructor + injectable seam pattern" recommendation)
    - Call `parent::__construct();` first; assign defaults with `??=` after.

    `handle(): int` flow (per D-07, D-08 — preflight first, no silent fallbacks):

    1. Resolve `$projectRoot = ($this->projectRootResolver)();`. Build `$godotProjectDir = $projectRoot.'/console-godot';` and `$godotProjectFile = $godotProjectDir.'/project.godot';`.

    2. Preflight #1 — project.godot existence: if `! ($this->projectFileChecker)($godotProjectFile)` then call `$this->error('console-godot/ not found — run from the Copland project root or restore the prototype (see .planning/phases/19-prototype-recovery-console-launcher/).');` and `return self::FAILURE;`. Do NOT proceed to the Godot.app probe (per D-08, stop on first preflight failure).

    3. Preflight #2 — Godot.app locatable: invoke a private method `private function godotAppLocatable(): bool`. Inside that method, run `mdfind` first (preferred per D-07), and only fall back to `osascript` if `mdfind` returns no hits. Concrete shapes (D-07):
       - Primary probe argv: `['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"]`. Pass via `$this->runShellCommand([...])`. Considered locatable iff `$result['exitCode'] === 0` AND `trim($result['stdout']) !== ''` (mdfind exits 0 even with no hits — empty stdout is the "not found" signal).
       - Fallback probe argv: `['osascript', '-e', 'id of app "Godot"']`. Pass via `$this->runShellCommand([...])`. Considered locatable iff `$result['exitCode'] === 0`.
       - Return `true` if either probe says locatable; `false` otherwise.

       If `godotAppLocatable()` returns `false`: `$this->error('error: Godot.app not found — install Godot 4.2+ (brew install --cask godot, or https://godotengine.org/).');` and `return self::FAILURE;`. Use the D-07 exact wording (the test asserts on it).

    4. Launch — only reached when both preflights pass: build argv `['open', '-a', 'Godot', '--args', '--path', $godotProjectDir]` (note: trailing slash on the dir is fine but not required; do NOT add `-W` per D-06). Invoke via `$this->runShellCommand([...])`. If exitCode is non-zero, surface `$this->error('open -a Godot failed: '.trim($result['stderr']));` and `return self::FAILURE;` (defensive — `open` rarely fails after preflight, but a non-zero exit is still a real failure). On success, optionally `$this->line('Launched Godot console (PID owned by Launch Services).');` then `return self::SUCCESS;`.

    Private helpers:
    - `private function runShellCommand(array $command): array` — copy verbatim from `AutomateCommand::runShellCommand` (PATTERNS.md "Process-runner seam pattern" says copy verbatim). Returns `['stdout' => string, 'stderr' => string, 'exitCode' => int]`; coalesce `getExitCode() ?? 1`.
    - `private function godotAppLocatable(): bool` — implements the two-probe logic above.

    Error handling discipline (per CONVENTIONS.md "Error Handling" and PATTERNS.md note "Use `$this->error(...)` not `throw new RuntimeException(...)` inside a command for preflight failures"):
    - Use `$this->error(...)` + `return self::FAILURE;` for preflight failures and shell-out failures. Do NOT throw `RuntimeException` from `handle()` — `AutomateCommand` reserves exceptions for truly unexpected ops failures (lines 104, 115, 128, 135, 150); preflight messages are user-facing diagnostics.

    Run `./vendor/bin/pint app/Commands/ConsoleCommand.php` after writing the file to honor the Laravel Pint formatting convention (CONVENTIONS.md "Code Style").
  </action>
  <verify>
    <automated>
      php copland list 2>&1 | grep -E '^\s*console\b' && \
      php -l app/Commands/ConsoleCommand.php && \
      grep -q "namespace App\\\\Commands" app/Commands/ConsoleCommand.php && \
      grep -q "extends Command" app/Commands/ConsoleCommand.php && \
      grep -q "protected \$signature = 'console'" app/Commands/ConsoleCommand.php && \
      grep -q "base_path()" app/Commands/ConsoleCommand.php && \
      grep -q "'open', '-a', 'Godot'" app/Commands/ConsoleCommand.php && \
      ! grep -q "'-W'" app/Commands/ConsoleCommand.php && \
      ! grep -q "getcwd" app/Commands/ConsoleCommand.php && \
      ./vendor/bin/pint --test app/Commands/ConsoleCommand.php
    </automated>
  </verify>
  <acceptance_criteria>
    - `app/Commands/ConsoleCommand.php` exists, parses cleanly (`php -l` exit 0).
    - `php copland list` includes a line for the `console` command (Laravel Zero auto-discovery picked it up).
    - File contains exactly the imports `LaravelZero\Framework\Commands\Command` and `Symfony\Component\Process\Process` (no extraneous imports — `RuntimeException` allowed if used, but no `HomeDirectory`, no `LaunchdPlist`, no `ProgressReporter`).
    - `$signature` is the bare string `'console'` (no arguments, no options).
    - Default `projectRootResolver` uses `base_path()` (D-09 enforced — grep confirms presence).
    - `getcwd()` does NOT appear anywhere in the file (D-09 — never use CWD).
    - The launch argv `['open', '-a', 'Godot', '--args', '--path', ...]` appears (D-04).
    - The string `'-W'` does NOT appear (D-06 — fire-and-forget).
    - Both preflight error messages contain D-07 wording: `console-godot/ not found` and `Godot.app not found`.
    - `./vendor/bin/pint --test` reports no style violations on the new file.
  </acceptance_criteria>
  <done>ConsoleCommand registered, parses, passes Pint, contains exact D-04/D-06/D-07/D-09 shapes; preflight blocks `open` invocation on failure.</done>
</task>

<task type="auto">
  <name>Task 2: Create tests/Feature/ConsoleCommandTest.php covering success + both preflight failures</name>
  <read_first>
    - tests/Feature/AutomateCommandTest.php (full — pattern for CommandTester, runner injection, display assertions)
    - tests/Unit/GitServiceTest.php (lines 1-60 — canonical match-based runner mock with `default => throw`)
    - .planning/codebase/TESTING.md (Pest conventions: no namespace, `it(...)` blocks, `$command->setLaravel($this->app);` requirement)
    - app/Commands/ConsoleCommand.php (the implementation just written — confirms exact argv shapes and error strings to assert on)
    - .planning/phases/19-prototype-recovery-console-launcher/19-PATTERNS.md (`tests/Feature/ConsoleCommandTest.php` section — match() runner is the canonical mock for this command)
  </read_first>
  <files>tests/Feature/ConsoleCommandTest.php</files>
  <action>
    Create a new Pest feature test at `tests/Feature/ConsoleCommandTest.php` (Feature, not Unit — matches `AutomateCommandTest` placement per PATTERNS.md "Test location decision"). No namespace declaration (Pest convention per TESTING.md "Structure").

    Imports:
    - `use App\Commands\ConsoleCommand;`
    - `use Symfony\Component\Console\Tester\CommandTester;`
    - (Add `use RuntimeException;` only if needed by the `default => throw` arm in match.)

    Three `it(...)` blocks, each with its own `$commands = [];` capture array and its own freshly-constructed `ConsoleCommand`:

    **Test 1 — success path: `it('launches Godot via open when preflights pass', function () { ... })`**
    - Construct ConsoleCommand with:
      - `runner: function (array $command) use (&$commands): array { $commands[] = $command; return match ($command) { ['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"] => ['stdout' => '/Applications/Godot.app', 'stderr' => '', 'exitCode' => 0], ['open', '-a', 'Godot', '--args', '--path', '/Users/tester/projects/copland/console-godot'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0], default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)) }; }`
      - `projectRootResolver: fn (): string => '/Users/tester/projects/copland'`
      - `projectFileChecker: fn (string $path): bool => $path === '/Users/tester/projects/copland/console-godot/project.godot'`
    - `$command->setLaravel($this->app);` (mandatory per TESTING.md and PATTERNS.md)
    - `$tester = new CommandTester($command); $exitCode = $tester->execute([]);`
    - Assert `expect($exitCode)->toBe(0);`
    - Assert `expect($commands)->toContain(['open', '-a', 'Godot', '--args', '--path', '/Users/tester/projects/copland/console-godot']);` — proves D-04 + D-09 launch shape.
    - Assert mdfind was called first: `expect($commands[0])->toBe(['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"]);` — proves D-07 probe ordering (mdfind preferred over osascript).
    - Note on path trailing slash: ConsoleCommand may emit either `console-godot` or `console-godot/`. If the implementation uses no trailing slash, assert without; if with, assert with. Match the implementation written in Task 1 exactly.

    **Test 2 — missing console-godot/project.godot: `it('refuses to launch and reports missing console-godot/ when project file is absent', function () { ... })`**
    - Construct ConsoleCommand with:
      - `runner: function (array $command) use (&$commands): array { $commands[] = $command; throw new RuntimeException('runner should not be invoked when project file is missing'); }` — proves D-08 (no shell-out attempted on preflight failure).
      - `projectRootResolver: fn (): string => '/Users/tester/projects/copland'`
      - `projectFileChecker: fn (string $path): bool => false` — simulate missing project.godot.
    - `$command->setLaravel($this->app);` and `$tester = new CommandTester($command);`
    - Assert `expect($tester->execute([]))->toBe(1);` (or `self::FAILURE` constant value).
    - Assert `expect($tester->getDisplay())->toContain('console-godot/ not found');` (D-07 wording).
    - Assert `expect($commands)->toBe([]);` — runner was never called; no shell-out attempted (D-08).

    **Test 3 — missing Godot.app: `it('refuses to launch and reports missing Godot.app when neither mdfind nor osascript locates it', function () { ... })`**
    - Construct ConsoleCommand with:
      - `runner: function (array $command) use (&$commands): array { $commands[] = $command; return match ($command) { ['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0], ['osascript', '-e', 'id of app "Godot"'] => ['stdout' => '', 'stderr' => 'execution error: ...', 'exitCode' => 1], default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)) }; }` — mdfind returns 0 with empty stdout (no hit), osascript returns non-zero. Both fail → Godot.app not locatable.
      - `projectRootResolver: fn (): string => '/Users/tester/projects/copland'`
      - `projectFileChecker: fn (string $path): bool => true` — project.godot exists, so preflight #1 passes.
    - Assert `expect($tester->execute([]))->toBe(1);`.
    - Assert `expect($tester->getDisplay())->toContain('Godot.app not found');` (D-07 wording).
    - Assert `expect($commands)->not->toContain(['open', '-a', 'Godot', '--args', '--path', '/Users/tester/projects/copland/console-godot']);` — no launch attempted (D-08).
    - Assert exactly the two probe calls were made (in order): `expect($commands)->toBe([['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"], ['osascript', '-e', 'id of app "Godot"']]);`. Proves fallback ordering and proves osascript IS tried when mdfind returns no hits.

    Run `./vendor/bin/pint tests/Feature/ConsoleCommandTest.php` after writing the file.
  </action>
  <verify>
    <automated>
      ./vendor/bin/pest --filter='ConsoleCommand' 2>&1 | tee /tmp/console-cmd-test.out && \
      grep -q '3 passed' /tmp/console-cmd-test.out && \
      ./vendor/bin/pint --test tests/Feature/ConsoleCommandTest.php && \
      ./vendor/bin/pest 2>&1 | tail -5 | grep -qE '(132|133|134|135) (passed|tests)'
    </automated>
  </verify>
  <acceptance_criteria>
    - `tests/Feature/ConsoleCommandTest.php` exists and parses cleanly.
    - Running `./vendor/bin/pest --filter='ConsoleCommand'` reports 3 passing tests, 0 failures.
    - Test 1 (success) asserts the captured `$commands` array contains the exact D-04 launch argv `['open', '-a', 'Godot', '--args', '--path', <abs>]` and that `mdfind` was the first probe (D-07 ordering).
    - Test 2 (missing project.godot) asserts exit code 1 AND that the runner was never invoked (D-08 — no shell-out on preflight failure).
    - Test 3 (missing Godot.app) asserts exit code 1 AND that `open` does NOT appear in captured commands AND that both `mdfind` and `osascript` were probed in that order (D-07 fallback).
    - All assertions use D-07 exact-substring matches (`'console-godot/ not found'`, `'Godot.app not found'`).
    - The full Pest suite still passes (no regression in the existing 132+ tests).
    - `./vendor/bin/pint --test` reports no style violations on the new test file.
  </acceptance_criteria>
  <done>3 new Pest tests pass; D-04/D-06/D-07/D-08/D-09 all asserted in tests; full suite green; Pint clean.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| user → `copland console` CLI | User invokes the command from any CWD. Command resolves project location via `base_path()` (under our control) and constructs argv for shell-out. |
| `copland console` → macOS `open` / `mdfind` / `osascript` | The command passes a project-path string into `open --args --path <path>` and a constant filter string into `mdfind`. The path comes from `base_path()` which is set by Laravel Zero at boot from the installed phar/script location — not from user input. |
| macOS Launch Services → Godot.app | `open -a Godot` resolves "Godot" via Launch Services; whatever `.app` bundle is registered under that name will be launched. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-19-04 | Tampering | argv to `open` / `mdfind` / `osascript` (shell injection) | mitigate | All shell-outs use Symfony Process with argv arrays (per `runShellCommand` pattern from AutomateCommand line 160 — `new Process($command)` accepts `array`, never a shell string). Argv arrays are passed straight to `execvp` without shell interpretation, so metacharacters in `base_path()` cannot be re-interpreted. Tests assert exact argv shapes (Task 2 Test 1), so any drift to a string-based invocation would fail the test. |
| T-19-05 | Spoofing | `open -a Godot` resolves to a spoofed app via Launch Services | accept | Per CONTEXT.md D-04 and planning_context "Security threat model": this is a personal CLI invoked on a developer's own machine. Launch Services spoofing requires local privilege to register a malicious app under the "Godot" name — at that point the attacker already owns the user account. Documented but not blocked. Re-evaluate if Copland ships as a signed/notarized binary for non-developer users. |
| T-19-06 | Denial of Service | `mdfind` index spoofing to make `Godot.app not found` always fail | accept | Same threat model as T-19-05; an attacker who controls the Spotlight index already owns the box. Worst case: user sees the D-07 "install Godot 4.2+" error and runs Godot manually. No data loss, no privilege escalation. |

(No supply-chain row needed — this plan installs zero packages.)
</threat_model>

<verification>
Phase-level checks for this plan:

1. **Command registered:** `php copland list` shows `console` under Available commands.
2. **All Pest tests green:** `./vendor/bin/pest` shows 3 new passing tests under ConsoleCommandTest plus the full prior suite (132+) still passing.
3. **Style clean:** `./vendor/bin/pint --test app/Commands/ConsoleCommand.php tests/Feature/ConsoleCommandTest.php` reports zero violations.
4. **D-04 launch shape asserted in test:** captured commands include `['open', '-a', 'Godot', '--args', '--path', <abs>]`.
5. **D-06 honored:** no `-W` flag in source or test argv.
6. **D-07 probes asserted:** mdfind first, osascript fallback; both error strings present in display assertions.
7. **D-08 honored:** runner not invoked on missing-project test; `open` not present in captured commands on missing-Godot test.
8. **D-09 honored:** `base_path()` is the default resolver; `getcwd()` does not appear anywhere in the new code.
</verification>

<success_criteria>
- GODOT-03 satisfied: `copland console` is registered, runs preflight, launches Godot via `open -a Godot --args --path <abs>/console-godot/`, exits cleanly on success and non-zero with a targeted message on either preflight failure.
- All five CONTEXT.md decision IDs that apply to PHP (D-04, D-06, D-07, D-08, D-09) are enforced by tests, not just by code review.
- No regression to the existing test suite (must continue to pass 132+ tests).
- Pint reports no style violations on the new files.
</success_criteria>

<output>
Create `.planning/phases/19-prototype-recovery-console-launcher/19-02-SUMMARY.md` when done, including:
- Output of `php copland list | grep console` (proves registration)
- Output of `./vendor/bin/pest --filter='ConsoleCommand'` final line (3 passed)
- Output of `./vendor/bin/pest` final summary (full suite total — should be 135+ passing)
- Output of `./vendor/bin/pint --test app/Commands/ConsoleCommand.php tests/Feature/ConsoleCommandTest.php`
- Brief note on which probe ordering was chosen (mdfind-first per D-07 preferred) and exact argv shapes used
- Any deviations from D-04/D-06/D-07/D-08/D-09 (expected: none)
</output>

# Phase 19: Prototype Recovery + Console Launcher - Pattern Map

**Mapped:** 2026-05-26
**Files analyzed:** 9 (2 PHP to create, 7 Godot to restore verbatim)
**Analogs found:** 2 / 2 PHP files (Godot files are static restore — no analog needed)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Commands/ConsoleCommand.php` | command (CLI entry) | request-response (preflight → shell-out) | `app/Commands/AutomateCommand.php` | exact (Laravel Zero command with macOS shell-out + preflight + injectable runner) |
| `tests/Feature/ConsoleCommandTest.php` | test (feature) | request-response (mocked runner) | `tests/Feature/AutomateCommandTest.php` | exact (Pest test of a command with injected `$runner` callable + resolver closures via `CommandTester`) |
| `console-godot/project.godot` | static asset (restored) | n/a | none — restored verbatim from `backup/local-main-diverged-20260526` | static restore, no analog |
| `console-godot/icon.svg` | static asset (restored) | n/a | none | static restore, no analog |
| `console-godot/README.md` | static doc (restored) | n/a | none — D-02 forbids edits in this phase | static restore, no analog |
| `console-godot/TODO.md` | static doc (restored) | n/a | none — D-02 forbids edits in this phase | static restore, no analog |
| `console-godot/scenes/Main.tscn` | static asset (restored) | n/a | none | static restore, no analog |
| `console-godot/scripts/Main.gd` | static asset (restored) | n/a | none (GDScript, not PHP) | static restore, no analog |
| `console-godot/scripts/TaskLoader.gd` | static asset (restored) | n/a | none (GDScript, not PHP) | static restore, no analog |

**Static restore note for planner:** the 7 `console-godot/*` files come from `git checkout backup/local-main-diverged-20260526 -- console-godot/` (D-01). They are not authored, only restored — there are no PHP analog patterns to copy. The planner should treat the restore as a single git-checkout-plus-commit action, NOT as a code-authoring action. `console-godot/assets/{fonts,textures,themes}/` are already on `main` and the checkout leaves them untouched (verified by D-03).

---

## Pattern Assignments

### `app/Commands/ConsoleCommand.php` (command, preflight + shell-out)

**Analog:** `app/Commands/AutomateCommand.php`

**Why this analog:** It is the only existing command that (a) shells out to a macOS-specific binary via `Symfony\Component\Process\Process`, (b) accepts an injectable `$runner` callable for testability, (c) injects path/home resolver closures so tests don't touch real filesystem/PATH, and (d) returns a non-zero exit code on failure. `ConsoleCommand` repeats this exact shape for `open -a Godot`.

#### Imports + class header pattern (`AutomateCommand.php` lines 1-19)

```php
<?php

namespace App\Commands;

use App\Support\HomeDirectory;          // not needed by ConsoleCommand — uses base_path() instead
use App\Support\LaunchdPlist;           // not needed by ConsoleCommand
use App\Support\ProgressReporter;       // OPTIONAL — only if multi-step UI is wanted; for `console` a single line + error is fine
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

class AutomateCommand extends Command
{
    protected $signature = 'automate
        {--hour=2 : Hour for the nightly launchd run (0-23)}
        {--minute=0 : Minute for the nightly launchd run (0-59)}';

    protected $description = 'Install or refresh the macOS launchd job for nightly Copland runs';
```

**Copy for `ConsoleCommand`:**
- `namespace App\Commands;` — Laravel Zero auto-discovers
- Extend `LaravelZero\Framework\Commands\Command`
- `protected $signature = 'console';` (per CONTEXT.md "Claude's Discretion": no args for v2.0)
- `protected $description = 'Launch the Copland Console (Godot 4.2+ GUI pointed at ~/.copland/tasks/)';`
- Import `Symfony\Component\Process\Process` and `RuntimeException`

#### Constructor + injectable seam pattern (`AutomateCommand.php` lines 20-57)

```php
public function __construct(
    private ?LaunchdPlist $plistBuilder = null,
    private $runner = null,
    private $homeResolver = null,
    private $phpBinaryResolver = null,
    private $projectRootResolver = null,
    private $pathResolver = null,
) {
    parent::__construct();

    $this->plistBuilder ??= new LaunchdPlist;
    $this->homeResolver ??= static fn (): string => HomeDirectory::resolve();
    $this->phpBinaryResolver ??= static fn (): string => PHP_BINARY;
    $this->projectRootResolver ??= static fn (): string => base_path();
    ...
}
```

**Copy for `ConsoleCommand`:** the constructor takes nullable callable seams that default to real implementations. For `ConsoleCommand` the minimum seams are:
- `$runner` — replaces `Process` execution (used by both preflight probes and `open` launch)
- `$projectRootResolver` — defaults to `static fn (): string => base_path();` (per D-09, must use `base_path()`, NOT `getcwd()`)
- `$projectFileChecker` (optional but recommended for test isolation) — defaults to `static fn (string $path): bool => file_exists($path);` so tests can simulate missing `project.godot` without writing fixtures

The pattern is: every external-world touchpoint (process, filesystem, env) gets a closure seam with a real default. Tests inject closures; production passes nothing.

#### `handle()` shape — preflight then shell-out (`AutomateCommand.php` lines 59-97)

```php
public function handle(): int
{
    $progress = new ProgressReporter(totalSteps: 5);
    $hour = $this->validatedHour();
    ...
    $this->line($progress->step('Resolve launchd installation paths'));
    ...
    $this->line($progress->step('Reload launchd job'));
    $this->reloadLaunchAgent($plistPath);
    ...
    return self::SUCCESS;
}
```

**Copy for `ConsoleCommand`:** `handle(): int` returns `self::SUCCESS` (0) on success or `self::FAILURE` (1) on preflight failure. The flow per D-07/D-08 is:

1. Resolve `$projectRoot = ($this->projectRootResolver)();` then `$godotProjectDir = $projectRoot . '/console-godot';` and `$godotProjectFile = $godotProjectDir . '/project.godot';`
2. Preflight check #1: `if (! ($this->projectFileChecker)($godotProjectFile)) { $this->error('console-godot/ not found — run from the Copland project root or restore the prototype (see .planning/phases/19-...).'); return self::FAILURE; }`
3. Preflight check #2: probe Godot.app via runner (D-07 lists `mdfind "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"` OR `osascript -e 'id of app "Godot"'` — exact choice is Claude's Discretion). On failure: `$this->error('error: Godot.app not found — install Godot 4.2+ (brew install --cask godot, or https://godotengine.org/).'); return self::FAILURE;`
4. Only after both preflights pass: invoke `open -a Godot --args --path <abs>/console-godot/` via runner (per D-04, D-06: no `-W`, returns immediately)
5. `return self::SUCCESS;`

Use `$this->error(...)` (not `throw new RuntimeException(...)`) inside a command for preflight failures — `AutomateCommand` reserves `RuntimeException` for true operational failures during execution (lines 104, 115, 128, 135, 150). Preflight messages are user-facing diagnostics, not exceptions. (`StatusCommand` and the success paths of `AutomateCommand` confirm `$this->line()` / `$this->error()` is the command-context idiom — see CONVENTIONS.md "Logging".)

#### Process-runner seam pattern (`AutomateCommand.php` lines 154-168)

```php
private function runShellCommand(array $command): array
{
    if ($this->runner !== null) {
        return ($this->runner)($command);
    }

    $process = new Process($command);
    $process->run();

    return [
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
        'exitCode' => $process->getExitCode() ?? 1,
    ];
}
```

**Copy verbatim** for `ConsoleCommand::runShellCommand()`. The return shape `['stdout' => string, 'stderr' => string, 'exitCode' => int]` is the project-wide convention (also used by `GitService::execute()` at `app/Services/GitService.php` lines 131-145).

Exact commands `ConsoleCommand` will pass through this seam (concrete shapes from D-04/D-07):
- Preflight probe (one of): `['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"]` or `['osascript', '-e', 'id of app "Godot"']`
- Launch: `['open', '-a', 'Godot', '--args', '--path', $absGodotDir]` (note: no `-W` per D-06)

Branch on `$result['exitCode'] !== 0` to decide preflight success/failure, exactly like `AutomateCommand::reloadLaunchAgent()` lines 149-151:

```php
$load = $this->runShellCommand(['launchctl', 'load', $plistPath]);
if ($load['exitCode'] !== 0) {
    throw new RuntimeException('launchctl load failed: '.trim($load['stderr']));
}
```

(In `ConsoleCommand`, swap the `throw` for `$this->error(...); return self::FAILURE;` because preflight failures should be diagnostic, not exceptional.)

#### Supporting reference: `RunCommand.php` for command structure consistency

`RunCommand.php` line 36 shows the simpler signature shape (`'run {repo? : ...}'`) and lines 42-49 show another constructor seam pattern. `ConsoleCommand` is structurally simpler than `RunCommand` and closer to `AutomateCommand`, but if the planner wants to confirm registration is automatic, both files demonstrate it — no service-provider edit is needed.

`StatusCommand.php` (full file, 17 lines) is the minimal Laravel Zero command shape and confirms `extends LaravelZero\Framework\Commands\Command` + `protected $signature` + `protected $description` + `public function handle()` is all that's needed for auto-discovery.

---

### `tests/Feature/ConsoleCommandTest.php` (test, feature)

**Analog:** `tests/Feature/AutomateCommandTest.php`

**Why this analog:** Both test a Laravel Zero command that shells out to macOS and accepts an injectable `$runner`. `AutomateCommandTest` uses `Symfony\Component\Console\Tester\CommandTester` to drive the command, captures runner invocations into a `&$commands` reference array, and asserts both the captured command list and the display output. Identical pattern applies to `ConsoleCommand` — capture `['open', '-a', 'Godot', ...]` invocations and preflight probe invocations, assert preflight failures produce the expected error messages and non-zero exit.

**Test location decision:** `tests/Feature/` (not `tests/Unit/`) — `AutomateCommandTest` lives in Feature, and ConsoleCommand similarly integrates command lifecycle + multiple injected resolvers + display output. This matches the existing convention.

#### Imports + harness setup (`AutomateCommandTest.php` lines 1-6)

```php
<?php

use App\Commands\AutomateCommand;
use App\Support\LaunchdPlist;
use Symfony\Component\Console\Tester\CommandTester;

it('writes the launch agent plist and reloads launchctl through the automate command', function () {
```

**Copy for ConsoleCommandTest:**
- No namespace declaration (Pest convention — see TESTING.md "Structure")
- `use App\Commands\ConsoleCommand;`
- `use Symfony\Component\Console\Tester\CommandTester;`
- Multiple `it(...)` blocks, one per scenario (success, missing `console-godot/`, missing Godot.app)

#### Command instantiation + closure injection (`AutomateCommandTest.php` lines 13-30)

```php
$command = new AutomateCommand(
    plistBuilder: new LaunchdPlist,
    runner: function (array $command) use (&$commands): array {
        $commands[] = $command;

        if ($command[1] === 'unload') {
            return ['stdout' => '', 'stderr' => 'not loaded', 'exitCode' => 1];
        }

        return ['stdout' => 'ok', 'stderr' => '', 'exitCode' => 0];
    },
    homeResolver: fn (): string => $home,
    phpBinaryResolver: fn (): string => '/opt/homebrew/bin/php',
    projectRootResolver: fn (): string => '/Users/tester/projects/copland',
    pathResolver: fn (): string => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin',
);
$command->setLaravel($this->app);

$tester = new CommandTester($command);
$exitCode = $tester->execute(['--hour' => '3', '--minute' => '15']);
$display = $tester->getDisplay();
```

**Copy for `ConsoleCommandTest`:** the success-path test should look like:

```php
$commands = [];
$command = new ConsoleCommand(
    runner: function (array $command) use (&$commands): array {
        $commands[] = $command;
        // Godot.app probe succeeds; open succeeds
        return ['stdout' => '/Applications/Godot.app', 'stderr' => '', 'exitCode' => 0];
    },
    projectRootResolver: fn (): string => '/Users/tester/projects/copland',
    projectFileChecker: fn (string $path): bool => true,  // pretend console-godot/project.godot exists
);
$command->setLaravel($this->app);

$tester = new CommandTester($command);
$exitCode = $tester->execute([]);

expect($exitCode)->toBe(0);
expect($commands)->toContain(
    ['open', '-a', 'Godot', '--args', '--path', '/Users/tester/projects/copland/console-godot/']
);
```

The `$command->setLaravel($this->app);` line is **mandatory** when constructing a command outside the kernel — without it, `$this->line()` / `$this->error()` calls inside `handle()` will fail because the IO bindings aren't initialized. (`AutomateCommandTest` line 29 demonstrates this.)

#### Pest closure-pattern with match() runner — the project's canonical mock

`tests/Unit/GitServiceTest.php` lines 8-20 (and TESTING.md "Mocking" section) show the project's canonical runner mock using `match()` to dispatch on command shape and throw on unexpected commands:

```php
$git = new GitService(function (array $command, string $cwd) use (&$calls): array {
    $calls[] = $command;

    return match ($command) {
        ['git', 'status', '--porcelain'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
        ['git', 'fetch', 'origin'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
        ...
        default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
    };
});
```

**Apply to `ConsoleCommandTest`** for the preflight-failure scenarios — use `match()` to dispatch on the first argv element (`mdfind` / `osascript` / `open`) and return canned exit codes. The `default => throw` arm catches any unexpected shell-out and surfaces test-logic errors loudly (per TESTING.md "What to Mock"). This is preferable to `AutomateCommandTest`'s `if ($command[1] === 'unload')` branch for `ConsoleCommandTest` because the multi-command preflight is closer in shape to `GitServiceTest` than to `AutomateCommandTest`'s two-call sequence.

#### Assertion patterns (`AutomateCommandTest.php` lines 35-46)

```php
expect($exitCode)->toBe(0);
expect($display)->toContain('Installed plist: '.$home.'/Library/LaunchAgents/com.binarygary.copland.plist');
expect($display)->toContain('Label: com.binarygary.copland');
...
expect($commands)->toBe([
    ['launchctl', 'unload', $home.'/Library/LaunchAgents/com.binarygary.copland.plist'],
    ['launchctl', 'load', $home.'/Library/LaunchAgents/com.binarygary.copland.plist'],
]);
```

**Copy for `ConsoleCommandTest`** — assert:
1. `$exitCode` (0 for success, 1 for each preflight failure)
2. `$display` contains the expected error string (D-07 exact wording: `'console-godot/ not found'` or `'Godot.app not found'`)
3. `$commands` array — for success, contains the `open -a Godot ...` invocation; for preflight failures, does NOT contain `open` (proves D-08: no launch on preflight failure)

Use `expect($commands)->not->toContain([...])` for the "no launch attempted" assertion in failure-path tests.

#### Cleanup pattern

`AutomateCommandTest` writes to a temp `$home = '/tmp/copland-automate-command-'.uniqid();` directory (line 8) and does not explicitly clean up (relies on temp directory). `ConsoleCommandTest` does NOT need a temp directory — all filesystem interaction is mocked via `$projectFileChecker` closure. This is a simplification over the analog.

---

## Shared Patterns

### Process execution return shape
**Source:** `app/Services/GitService.php` lines 131-145, `app/Commands/AutomateCommand.php` lines 154-168
**Apply to:** `ConsoleCommand::runShellCommand()`

Project-wide canonical shape for shell-out results:
```php
return [
    'stdout' => $process->getOutput(),
    'stderr' => $process->getErrorOutput(),
    'exitCode' => $process->getExitCode() ?? 1,
];
```

Always coalesce `getExitCode()` with `?? 1` — `Process::getExitCode()` returns `null` if the process never started, and `null` must surface as failure.

### Project-root resolution via `base_path()`
**Source:** `app/Commands/AutomateCommand.php` line 33 — `$this->projectRootResolver ??= static fn (): string => base_path();`
**Apply to:** `ConsoleCommand` — per D-09, the `console-godot/` path MUST be absolute relative to the Copland project root, NOT the CWD. `base_path()` is Laravel Zero's global helper for this. Wrap it in a resolver closure so tests can inject a fixed path.

### Error-message style in commands
**Source:** CONVENTIONS.md "Logging" + CONVENTIONS.md "Error Handling"
**Apply to:** `ConsoleCommand`

- `$this->error(...)` for user-facing diagnostic messages that don't kill the process via exception (preflight failures fall here)
- `throw new RuntimeException(...)` only for truly unexpected operational failures (none expected in `ConsoleCommand` — preflights catch everything)
- Error messages include the remediation hint inline (D-07 wording: `"error: Godot.app not found — install Godot 4.2+ (brew install --cask godot, or https://godotengine.org/)."`)

### Pest closure-style test with `&$calls` capture array
**Source:** `tests/Unit/GitServiceTest.php` lines 6-20, TESTING.md "Mocking" + "Common Patterns"
**Apply to:** `tests/Feature/ConsoleCommandTest.php`

```php
$calls = [];
$service = new X(function (array $command) use (&$calls): array {
    $calls[] = $command;
    return match ($command) {
        [...] => [...],
        default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
    };
});
// ... act
expect($calls)->toBe([...]);  // order-sensitive
```

---

## No Analog Found

| File | Role | Reason |
|------|------|--------|
| `console-godot/project.godot` | static asset | Restored verbatim from `backup/local-main-diverged-20260526` (D-01, D-03). No PHP analog. Planner action: `git checkout backup/local-main-diverged-20260526 -- console-godot/project.godot`. |
| `console-godot/icon.svg` | static asset | Same — static restore. |
| `console-godot/README.md` | static doc | Same — static restore. D-02 explicitly forbids editing in this phase; doc alignment is Phase 22's job. |
| `console-godot/TODO.md` | static doc | Same — static restore. D-02 applies. |
| `console-godot/scenes/Main.tscn` | static asset | Same — static restore (Godot scene file). |
| `console-godot/scripts/Main.gd` | static asset | GDScript, not PHP. Static restore. The script's existing behavior (reads `HOME` directly) is what makes the `copland console` launch trivial — confirmed by `code_context` in CONTEXT.md. |
| `console-godot/scripts/TaskLoader.gd` | static asset | GDScript, not PHP. Static restore. Reads `~/.copland/tasks/` via `OS.get_environment("HOME")` — see CONTEXT.md `specifics`. |

**Planner action for all 7:** single `git checkout backup/local-main-diverged-20260526 -- console-godot/` command will restore all of them at once (D-01 / D-03 — `console-godot/assets/` already on `main` will be left untouched because they match the backup tree). Wrap in one commit with a message like "restore Godot prototype from backup branch (Phase 19)". No code authoring, no pattern extraction, no Pest tests for these files.

---

## Metadata

**Analog search scope:**
- `app/Commands/*.php` (6 files: AutomateCommand, IssuesCommand, PlanCommand, RunCommand, SetupCommand, StatusCommand)
- `app/Services/GitService.php` (for `$runner` callable seam pattern)
- `tests/Feature/*.php` (6 files — selected AutomateCommandTest as closest)
- `tests/Unit/GitServiceTest.php` (for canonical match()-based runner mock pattern)

**Files read in full or extracted from:**
- `app/Commands/AutomateCommand.php` (full, 169 lines)
- `app/Commands/StatusCommand.php` (full, 17 lines)
- `app/Commands/RunCommand.php` (full, 449 lines)
- `app/Services/GitService.php` (full, 146 lines)
- `tests/Feature/AutomateCommandTest.php` (full, 46 lines)
- `tests/Unit/GitServiceTest.php` (lines 1-60)

**Pattern extraction date:** 2026-05-26

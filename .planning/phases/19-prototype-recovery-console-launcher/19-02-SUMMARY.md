---
phase: 19-prototype-recovery-console-launcher
plan: 02
subsystem: cli-commands
tags: [laravel-zero, command, godot, macos, preflight]
requires:
  - 19-01 (console-godot/project.godot restored on disk for end-to-end manual verification; tests stub it)
provides:
  - "Laravel Zero `console` command: `php copland console` launches Godot 4.2+ on the restored prototype"
  - "Injectable-runner pattern application: `runner` + `projectRootResolver` + `projectFileChecker` closure seams (mirrors AutomateCommand)"
affects:
  - app/Commands/ (one new command registered automatically by Laravel Zero discovery)
tech-stack:
  added: []  # No new dependencies — uses Symfony Process already in composer.json
  patterns:
    - Injectable closure seam (`$runner = null` in constructor, `??=` defaults)
    - Two-stage preflight before shell-out (filesystem check → app-locatable probe)
    - match()-based runner mock with `default => throw` (canonical from GitServiceTest)
key-files:
  created:
    - app/Commands/ConsoleCommand.php
    - tests/Feature/ConsoleCommandTest.php
  modified: []
decisions:
  - "D-04 enforced in code AND test: launch argv is `['open', '-a', 'Godot', '--args', '--path', <abs>]`"
  - "D-06 enforced in code AND test: no `-W` flag — fire-and-forget"
  - "D-07 enforced in code AND test: mdfind is the preferred probe; osascript is the fallback; ordering is asserted"
  - "D-08 enforced in code AND test: preflight failures short-circuit before any shell-out; tests assert runner was never called (Test 2) and that `open` is absent from captured commands (Test 3)"
  - "D-09 enforced in code AND test: default `projectRootResolver` uses `base_path()`; `getcwd()` does not appear in the source; tests inject a fixed absolute root"
metrics:
  duration: ~5m
  tasks: 2
  files_created: 2
  files_modified: 0
  tests_added: 3
  tests_total: 137 (was 134)
  pest_assertions: 443
  completed: 2026-05-27
---

# Phase 19 Plan 02: Console Command Summary

**One-liner:** New `copland console` Laravel Zero command runs `mdfind`/`osascript` preflight then `open -a Godot --args --path <abs>/console-godot` fire-and-forget, with three Pest tests covering the success path and both preflight failures via an injected `$runner` closure.

## What Was Built

A single Laravel Zero command (`app/Commands/ConsoleCommand.php`) plus its Pest test (`tests/Feature/ConsoleCommandTest.php`). The command:

1. Resolves the Copland project root via `base_path()` (D-09 — never `getcwd()`).
2. Preflight #1: confirms `console-godot/project.godot` exists via the injectable `$projectFileChecker` closure (D-07/D-08). On failure: emits `console-godot/ not found — run from the Copland project root...` and returns `self::FAILURE` without invoking any shell-out.
3. Preflight #2: probes Godot.app via `mdfind "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"` (preferred). If `mdfind` exits 0 but stdout is empty (no hit), falls back to `osascript -e 'id of app "Godot"'`. On both-fail: emits `error: Godot.app not found — install Godot 4.2+ (brew install --cask godot, or https://godotengine.org/).` and returns `self::FAILURE` without invoking `open`.
4. Launch: runs `open -a Godot --args --path <abs>/console-godot` (D-04, D-06 — no `-W`), prints a one-line confirmation, returns `self::SUCCESS`.

All shell-outs flow through `private runShellCommand(array): array` returning the project-wide canonical `['stdout', 'stderr', 'exitCode']` shape (copied verbatim from `AutomateCommand::runShellCommand`).

## Probe Ordering Chosen

**mdfind first, osascript fallback** — per D-07 ("Preferred probe: `mdfind`... fallback probe: `osascript`"). Reasoning baked into code: mdfind returns exit 0 even when there are no hits (empty stdout is the "not found" signal), so the locatable check is `exitCode === 0 AND trim(stdout) !== ''`. osascript is only invoked when mdfind reports zero hits. Test 3 asserts both probes ran in that order when neither succeeded.

## Exact Argv Shapes Used

| Purpose | argv |
|---|---|
| Spotlight probe | `['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"]` |
| Launch Services probe | `['osascript', '-e', 'id of app "Godot"']` |
| Launch | `['open', '-a', 'Godot', '--args', '--path', '/abs/.../console-godot']` (no trailing slash, no `-W`) |

The launch path uses **no trailing slash** (`console-godot`, not `console-godot/`). Godot accepts both, and the tests assert the no-trailing-slash form to match the source.

## Verification Output

### `php copland list | grep console`
```
console      Launch the Copland Console (Godot 4.2+ GUI pointed at ~/.copland/tasks/)
```

### `./vendor/bin/pest --filter='ConsoleCommand'`
```
Tests:    3 passed (10 assertions)
Duration: 0.20s
```

### `./vendor/bin/pest` (full suite)
```
Tests:    137 passed (443 assertions)
Duration: 0.87s
```

(Was 134 prior to this plan; the +3 are the new `ConsoleCommandTest` cases. No regressions.)

### `./vendor/bin/pint --test app/Commands/ConsoleCommand.php tests/Feature/ConsoleCommandTest.php`
```
{"tool":"pint","result":"passed"}
```

## Tasks Completed

| Task | Name | Commit | Files |
|---|---|---|---|
| 1 | Create `app/Commands/ConsoleCommand.php` with injectable preflight + open shell-out | `226db81` | `app/Commands/ConsoleCommand.php` |
| 2 | Create `tests/Feature/ConsoleCommandTest.php` covering success + both preflight failures | `d3da678` | `tests/Feature/ConsoleCommandTest.php` |

## Deviations from Plan

**None on D-04 / D-06 / D-07 / D-08 / D-09.** Every decision ID is enforced by both source and tests.

**One environmental footnote (not a deviation):** The agent's git worktree was spawned without a `vendor/` directory. Running `composer install --no-interaction --prefer-dist` was required before `./vendor/bin/pint` and `./vendor/bin/pest` were available. This installs the same dependencies the main worktree already has (`composer.lock` was not modified), and `vendor/` is gitignored, so no new files were committed and no `composer.json`/`composer.lock` changes occurred. The orchestrator may want to ensure parallel-executor worktrees inherit or share `vendor/` to avoid repeating this install cost on future PHP-touching plans.

## Authentication Gates

None. The command does not touch GitHub, Anthropic, or any authenticated service.

## Known Stubs

None. The command is fully functional end-to-end; the only abstraction is the injectable runner (production default is real `Symfony\Component\Process\Process` execution).

## Threat Flags

None new. The plan's `<threat_model>` covers all shell-out surfaces; argv arrays go straight to `execvp` via `new Process($command)` (no shell interpretation), which Test 1 implicitly asserts by checking exact argv shape rather than a stringified command.

## Self-Check: PASSED

- `app/Commands/ConsoleCommand.php` — FOUND (89 lines, Pint clean, `php -l` clean, registered by `copland list`)
- `tests/Feature/ConsoleCommandTest.php` — FOUND (107 lines, Pint clean, 3 tests pass)
- Commit `226db81` — FOUND on `worktree-agent-a70d2826f2191fb06`
- Commit `d3da678` — FOUND on `worktree-agent-a70d2826f2191fb06`
- D-04 launch argv asserted in Test 1: confirmed via `expect($commands)->toContain(['open', '-a', 'Godot', '--args', '--path', ...])`
- D-06 (no `-W`): confirmed by absence in source and absence in test argv
- D-07 probe order: asserted by `expect($commands[0])->toBe(['mdfind', ...])` in Test 1 and by `expect($commands)->toBe([['mdfind', ...], ['osascript', ...]])` in Test 3
- D-08 (no launch on preflight failure): asserted by `expect($commands)->toBe([])` in Test 2 and `expect($commands)->not->toContain(['open', ...])` in Test 3
- D-09 (`base_path()`, not `getcwd()`): confirmed via source grep (`base_path()` present, `getcwd` absent)

---
phase: 18-automate-command
plan: "01"
subsystem: commands
tags: [rename, deprecation, launchd, automate, backward-compat]
dependency_graph:
  requires: []
  provides: [AutomateCommand, SetupCommand-wrapper]
  affects: [app/Commands/AutomateCommand.php, app/Commands/SetupCommand.php]
tech_stack:
  added: []
  patterns: [thin-deprecated-wrapper, constructor-injection, artisan-delegation]
key_files:
  created:
    - app/Commands/AutomateCommand.php
    - tests/Feature/AutomateCommandTest.php
  modified:
    - app/Commands/SetupCommand.php
    - tests/Feature/SetupCommandTest.php
decisions:
  - SetupCommand delegates to automate via $this->call('automate') to route through the Artisan kernel with forwarded options
  - $hidden = true on SetupCommand hides it from copland --help while keeping it runnable
metrics:
  duration: ~8 min
  completed: "2026-04-09T02:02:42Z"
  tasks: 2
  files: 4
---

# Phase 18 Plan 01: Automate Command Rename Summary

**One-liner:** Renamed `copland setup` to `copland automate` by extracting full LaunchAgent logic into `AutomateCommand` and replacing `SetupCommand` with a hidden deprecated wrapper that delegates via `$this->call('automate')`.

## What Was Built

- `AutomateCommand` — new primary command (`signature: automate`) containing the complete LaunchAgent installation logic copied verbatim from the old `SetupCommand`, with constructor-injectable dependencies for testability
- `SetupCommand` — rewritten as a thin hidden wrapper (`$hidden = true`) that prints a deprecation notice and delegates to `automate` with forwarded `--hour` and `--minute` options
- `AutomateCommandTest` — full test coverage mirroring the original `SetupCommandTest` structure, exercising plist write and launchctl reload via injected mocks
- `SetupCommandTest` — updated to assert the deprecation notice output and the hidden flag, using `$this->artisan('setup')` for delegation chain integration testing

## Verification Results

1. `php copland automate --help` — shows command with `--hour` and `--minute` options
2. `php copland --help` — does NOT contain `setup` (hidden from help)
3. `php copland setup` — prints both deprecation lines, then delegates to automate and runs successfully
4. `./vendor/bin/pest tests/Feature/AutomateCommandTest.php tests/Feature/SetupCommandTest.php` — 3 passed
5. `./vendor/bin/pint --test` — passes for both command files

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Pint binary_operator_spaces fix on SetupCommand wrapper**
- **Found during:** Task 1 verify step
- **Issue:** Pint flagged `'--hour'   =>` alignment spacing as a style violation
- **Fix:** Ran `./vendor/bin/pint app/Commands/SetupCommand.php` to auto-fix spacing to `'--hour' =>`
- **Files modified:** app/Commands/SetupCommand.php
- **Commit:** 10c0b9c (pint applied before commit)

None other — plan executed as written.

## Known Stubs

None — all functionality is wired. AutomateCommand has live LaunchAgent installation logic; SetupCommand delegates to it.

## Threat Flags

No new threat surface introduced. The `automate` command's attack surface (launchctl, user LaunchAgent) was already present in `setup`. The delegation wrapper adds no new network endpoints, auth paths, or file access patterns.

## Commits

| Hash | Message |
|------|---------|
| 10c0b9c | feat(18-01): create AutomateCommand and rewrite SetupCommand as deprecated wrapper |
| f01df4c | test(18-01): add AutomateCommandTest and update SetupCommandTest for wrapper behavior |

## Self-Check: PASSED

Files verified:
- FOUND: app/Commands/AutomateCommand.php
- FOUND: app/Commands/SetupCommand.php
- FOUND: tests/Feature/AutomateCommandTest.php
- FOUND: tests/Feature/SetupCommandTest.php

Commits verified:
- FOUND: 10c0b9c
- FOUND: f01df4c

# Phase 7, Plan 1: SetupCommand and LaunchAgent plist generation - Summary

**Completed:** 2026-04-03
**Status:** Success

## Accomplishments

### 1. LaunchAgent Plist Foundation
- Added `App\Support\LaunchdPlist` to generate a stable per-user LaunchAgent plist for `com.binarygary.copland`.
- The generated plist includes:
  - explicit `HOME` and `PATH` environment variables
  - `WorkingDirectory` set to the Copland checkout
  - `ProgramArguments` that run the local `copland run` command via PHP
  - nightly `StartCalendarInterval`
  - stdout/stderr log paths under `~/.copland/logs/launchd/`

### 2. Setup Command Installation Flow
- Added `App\Commands\SetupCommand` with `--hour` and `--minute` schedule inputs.
- The command now creates the LaunchAgents directory and Copland launchd log directory, writes the plist idempotently, reloads the LaunchAgent with `launchctl`, and prints verification details including the plist path, label, and manual `launchctl start` command.

### 3. Verification
- Added `tests/Unit/LaunchdPlistTest.php` for plist structure assertions.
- Added `tests/Feature/SetupCommandTest.php` for plist installation and mocked launchctl reload coverage.
- Ran:
  - `php -l app/Support/LaunchdPlist.php`
  - `php -l app/Commands/SetupCommand.php`
  - `./vendor/bin/pest tests/Unit/LaunchdPlistTest.php tests/Feature/SetupCommandTest.php`

## Residuals

- Plan 07-02 is blocked only on real macOS verification: running `copland setup` and confirming the LaunchAgent registers and starts successfully with `launchctl`.

---
*Phase: 07-launchd-setup*
*Plan: 01*

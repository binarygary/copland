# Phase 18 Context — Automate Command

## Phase Goal

Rename `copland setup` to `copland automate`. Keep `setup` as a hidden deprecated alias that prints a notice and delegates.

## Requirements in Scope

- **AUTO-01**: `copland automate` installs the macOS LaunchAgent (identical behavior to current `copland setup`)
- **AUTO-02**: `copland setup` shows a deprecation notice, then delegates to `copland automate`

## Decisions

### Rename approach
Rename `SetupCommand.php` to `AutomateCommand.php` with signature `automate`. Create a thin `SetupCommand` wrapper that handles the deprecation notice and delegates.

### Deprecation notice behavior (AUTO-02)
Option B: print the deprecation warning, then explicitly say "Running `copland automate`..." before delegating. Do not pause or ask for confirmation.

```
⚠ `copland setup` is deprecated — use `copland automate` instead.
Running `copland automate`...
[normal automate output follows]
```

### Help text visibility
`copland setup` must be hidden from `copland --help`. Use Laravel Zero's `$hidden = true` on the deprecated `SetupCommand`. This is a temporary measure — a future phase will fully remove the `setup` alias.

### Test impact
- `tests/Feature/SetupCommandTest.php` exists and covers the current `setup` command
- Tests must be updated: add `AutomateCommand` tests, update `SetupCommand` tests to assert the deprecation notice and delegation behavior

## Existing Code Reference

- `app/Commands/SetupCommand.php` — full LaunchAgent install logic (rename to `AutomateCommand.php`)
- `app/Support/LaunchdPlist.php` — plist builder (unchanged)
- `tests/Feature/SetupCommandTest.php` — existing test coverage

## Out of Scope

- Full removal of `copland setup` — deferred to a future phase
- Linux systemd support — out of scope for this milestone

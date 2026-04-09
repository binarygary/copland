---
phase: 18
slug: automate-command
status: complete
nyquist_compliant: true
requirements:
  - AUTO-01
  - AUTO-02
gaps_found: 0
gaps_resolved: 0
gaps_manual: 0
updated: "2026-04-09"
---

# Phase 18 Validation: Automate Command

## Test Infrastructure

| Tool | Config | Run Command |
|------|--------|-------------|
| PestPHP | `phpunit.xml.dist` | `./vendor/bin/pest` |

## Per-Task Requirement Map

| Task | Requirement | Test File | Status |
|------|-------------|-----------|--------|
| Task 1: Create AutomateCommand + rewrite SetupCommand | AUTO-01 | `tests/Feature/AutomateCommandTest.php` | COVERED |
| Task 1: Create AutomateCommand + rewrite SetupCommand | AUTO-02 | `tests/Feature/SetupCommandTest.php` | COVERED |
| Task 2: AutomateCommandTest + SetupCommandTest | AUTO-01 | `tests/Feature/AutomateCommandTest.php` | COVERED |
| Task 2: AutomateCommandTest + SetupCommandTest | AUTO-02 | `tests/Feature/SetupCommandTest.php` | COVERED |

## Coverage Detail

### AUTO-01 — `copland automate` installs the macOS LaunchAgent

**Test:** `tests/Feature/AutomateCommandTest.php`
- `it('writes the launch agent plist and reloads launchctl through the automate command')` — instantiates `AutomateCommand` directly with injected runner/resolver mocks, executes `--hour=3 --minute=15`, asserts plist written, log directory created, launchctl unload+load called in sequence, exit code 0.

**Verdict:** COVERED — full happy-path with mock isolation.

### AUTO-02 — `copland setup` is a deprecated hidden alias

**Test:** `tests/Feature/SetupCommandTest.php`
- `it('setup command prints the deprecation notice')` — asserts both deprecation lines appear in output via `$this->artisan('setup')`.
- `it('setup command is hidden from help')` — asserts `$command->isHidden()` returns true.

**Verdict:** COVERED — deprecation notice and hidden flag both explicitly asserted.

## Manual-Only

None.

## Sign-Off

No gaps found — all AUTO-01 and AUTO-02 behaviors have automated test coverage. Phase is Nyquist-compliant.

## Validation Audit 2026-04-09

| Metric | Count |
|--------|-------|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |

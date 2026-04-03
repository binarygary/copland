---
phase: quick
plan: 260402-u6u
subsystem: quality-tooling
tags: [phpstan, pint, pest, composer-scripts, code-quality]
dependency_graph:
  requires: []
  provides: [composer-test-script, composer-lint-script, composer-analyse-script, phpstan-config]
  affects: [all-php-files]
tech_stack:
  added: [phpstan/phpstan ^2.1]
  patterns: [phpstan-neon-config, pint-auto-format, pest-no-coverage]
key_files:
  created:
    - phpstan.neon
  modified:
    - composer.json
    - app/Support/IssueFileHintExtractor.php
    - (31 additional files auto-formatted by Pint)
decisions:
  - PHPStan level 5 passes clean after fixing one nullCoalesce.offset false positive
  - Removed redundant `?? []` on preg_match_all result (index 1 is always set)
metrics:
  duration: ~10 minutes
  completed: 2026-04-03
  tasks_completed: 2
  files_changed: 33
---

# Quick 260402-u6u: Add Quality Tooling Summary

**One-liner:** Wired Pest, Pint, and PHPStan into single-command quality gate via composer scripts with zero errors at PHPStan level 5.

## What Was Done

Task 1 (already committed as `1c2e3f1`):
- PHPStan (`^2.1`) added as dev dependency (was already in composer.json before this task ran, suggesting prior partial work)
- Removed `tests/Feature/InspireCommandTest.php` (scaffolding remnant calling non-existent `artisan inspire`)
- Composer scripts: `test`, `lint`, `lint:check`, `analyse` added to composer.json

Task 2 (committed as `1df6848`):
- Created `phpstan.neon` at project root — level 5, targets `app/`, excludes `vendor/`
- Auto-formatted all PHP files via `./vendor/bin/pint` (~31 files reformatted)
- Fixed one PHPStan error in `IssueFileHintExtractor.php`: removed redundant `?? []` null coalesce on `$matches[1]` — PHPStan correctly identified the offset always exists on `preg_match_all` output

## Verification Results

All three quality gates exit 0:
- `composer test`: 38 passed, 98 assertions, 0.26s
- `composer lint:check`: `{"result":"pass"}`
- `composer analyse`: `[OK] No errors`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed PHPStan nullCoalesce.offset error in IssueFileHintExtractor**
- **Found during:** Task 2 verification
- **Issue:** `$matches[1] ?? []` — PHPStan knows `preg_match_all` always populates index 1 when a capture group exists; the `?? []` is unreachable and triggers `nullCoalesce.offset`
- **Fix:** Changed `$matches[1] ?? []` to `$matches[1]`
- **Files modified:** `app/Support/IssueFileHintExtractor.php`
- **Commit:** 1df6848

## Commits

| Task | Description | Hash |
|------|-------------|------|
| Task 1 | Install PHPStan, add composer scripts, remove broken test | 1c2e3f1 |
| Task 2 | Add phpstan.neon, apply Pint formatting, fix PHPStan error | 1df6848 |

## Self-Check: PASSED

- phpstan.neon exists: FOUND
- composer.json scripts present: VERIFIED (test, lint, lint:check, analyse)
- composer test: 38 passed
- composer lint:check: pass
- composer analyse: no errors
- Commits 1c2e3f1 and 1df6848: FOUND in git log

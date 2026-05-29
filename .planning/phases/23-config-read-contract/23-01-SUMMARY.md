---
phase: 23-config-read-contract
plan: 01
subsystem: config-read
tags: [config, cli, json-contract, redaction, pest, tdd]
dependency_graph:
  requires: []
  provides:
    - "copland config:show {--json} CLI surface (v1 JSON schema)"
    - "App\\Services\\ConfigShowService::snapshot() reusable PHP builder"
    - "tests/fixtures/config/show-snapshot.json canonical contract"
  affects:
    - "Phases 24-26 (config write subcommands) — read-back assertions can use the same snapshot shape"
    - "Phase 27-29 (Godot console) — JSON consumer pinned against the fixture"
tech_stack:
  added: []
  patterns:
    - "Preflight-before-construct: filesystem probe before `new GlobalConfig` so the bootstrap auto-create cannot mask missing-file errors"
    - "JSON-only stdout discipline via OutputInterface::OUTPUT_RAW (bypasses Symfony Console styling)"
    - "Stderr routing via ConsoleOutputInterface::getErrorOutput() with fwrite(STDERR) fallback"
    - "RAW Yaml::parseFile() for per-repo `.copland.yml` (deliberately bypasses RepoConfig accessors which fill defaults)"
key_files:
  created:
    - "tests/fixtures/config/show-snapshot.json"
    - "app/Services/ConfigShowService.php"
    - "app/Commands/ConfigShowCommand.php"
    - "tests/Unit/ConfigShowServiceTest.php"
    - "tests/Feature/ConfigShowCommandTest.php"
  modified: []
decisions:
  - "Asana token redaction: `strlen(trim($token)) > 0` — empty AND whitespace-only both -> false. Raw value never enters the snapshot graph."
  - "JSON encoding: `json_encode($snapshot, JSON_UNESCAPED_SLASHES)` + single trailing newline. Compact one-liner — no pretty-printing in v1 (deferred per CONTEXT.md)."
  - "Per-repo `local_config` is the raw parsed YAML or null. We do NOT instantiate RepoConfig because (a) its ctor auto-creates a default file with `ensureExists()` and (b) its accessors fill schema defaults — both would corrupt the 'as written' contract."
  - "Preflight order: file-exists (filesystem probe) -> ParseException (try/catch around `new GlobalConfig`) -> repo-path is_dir(). First failure short-circuits with non-zero exit + stderr message."
  - "CommandTester error tests use `['capture_stderr_separately' => true]` to assert stdout/stderr split explicitly — the invariant being verified is that `--json` stdout is pure (empty on error, JSON on success)."
metrics:
  duration: "single session, ~25 minutes wall-clock"
  completed: "2026-05-29"
  tests_added: 16
  total_tests_passing: 216
  total_assertions: 747
---

# Phase 23 Plan 23-01: Config Read Contract Summary

Shipped `copland config:show {--json}` — a Laravel Zero command + supporting service that emits the v1 JSON snapshot of the merged global + per-repo configuration that every downstream Godot config screen (Phases 24-29) will read instead of parsing YAML.

## Outcome

**Status: complete.** All 4 plan tasks GREEN, 16 new Pest tests passing, full suite green at 216/216.

## Files Created

| Path | Role |
|------|------|
| `tests/fixtures/config/show-snapshot.json` | Canonical v1 schema example — referenced by Pest shape assertion and command `--help` text |
| `app/Services/ConfigShowService.php` | Pure-PHP snapshot builder. Single `snapshot(): array` entry point. |
| `app/Commands/ConfigShowCommand.php` | Laravel Zero command with signature `config:show {--json}`. Owns preflight + stdout/stderr discipline. |
| `tests/Unit/ConfigShowServiceTest.php` | 8 unit tests: shape match against fixture, four redaction variants, missing per-repo YAML, raw-YAML invariant, defaults sourced from GlobalConfig accessors |
| `tests/Feature/ConfigShowCommandTest.php` | 8 feature tests: 4 happy-path (JSON shape, JSON-only stdout, end-to-end redaction, human mode) + 4 error-path (missing global, malformed YAML, missing repo path, JSON-mode stdout-empty-on-error) |

## CFG-01 Coverage Map

| Roadmap Success Criterion | Tests | Notes |
|---------------------------|-------|-------|
| #1 JSON snapshot with correct shape (incl. `asana_token_set` boolean) | Unit T1, Feature T1, T3 | Top-level keys + per-repo keys both asserted against the fixture. Raw asana_token never appears (Unit T2 + Feature T3). |
| #2 Exit 0 on success / non-zero + stderr on missing or malformed global config | Feature T1, T4 (happy), T5, T6, T8 (error) | Stdout/stderr disambiguated via `capture_stderr_separately`. |
| #3 Schema documented for downstream consumers | Fixture file + command `$description` | `php copland help config:show` mentions `tests/fixtures/config/show-snapshot.json`. |
| #4 Pest tests assert JSON shape against fixture + token redaction | Unit T1 (shape vs fixture) + Unit T2-T5 (redaction) + Feature T3 (end-to-end redaction) | |

Additional CONTEXT.md invariants verified:
- Per-repo `local_config` exposes raw YAML, not defaults — Unit T7.
- Missing per-repo `.copland.yml` is NOT an error — Unit T6.
- Missing global config / parse error / missing repo path ARE errors — Feature T5/T6/T7.
- `--json` stdout is exclusively the JSON document — Feature T2 (asserts exact `json_encode + \n` byte match) and Feature T8 (asserts stdout empty on error).

## Commits

| # | Hash | Subject |
|---|------|---------|
| 1 | `6851e41` | `chore(23-01): add canonical config:show snapshot fixture (v1 schema)` |
| 2 | `3f666cc` | `test(23-01): add failing tests for ConfigShowService` (RED) |
| 3 | `aea5be6` | `feat(23-01): implement ConfigShowService snapshot builder` (GREEN) |
| 4 | `0294371` | `test(23-01): add failing tests for config:show command (happy path)` (RED) |
| 5 | `f8f2ef4` | `feat(23-01): implement config:show command` (GREEN) |
| 6 | `4f3c56c` | `test(23-01): add failing tests for config:show error paths` (RED) |
| 7 | `469cfff` | `feat(23-01): add error preflight to config:show` (GREEN) |

TDD gate sequence honored for all 3 behavior-adding tasks (Tasks 2-4): test commit precedes feat commit in every case.

## Test Counts

- **Baseline (before plan):** 200 tests, 705 assertions
- **After plan:** 216 tests, 747 assertions
- **Delta:** +16 tests, +42 assertions
  - 8 unit tests in `tests/Unit/ConfigShowServiceTest.php`
  - 8 feature tests in `tests/Feature/ConfigShowCommandTest.php`

## Manual Smoke Verification

1. `php copland config:show --json | jq` — emits valid JSON with `schema_version=1`, boolean `asana_token_set`, and `repos` array.
2. `php copland config:show` — prints sectioned human summary, exits 0.
3. `php copland help config:show` — `--help` description names `tests/fixtures/config/show-snapshot.json` (consumer discoverability hook).
4. `--json` stdout byte-match: Feature T2 asserts `$display === json_encode($parsed, JSON_UNESCAPED_SLASHES) . "\n"` — no banner, no styling, no extra newlines.

## Deviations from Plan

**None of substance.** All 4 tasks landed as written, in order, with the same file scope listed in `files_modified`. Two micro-decisions worth flagging:

1. **Error routing helper.** Plan suggested `fwrite(STDERR, ...)` OR `getErrorOutput()`. I used both: `ConsoleOutputInterface::getErrorOutput()` is the primary path (so `CommandTester` with `capture_stderr_separately` sees the message correctly), with `fwrite(STDERR)` as a fallback for non-`ConsoleOutput` wiring. This makes the command robust whether invoked from the real CLI, from tests, or from a future programmatic harness without changing behavior in any of those modes.

2. **Pint scope clarification.** Plan `<verification>` step #2 calls for `./vendor/bin/pint --test` to be clean across the whole codebase. As of this session start, 16 unrelated files (e.g. `app/Support/AnthropicApiClient.php`, `app/Services/AsanaService.php`, several `tests/Unit/*` files) have pre-existing Pint diffs unrelated to this plan. The plan-level Task 4 `<verify><automated>` block scopes pint to the four files this plan touches, and that scoped check passes:
   ```
   ./vendor/bin/pint --test app/Services/ConfigShowService.php app/Commands/ConfigShowCommand.php tests/Unit/ConfigShowServiceTest.php tests/Feature/ConfigShowCommandTest.php
   ```
   Per the executor scope-boundary rule (only auto-fix issues caused by the current task), the repo-wide Pint backlog is logged here for the milestone tracker but not addressed in this plan.

## Schema Fixture Path (Canonical Contract for Phases 24-29)

```
tests/fixtures/config/show-snapshot.json
```

Downstream consumers (Phases 24-26 config write subcommands; Phase 27-29 Godot console screens) should reference this file as the v1 contract. Any field added to the snapshot in a later phase should:
1. Update the fixture so the unit shape test still passes.
2. Bump `schema_version` to 2 only if the addition is breaking (renamed/removed keys); additive changes can stay on v1.

## Self-Check: PASSED

All five files exist:
- `tests/fixtures/config/show-snapshot.json` — FOUND
- `app/Services/ConfigShowService.php` — FOUND
- `app/Commands/ConfigShowCommand.php` — FOUND
- `tests/Unit/ConfigShowServiceTest.php` — FOUND
- `tests/Feature/ConfigShowCommandTest.php` — FOUND

All seven commits exist in `git log`:
- `6851e41` `3f666cc` `aea5be6` `0294371` `f8f2ef4` `4f3c56c` `469cfff` — all FOUND

Test counts confirmed by direct `./vendor/bin/pest --no-coverage` invocation: 216 passed (747 assertions).

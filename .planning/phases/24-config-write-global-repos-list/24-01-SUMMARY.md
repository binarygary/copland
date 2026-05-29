---
phase: 24-config-write-global-repos-list
plan: 01
subsystem: config-write
tags: [cli, config, godot, yaml, cfg-02, cfg-06]
status: complete-pending-godot-smoke
requirements:
  - CFG-02
  - CFG-06
dependency_graph:
  requires:
    - Phase 23 (ConfigShowService + `config:show --json`)
  provides:
    - App\Support\YamlBlockEditor (Phase 25 / 26 will reuse for asana_token, defaults, models, per-repo .copland.yml)
    - App\Support\GlobalConfigPath (shared file-missing preflight for write subcommands)
    - copland config:repos:add | edit | remove (CFG-02 surface)
    - Godot Config.tscn hub scene + Config.gd (CFG-02 console surface)
  affects:
    - app/Commands/ConsoleCommand.php (now passes --copland-bin to Godot)
    - console-godot/scripts/Main.gd (COPLAND_BIN_DEFAULT retired; KEY_C → Config)
tech-stack:
  added: []
  patterns:
    - Scoped-block YAML replacement (preserve comments outside the target block; in-block comments documented loss)
    - CFG-06 invariant — PHP owns YAML mutation; Godot shells out via OS.execute and never opens YAML directly
    - Portable copland binary discovery (cmdline arg → env var → PATH → empty + error banner)
key-files:
  created:
    - app/Support/YamlBlockEditor.php
    - app/Support/GlobalConfigPath.php
    - app/Commands/ConfigReposAddCommand.php
    - app/Commands/ConfigReposEditCommand.php
    - app/Commands/ConfigReposRemoveCommand.php
    - tests/Unit/YamlBlockEditorTest.php
    - tests/Feature/ConfigReposAddCommandTest.php
    - tests/Feature/ConfigReposEditCommandTest.php
    - tests/Feature/ConfigReposRemoveCommandTest.php
    - tests/Feature/ConfigReposCommentPreservationTest.php
    - tests/fixtures/config/repos-comment-preservation.yml
    - console-godot/scenes/Config.tscn
    - console-godot/scripts/Config.gd
  modified:
    - app/Commands/ConsoleCommand.php
    - tests/Feature/ConsoleCommandTest.php
    - console-godot/scripts/Main.gd
decisions:
  - "YamlBlockEditor: extract block via start-of-line-anchored regex; parse + dump only the block; splice back; preserve dominant line ending (CRLF/LF) end-to-end."
  - "Whole-file Yaml::parseFile() preflight in every write subcommand (not just the repos block) so a malformed `defaults:` block still surfaces a parse error before we touch the file."
  - "Shared App\\Support\\GlobalConfigPath helper for file-missing preflight (avoids re-invoking GlobalConfig's auto-creating constructor)."
  - "Slug-only string entries are converted to {slug, path} array entries on edit (they now own a real path)."
  - "Remove on the last entry writes back `repos: []` rather than deleting the block — Phase 25/26 read the empty list as 'block present, no entries'."
  - "Godot Config.gd duplicates Main.gd::_resolve_copland_bin verbatim (intentional inline duplication per CONTEXT.md D-02; CoplandBin.gd autoload deferred until a third caller arrives)."
  - "Godot project.godot UNCHANGED — Godot loads non-boot scenes by path via change_scene_to_file; only run/main_scene needs registration."
metrics:
  duration: ~75 minutes
  completed_date: 2026-05-29
---

# Phase 24 Plan 01: Config Write — Global Repos List Summary

Ships the write side of `~/.copland.yml`'s global `repos[]` list — three new CLI subcommands (`config:repos:add`, `config:repos:edit`, `config:repos:remove`) built on a reusable `YamlBlockEditor` helper, a cross-cutting comment-preservation guarantee, and a Godot Config hub scene whose Repos sub-view drives all writes through `OS.execute`. Retires `COPLAND_BIN_DEFAULT` from `Main.gd` and threads `--copland-bin` through `copland console` so binary discovery is portable.

## Outcome

**Status: complete — pending Godot manual smoke (Task 6 11-step checklist).**

All 8 tasks finished. All automated verification passes (PHP unit + feature tests, Pint scope check, CFG-06 grep gate, COPLAND_BIN_DEFAULT retirement grep gate). The Godot scene + script syntax-check cleanly via `godot --headless --check-only`. The 11-step manual smoke for the live Godot UI is deferred to user — this non-interactive CLI executor cannot launch and click through an interactive Godot window.

## Test Counts

| Bucket                                          | Tests | Assertions |
|-------------------------------------------------|------:|-----------:|
| Baseline (Phase 23 SUMMARY)                     | 216   | 747        |
| YamlBlockEditor (Unit)                          | 11    | 29         |
| ConfigReposAddCommand (Feature)                 | 10    | 25         |
| ConfigReposEditCommand (Feature)                | 8     | 17         |
| ConfigReposRemoveCommand (Feature)              | 6     | 14         |
| ConfigReposCommentPreservation (Feature)        | 5     | 20         |
| ConsoleCommand (one new test)                   | 1     | 4          |
| **Total after Phase 24-01**                     | **257** | **856**   |
| Delta                                           | +41   | +109       |

(Note: YamlBlockEditor Test 7 is split into 7a / 7b sub-tests per the plan, so 11 tests rather than 10. Likewise EditCommand Test 7 and RemoveCommand Test 5 are split into a/b for clarity.)

All 257 tests pass via `./vendor/bin/pest --no-coverage` (duration ≈ 3.0s).

## Pint Scope Check

`./vendor/bin/pint --test` clean on every plan-touched file (12 files). The repo-wide pint backlog from earlier phases is out of remit.

## Commits (oldest first)

| # | Hash       | Subject                                                                |
|---|------------|------------------------------------------------------------------------|
| 1 | `4ce3784`  | test(24-01): add failing tests for YamlBlockEditor                     |
| 2 | `0bc02ec`  | feat(24-01): implement YamlBlockEditor for scoped-block YAML rewrites  |
| 3 | `a1c269b`  | test(24-01): add failing tests for config:repos:add                    |
| 4 | `83e8597`  | feat(24-01): implement config:repos:add command                        |
| 5 | `0890c74`  | test(24-01): add failing tests for config:repos:edit                   |
| 6 | `e2cc82d`  | feat(24-01): implement config:repos:edit command                       |
| 7 | `740c8c8`  | test(24-01): add failing tests for config:repos:remove                 |
| 8 | `8aa61bb`  | feat(24-01): implement config:repos:remove command                     |
| 9 | `dc97bc1`  | test(24-01): add cross-cutting comment-preservation guarantee          |
| 10| `de04bc7`  | test(24-01): add failing test for ConsoleCommand --copland-bin         |
| 11| `f952bc5`  | feat(24-01): pass --copland-bin to Godot launch args                   |
| 12| `ffba885`  | feat(24-01): retire COPLAND_BIN_DEFAULT and add KEY_C config binding   |
| 13| `a02c8aa`  | feat(24-01): add Godot Config hub scene with Repos sub-view            |

13 commits (the plan estimated ~14; T5 needed only one commit because the SAFEGUARD test passed first-try — see "Deviations" below).

## Requirement Coverage Map

| Req     | Where satisfied                                                                              |
|---------|----------------------------------------------------------------------------------------------|
| CFG-02  | `config:repos:add` (T2) + `config:repos:edit` (T3) + `config:repos:remove` (T4) + Godot Config Repos sub-view (T6) drives all three via `OS.execute`. |
| CFG-06  | Godot `Config.gd` makes **zero** read/write FileAccess calls targeting `~/.copland.yml` — verified by `grep -c 'FileAccess.*copland\.yml' console-godot/scripts/Config.gd` returning `0`. The only FileAccess usage in the file is `FileAccess.file_exists()` probing the copland binary path during `_resolve_copland_bin()`. All YAML I/O goes through the CLI (`config:show --json` for reads, `config:repos:*` for writes). A code comment at the top of `Config.gd` pins this invariant. |

## Comment-Preservation Guarantee

The load-bearing claim of Phase 24's YAML mutation strategy:

> Comments outside the `repos:` block survive byte-for-byte across every write subcommand. Comments inside the `repos:` block are dropped (documented trade-off per CONTEXT.md D-01).

**Proven by:**

- `tests/Unit/YamlBlockEditorTest.php::Test 2` — byte-equivalent pre/post-block region assertion against an inline fixture, exercising `YamlBlockEditor` directly.
- `tests/Feature/ConfigReposCommentPreservationTest.php` — five end-to-end tests via `CommandTester` against the rich fixture `tests/fixtures/config/repos-comment-preservation.yml` (top-of-file + pre-block + in-block × 2 + post-block + trailing comments). Covers add, edit, remove, in-block loss assertion, and the compound add → edit → remove cycle.

The cross-cutting test would still trip a future regression if a subcommand bypassed `YamlBlockEditor` for direct file manipulation.

## CRLF / LF Preservation

`YamlBlockEditor::detectDominantLineEnding()` counts `\r\n` vs `\n` occurrences on read; majority wins, ties → LF. The rendered block (always LF from `Yaml::dump()`) is post-processed to match the detected dominant ending before splicing. Verified by Test 7a (CRLF-dominant fixture survives as CRLF; out-of-block comment preserved with `\r\n`) and Test 7b (LF-dominant fixture has zero `\r` bytes after write).

## `COPLAND_BIN_DEFAULT` Retirement

- `grep -c "COPLAND_BIN_DEFAULT" console-godot/scripts/Main.gd` → `0`. Confirmed.
- `Main.gd::_resolve_copland_bin()` now uses the locked four-step order:
  1. `--copland-bin` cmdline arg (passed by `copland console`)
  2. `$COPLAND_BIN` env var (kept as escape hatch for `godot --editor` launches)
  3. `which copland` on PATH
  4. empty → startup error banner in the ops log; no thread spawn
- `Config.gd::_resolve_copland_bin()` duplicates the same resolver verbatim with the intentional-duplication comment per CONTEXT.md D-02.
- `app/Commands/ConsoleCommand.php` appends `['--copland-bin', base_path().'/copland']` to the `open -a Godot --args ...` launch argv so step (1) succeeds out of the box for the dev case. Production-install fallback (`which copland` inside `ConsoleCommand`) is deferred per the plan's T8 deviation note.

## Manual Smoke (Task 6 — DEFERRED to user)

| Step | Description                                                                 | Result      |
|------|-----------------------------------------------------------------------------|-------------|
| 1    | `php copland console` launches Godot; Main scene loads                      | DEFERRED    |
| 2    | Press `C` → Config scene with four tabs, Repos active                       | DEFERRED    |
| 3    | Repos sub-view shows current repos from `~/.copland.yml` or empty hint      | DEFERRED    |
| 4    | `2` / `3` / `4` show "Coming in Phase 25/26" placeholder in shared area     | DEFERRED    |
| 5    | `A` → modal → enter slug + path → list refreshes with new entry             | DEFERRED    |
| 6    | `E` on entry → modal opens with slug read-only → change path → list refresh | DEFERRED    |
| 7    | `D` and `Delete` both trigger confirmation → Y to confirm → list refresh    | DEFERRED    |
| 8    | Force READ-path CLI error (invalid configured path) → stderr in error panel | DEFERRED    |
| 9    | `ESC` returns to Main with prior state intact                               | DEFERRED    |
| 10   | Force WRITE-path failure (duplicate slug) → error panel; list intact        | DEFERRED    |
| 11   | Force WRITE-path failure (invalid path on add) → error panel; list intact   | DEFERRED    |

**Why deferred:** this executor runs as a non-interactive CLI agent and cannot launch + click through an interactive Godot UI window. Per the executor stop conditions, faking the manual smoke is forbidden.

**Automated proxies that DID run and pass:**

- `godot --headless --check-only --script scripts/Config.gd` → loads cleanly (no syntax errors).
- `godot --headless --check-only --script scripts/Main.gd` → loads cleanly.
- `grep -c "COPLAND_BIN_DEFAULT" console-godot/scripts/Main.gd` → `0` (gate pass).
- `grep -c 'FileAccess.*copland\.yml' console-godot/scripts/Config.gd` → `0` (CFG-06 gate pass).
- `php copland config:show --json` → emits expected snapshot from Gary's `~/.copland.yml` (sanity check the consumer call works end-to-end on disk).

**User to perform** the 11-step smoke after pulling this commit; if any step fails, file a Phase 24 follow-up.

## Deviations from Plan

1. **T5 (cross-cutting comment-preservation test) needed only ONE commit instead of test+feat.** The plan acknowledged this explicitly ("If everything passes immediately on first run, commit it as a SAFEGUARD test"). All 5 tests passed first run because `YamlBlockEditor`'s out-of-block guarantee from T1 propagates through all three subcommands. Single commit `dc97bc1` covers both the fixture file and the test file.

2. **Extracted `App\Support\GlobalConfigPath` as a tiny helper** instead of copy-pasting the file-missing preflight across T2/T3/T4 + `ConfigShowCommand`. The plan's T2 action grants discretion ("the simplest move is to copy the preflight from `ConfigShowCommand` since both files now own it"); a 50-line helper class is simpler than 4× duplication and avoids GlobalConfig's auto-creating ctor in every call site. `ConfigShowCommand` was NOT updated to use it (out of scope; its existing inline preflight still works).

3. **Whole-file `Yaml::parseFile()` preflight** added to each write subcommand (T2/T3/T4) instead of relying solely on `YamlBlockEditor::readBlock()` to surface parse errors. `readBlock` only parses the extracted block — a malformed `defaults:` block would otherwise pass through silently. The whole-file preflight is one extra parse per write but guarantees we never write into a globally-broken file. Test 10 of AddCommand verifies this.

4. **Footer hint string update in `Main.gd`** added a separate `CONFIG` cluster (with `[C, CONFIG]` chip) between `CREATE` and `UTILITY` in the manifest-mode footer, rather than appending `C config` text to an existing cluster. Matches the visual rhythm of the other footer clusters.

5. **`project.godot` was NOT modified.** The plan's T6 action explicitly says "if no registration block exists for non-boot scenes, leave it alone and only touch project.godot if a scene-list block exists. If unsure, skip the project.godot edit and document in SUMMARY." Inspected `project.godot`: only `run/main_scene` is set; non-boot scenes load via `change_scene_to_file` by path. No edit needed.

6. **T5 helper `runConfigCommand` accepts a base `Command` parameter** so the same helper drives all three subcommand classes through `CommandTester`. Plain refactor; behavior identical to instantiating each class directly.

## Files Created

**PHP (5):**
- `app/Support/YamlBlockEditor.php` (300 lines)
- `app/Support/GlobalConfigPath.php` (51 lines)
- `app/Commands/ConfigReposAddCommand.php` (124 lines)
- `app/Commands/ConfigReposEditCommand.php` (115 lines)
- `app/Commands/ConfigReposRemoveCommand.php` (104 lines)

**PHP tests + fixture (6):**
- `tests/Unit/YamlBlockEditorTest.php` (204 lines, 11 tests)
- `tests/Feature/ConfigReposAddCommandTest.php` (245 lines, 10 tests)
- `tests/Feature/ConfigReposEditCommandTest.php` (203 lines, 8 tests)
- `tests/Feature/ConfigReposRemoveCommandTest.php` (161 lines, 6 tests)
- `tests/Feature/ConfigReposCommentPreservationTest.php` (196 lines, 5 tests)
- `tests/fixtures/config/repos-comment-preservation.yml` (16 lines)

**Godot (2):**
- `console-godot/scenes/Config.tscn` (12 lines)
- `console-godot/scripts/Config.gd` (~520 lines)

## Files Modified

- `app/Commands/ConsoleCommand.php` — args array extended with `--copland-bin` pair.
- `tests/Feature/ConsoleCommandTest.php` — existing happy-path test updated for new arg shape; new test asserts pass-through.
- `console-godot/scripts/Main.gd` — `COPLAND_BIN_DEFAULT` retired, `_resolve_copland_bin()` rewritten, `_spawn_copland()` early-returns with banner on empty resolution, `KEY_C` case added to `_input()` match, footer cluster list extended with `CONFIG`.

## Threat Flags

None — all surface in the threat register's `mitigate` dispositions (T-24-01..T-24-06) implemented as planned. No new endpoints, auth paths, file access patterns, or schema changes beyond the registered threat surface.

## Plan-Checker LOW Notes

None outstanding — the plan's `requirements: [CFG-02, CFG-06]` are satisfied end-to-end, the `must_haves.truths` list is fully realized (truths 1-7 + 9-10 by automated tests; truth 6 [Godot keybindings] + truth 8 [CLI failure surface in Repos sub-view] deferred to manual smoke for the live UI behavior).

## Self-Check: PASSED

- All 13 commits present in `git log --oneline -20`: confirmed.
- All claimed files exist on disk: confirmed.
- Verification gates (COPLAND_BIN_DEFAULT=0; FileAccess.*copland.yml=0 in Config.gd; KEY_C present; --copland-bin in ConsoleCommand): all confirmed via grep.
- Full Pest suite (257 passing) ran clean at SUMMARY-write time.
- Pint scope check ran clean on every plan-touched file.

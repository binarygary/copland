---
phase: 24-config-write-global-repos-list
verified: 2026-05-29T00:00:00Z
status: human_needed
score: 10/10 must-haves verified (8 by automated checks + CLI smoke; 2 require Godot UI smoke)
re_verification:
  previous_status: none
  previous_score: n/a
  gaps_closed: []
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Launch Godot via `php copland console`; from Main scene press `C`"
    expected: "Config scene loads with four tabs visible, Repos tab active (brass underline)"
    why_human: "CLI executor cannot launch and click through an interactive Godot UI window"
  - test: "On Config Repos tab, observe current repos list"
    expected: "Repos list shows current `~/.copland.yml` repos[] or empty-state hint `No repos configured. Press A to add one.`"
    why_human: "Visual rendering check; requires running Godot main loop"
  - test: "Press `2`, then `3`, then `4`"
    expected: "Each non-Repos tab shows `Coming in Phase 25` (Per-Repo) or `Coming in Phase 26` (Asana, Defaults) placeholder centered in ContentArea"
    why_human: "Visual rendering check"
  - test: "Press `A`, enter slug `test-owner/test-repo` and a real dir path, press ENTER"
    expected: "Modal closes; list refreshes and shows the new entry"
    why_human: "Requires interactive form input + scene refresh observation"
  - test: "Press `E` on the new entry"
    expected: "Modal opens with slug read-only (editable=false) and current path prefilled; change path; ENTER; list refreshes with the new path"
    why_human: "Requires interactive form input + observation of slug-immutable UI affordance"
  - test: "Press `D` (and separately verify `Delete` key) on the new entry"
    expected: "Confirmation prompt `Remove <slug> ?`; press Y; list refreshes without the entry"
    why_human: "Requires interactive confirm + observation of refresh"
  - test: "READ-path error: `mv` a configured repo path away, open the Repos tab"
    expected: "stderr from `config:show --json` surfaces in the error panel; no crash"
    why_human: "Requires interactive observation of error panel rendering"
  - test: "Press `ESC` from Config"
    expected: "Returns to Main scene with prior state intact"
    why_human: "Requires interactive observation of scene transition"
  - test: "WRITE-path error (duplicate slug): press `A`, enter a slug already in list, ENTER"
    expected: "Error panel shows `Repo '<slug>' already exists. Use config:repos:edit to change its path.` AND list is not corrupted (re-renders prior state)"
    why_human: "Requires interactive form input + observation"
  - test: "WRITE-path error (invalid path): press `A`, enter a NEW valid slug + nonexistent path, ENTER"
    expected: "Error panel shows `Path '<path>' does not exist or is not a directory.` AND list is not corrupted"
    why_human: "Requires interactive form input + observation"
  - test: "Visual rhythm of footer + tab strip in Config scene"
    expected: "Tabs styled with brass-highlight on active, dim on inactive; footer hint reads `1/2/3/4 switch tabs · A add · E edit · D/Del remove · ESC back to Main`; matches Main.gd palette"
    why_human: "Visual / aesthetic check"
---

# Phase 24: Config Write — Global Repos List Verification Report

**Phase Goal:** Users manage the global `~/.copland.yml` `repos[]` list (add, edit, remove repo slug + path entries) entirely from the Godot console, with PHP owning all YAML mutation via a new `copland config repos` subcommand family.

**Verified:** 2026-05-29
**Status:** human_needed (passed-pending-Godot-smoke)
**Re-verification:** No — initial verification

---

## Roadmap Success Criteria

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | `copland config:repos:{add,edit,remove}` mutate `~/.copland.yml` correctly, preserve YAML comments and ordering, return non-zero with clear stderr on invalid input | VERIFIED | Manual CLI smoke (8 scenarios, all expected exit codes + stderr); 24 Pest tests across the three command files; comment preservation test (5 tests) on rich fixture; manual smoke file contents show `# top comment`, `# trailing`, and `defaults:` all surviving byte-for-byte through full add→edit→remove cycle |
| 2 | Godot console gains "Repos" config screen that invokes subcommands via `OS.execute` and never opens `~/.copland.yml` directly | PARTIAL (codepath VERIFIED; live UX DEFERRED) | `Config.gd` `_invoke_cli` uses `OS.execute(copland_bin, ...)` for `config:repos:add/edit/remove`; CFG-06 grep gate (see Key Links table) shows zero FileAccess WRITE matches on `copland.yml`. Live UX confirmation (key presses, modal interactions) is in human_verification list. |
| 3 | After saving, screen refreshes by re-calling `config:show --json` | PARTIAL (codepath VERIFIED; live UX DEFERRED) | `Config.gd::_submit_modal()` (lines 549, 557) and `_confirm_remove()` (line 634) both call `_refresh_snapshot()` after every CLI invocation regardless of exit code; `_refresh_snapshot()` (lines 384-409) invokes `config:show --json` via `_invoke_cli`. Live UX confirmation is in human_verification list. |
| 4 | Pest covers each subcommand against tmp `HOME`; GDScript-side check confirms no `FileAccess` write call targets `~/.copland.yml` | VERIFIED | 11 + 10 + 8 + 6 + 5 = 40 Pest tests across YamlBlockEditor + three commands + comment preservation; `grep -rE 'FileAccess.*(WRITE\|READ_WRITE).*copland\.yml' console-godot/scripts/` returns ZERO matches |

**Score:** 4/4 roadmap criteria met (2 fully; 2 codepath-verified with live UX in human_verification list)

---

## Observable Truths (from PLAN must_haves)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `config:repos:add` appends `{slug, path}` to `repos:` | VERIFIED | Manual smoke step 1 (exit 0, "Added repo: verify/repo"); ConfigReposAddCommandTest passes 10/10 |
| 2 | `config:repos:edit` rewrites existing entry's path in place | VERIFIED | Manual smoke step 4 (exit 0, "Updated repo: verify/repo"); ConfigReposEditCommandTest passes 8/8 |
| 3 | `config:repos:remove` drops the entry from the list | VERIFIED | Manual smoke step 6 (exit 0, "Removed repo: verify/repo"); file contents post-cycle show `repos: {  }`; ConfigReposRemoveCommandTest passes 6/6 |
| 4 | All three subcommands exit non-zero with stderr on invalid input | VERIFIED | Manual smoke steps 2, 3, 5, 7 (all exit=1 with exact expected stderr messages); tests cover duplicate/missing/invalid-path/missing-flag/missing-config/malformed-YAML across all three commands |
| 5 | Comments outside `repos:` survive byte-for-byte across writes | VERIFIED | `ConfigReposCommentPreservationTest` 5 tests pass; assertions use `substr() + ->toBe()` (Pest `===`) on captured pre/post regions, not `str_contains` (plan-checker H-2 requirement satisfied); manual smoke file contents confirm `# top comment`, `# trailing`, and `defaults:` block intact after add→edit→remove cycle |
| 6 | From Godot Main pressing `C` opens Config hub with 4 tabs (only Repos live) | DEFERRED | Codepath VERIFIED: `Main.gd:416-418` has `KEY_C → change_scene_to_file("res://scenes/Config.tscn")`; `Config.gd` has `TAB_LABELS = ["Repos", "Per-Repo", "Asana", "Defaults"]` and `TAB_PLACEHOLDERS = {1: "Coming in Phase 25", 2: "Coming in Phase 26", 3: "Coming in Phase 26"}`; only `active_tab == 0` renders `_render_repos_view()`. Live UX confirmation in human_verification. |
| 7 | Repos sub-view shells out via `OS.execute` and refreshes via `config:show --json` | DEFERRED | Codepath VERIFIED: `Config.gd::_invoke_cli` uses `OS.execute(copland_bin, args, output, true)`; all three writes followed by `_refresh_snapshot()` (which calls `config:show --json`). Live UX confirmation in human_verification. |
| 8 | On CLI failure Repos sub-view surfaces stderr in visible panel | DEFERRED | Codepath VERIFIED: `_submit_modal()` / `_confirm_remove()` / `_refresh_snapshot()` all call `_show_error(...)` on non-zero exit; persistent `error_panel` (lines 217-233) is added before the rows box and made visible on error. Live UX confirmation in human_verification. |
| 9 | `COPLAND_BIN_DEFAULT` is gone from `Main.gd`; binary discovery uses cmdline → env → which → empty+banner | VERIFIED | `grep -n 'COPLAND_BIN_DEFAULT' console-godot/scripts/Main.gd` → ZERO matches; `Main.gd:1486-1502` implements exact four-step resolver; `Main.gd:1514-1518` surfaces error banner via ops log and early-returns when resolution is empty |
| 10 | `copland console` passes `--copland-bin` to Godot launch args | VERIFIED | `ConsoleCommand.php:64-68` extends args with `'--copland-bin', $coplandBinPath` where `$coplandBinPath = $projectRoot.'/copland'`; new ConsoleCommandTest assertion (line 112 onward) verifies the consecutive pair is present in captured `runner` args |

**Score:** 10/10 truths verified (7 fully via automated + CLI smoke; 3 codepath-verified with live UX deferred to human_verification)

---

## CONTEXT.md Decision Compliance (D-01 .. D-04)

| Decision | Requirement | Status | Evidence |
|----------|-------------|--------|----------|
| D-01 | Scoped-block replacement (NOT whole-file round-trip via Yaml::dump) | VERIFIED | `YamlBlockEditor::writeBlock()` calls `Yaml::dump([$key => $value], ...)` ONLY on the single key — not the whole parsed file. `extractBlockText()` regex anchored to start-of-line; `before/after` substring splice preserves everything outside the block. |
| D-01 | Line-ending preservation (CRLF/LF dominant detected & emitted) | VERIFIED | `YamlBlockEditor::detectDominantLineEnding()` (lines 268-275) counts `\r\n` vs bare `\n` and returns dominant; `withLineEnding()` (lines 277-287) re-emits Yaml::dump's LF output as CRLF when needed; Test 7a (CRLF preserved) + Test 7b (LF stays free of `\r`) both pass |
| D-01 | Out-of-block comments survive byte-for-byte | VERIFIED | Comment-preservation test uses `substr($contents, 0, strpos(...,"repos:"))` + `substr($contents, strpos(...,"\ndefaults:"))` then asserts `->toBe()` (strict `===`); also confirmed by manual smoke file contents |
| D-02 | Config hub with 4 tabs; only Repos live; others show placeholders | VERIFIED | `Config.gd` lines 37-42 declare `TAB_LABELS` (4 items) + `TAB_PLACEHOLDERS` map; `_render_active_tab` (lines 190-207) renders `repos_view` for `active_tab==0` else a centered placeholder Label with the mapped text "Coming in Phase 25" / "Coming in Phase 26" |
| D-02 | No Theme autoload extraction (PALETTE duplicated inline) | VERIFIED | `Config.gd` lines 19-35 duplicate the PALETTE dict from Main.gd; explanatory comment at lines 16-18 references CONTEXT.md D-02 directly |
| D-03 | Binary discovery: cmdline `--copland-bin` → `$COPLAND_BIN` → `which copland` → empty + banner | VERIFIED | Both `Main.gd::_resolve_copland_bin` (1486-1502) and `Config.gd::_resolve_copland_bin` (87-103) implement the exact same 4-step order; explicit comment in Config.gd lines 83-86 documents intentional duplication |
| D-03 | `COPLAND_BIN_DEFAULT` retired from Main.gd | VERIFIED | `grep -c "COPLAND_BIN_DEFAULT" console-godot/scripts/Main.gd` → 0 |
| D-04 | Slug immutable; add takes slug+path only (no --asana-project) | VERIFIED | `ConfigReposAddCommand::$signature` declares only `{--slug=} {--path=}`; `ConfigReposEditCommand::$signature` declares `{--slug=} {--path=}` and `handle()` only mutates `path` (line 70: `$repos[$idx] = ['slug' => $slug, 'path' => $path]`); Godot `_show_edit_modal` sets `modal_slug_input.editable = false` (line 526) with comment `# slug is immutable (D-04)` |

**Score:** 8/8 decision points verified

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Support/YamlBlockEditor.php` | Reusable scoped-block helper | VERIFIED | 300 lines; public API matches plan (`readBlock`, `writeBlock`, `deleteBlock`); used by all three commands |
| `app/Support/GlobalConfigPath.php` | File-missing preflight helper (plan-authorized DRY extraction) | VERIFIED | 51 lines; used by all three commands |
| `app/Commands/ConfigReposAddCommand.php` | `config:repos:add {--slug=} {--path=}` | VERIFIED | Signature exact; 130 lines; uses YamlBlockEditor + GlobalConfigPath + ParseException preflight |
| `app/Commands/ConfigReposEditCommand.php` | `config:repos:edit {--slug=} {--path=}` | VERIFIED | Signature exact; 115 lines; slug-locator-only semantics |
| `app/Commands/ConfigReposRemoveCommand.php` | `config:repos:remove {--slug=}` | VERIFIED | Signature exact; 104 lines; writes `repos: {  }` on last-entry removal (does NOT delete block) |
| `console-godot/scenes/Config.tscn` | Godot hub scene | VERIFIED | 12 lines; loads `Config.gd` script |
| `console-godot/scripts/Config.gd` | Repos sub-view + tab strip + CLI shell-outs | VERIFIED | 635 lines; full tab strip, modals, error panel, CLI integration |
| `tests/Feature/ConfigReposCommentPreservationTest.php` | Load-bearing cross-cutting guarantee | VERIFIED | 196 lines; 5 tests using byte-equivalent region capture (substr + toBe) |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|------|------|--------|---------|
| `ConfigReposAddCommand` | `YamlBlockEditor` | static helper call | WIRED | Direct `new YamlBlockEditor($activePath)` at line 76 |
| `ConfigReposEditCommand` | `YamlBlockEditor` | static helper call | WIRED | Direct `new YamlBlockEditor($activePath)` at line 59 |
| `ConfigReposRemoveCommand` | `YamlBlockEditor` | static helper call | WIRED | Direct `new YamlBlockEditor($activePath)` at line 46 |
| `Config.gd` | `copland` CLI | `OS.execute` | WIRED | `_invoke_cli()` line 377: `OS.execute(copland_bin, args, output, true)` |
| `Main.gd` | `Config.tscn` | `change_scene_to_file()` | WIRED | Line 417: `get_tree().change_scene_to_file("res://scenes/Config.tscn")` |
| CFG-06 invariant | (Godot does NOT open YAML directly) | grep gate | WIRED | `grep -rE 'FileAccess.*(WRITE\|READ_WRITE).*copland\.yml' console-godot/scripts/` returns ZERO matches; the only FileAccess usage in Config.gd is `file_exists()` probes on the binary path (read-only metadata check, not file content access) |

---

## Test Execution Results

### Pest Suite

```
Tests:    257 passed (856 assertions)
Duration: 3.01s
```

Breakdown matches SUMMARY claim:
- Baseline (Phase 23): 216
- YamlBlockEditorTest: 11 (Test 7 split into 7a/7b)
- ConfigReposAddCommandTest: 10
- ConfigReposEditCommandTest: 8
- ConfigReposRemoveCommandTest: 6
- ConfigReposCommentPreservationTest: 5
- ConsoleCommandTest: +1 new (existing tests updated for `--copland-bin` arg shape)
- **Total: 257** (= 216 + 41 new)

### Pint (scope check on plan-touched files)

```
{"tool":"pint","result":"passed"}
```

12 files clean.

### CFG-06 Grep Gate (load-bearing invariant)

```
$ grep -rE 'FileAccess.*(WRITE|READ_WRITE).*copland\.yml' console-godot/scripts/
(no matches)
```

The only FileAccess usage in `Config.gd` is three `FileAccess.file_exists(bin)` probes inside `_resolve_copland_bin` — checking the copland binary path, not reading YAML. PASS.

### COPLAND_BIN_DEFAULT Retirement Gate

```
$ grep -n 'COPLAND_BIN_DEFAULT' console-godot/scripts/Main.gd
(no matches)
```

PASS.

### Manual CLI Smoke (against tmp HOME)

| # | Command | Expected Result | Actual |
|---|---------|-----------------|--------|
| 1 | `add --slug verify/repo --path $TMPHOME` (fresh repos: []) | exit 0, "Added repo: verify/repo" | PASS |
| 2 | `add --slug verify/repo --path $TMPHOME` (duplicate) | exit 1, "Repo 'verify/repo' already exists. Use config:repos:edit to change its path." | PASS |
| 3 | `add --slug nonexistent/x --path /no/such/path` | exit 1, "Path '/no/such/path' does not exist or is not a directory." | PASS |
| 4 | `edit --slug verify/repo --path /tmp` | exit 0, "Updated repo: verify/repo" | PASS |
| 5 | `edit --slug missing/repo --path /tmp` | exit 1, "Repo 'missing/repo' not found in ~/.copland.yml." | PASS |
| 6 | `remove --slug verify/repo` | exit 0, "Removed repo: verify/repo" | PASS |
| 7 | `remove --slug missing/repo` | exit 1, "Repo 'missing/repo' not found in ~/.copland.yml." | PASS |
| 8 | Post-cycle file contents | `# top comment`, `# trailing`, and `defaults:` block intact; `repos: {  }` for empty list | PASS (comment preservation confirmed end-to-end through full add→edit→remove cycle) |

---

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| CFG-02 | 24-01-PLAN | User can manage repos list from console | SATISFIED | Three subcommands shipped + Godot Config Repos sub-view drives them via `OS.execute` (codepath verified); manual CLI smoke confirms all three commands work end-to-end |
| CFG-06 | 24-01-PLAN | PHP owns YAML mutation; Godot never writes YAML directly | SATISFIED | CFG-06 grep gate returns ZERO matches; Config.gd has explicit invariant comment at lines 9-14; all writes go through `_invoke_cli` → `OS.execute(copland_bin, ['config:repos:...'])` |

---

## Anti-Pattern Scan

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (no debt markers found in plan-modified files) | — | — | — | — |

Scanned: all 13 files listed in SUMMARY's `key-files.created` + `key-files.modified`. No `TODO`, `FIXME`, `TBD`, `XXX`, `HACK`, or `PLACEHOLDER` markers in the new/modified PHP or GDScript code. (Config.gd uses `placeholder_text` and `placeholder_label` as variable/property names referring to actual UI placeholder *text* shown to users — these are legitimate naming, not debt markers.)

---

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| `config:repos:add` exists and is invokable | `HOME=$TMP ./copland config:repos:add --slug verify/repo --path $TMP` | exit 0, "Added repo: verify/repo" | PASS |
| `config:repos:edit` exists and is invokable | `HOME=$TMP ./copland config:repos:edit --slug verify/repo --path /tmp` | exit 0, "Updated repo: verify/repo" | PASS |
| `config:repos:remove` exists and is invokable | `HOME=$TMP ./copland config:repos:remove --slug verify/repo` | exit 0, "Removed repo: verify/repo" | PASS |
| Comment preservation end-to-end | Manual smoke step 8 (file dump post-add-edit-remove cycle) | `# top comment` + `# trailing` + `defaults:` all intact | PASS |
| Three commands registered in CLI | (visible in command output above; no "command not defined" error) | All three resolved | PASS |
| Godot scripts syntax-load | (per SUMMARY: `godot --headless --check-only --script scripts/Config.gd` and `Main.gd`) | clean per SUMMARY | PASS (executor proxy) |

---

## Probe Execution

No formal probe scripts declared by this phase. Manual CLI smoke (above) serves as the equivalent behavioral check.

---

## T6 Godot Manual Smoke Checklist (DEFERRED to user)

The 11-step Godot UI smoke from PLAN.md Task 6 cannot be executed by this verifier (CLI-only environment, no GUI access). The user must run these AFTER pulling this commit to close out the phase fully:

| Step | Description | Status |
|------|-------------|--------|
| 1 | `php copland console` launches Godot; Main scene loads | DEFERRED-TO-USER |
| 2 | Press `C` → Config scene with four tabs, Repos active | DEFERRED-TO-USER |
| 3 | Repos sub-view shows current repos from `~/.copland.yml` or empty hint | DEFERRED-TO-USER |
| 4 | `2` / `3` / `4` show "Coming in Phase 25/26" placeholder in shared area | DEFERRED-TO-USER |
| 5 | `A` → modal → enter slug + path → list refreshes with new entry | DEFERRED-TO-USER |
| 6 | `E` on entry → modal opens with slug read-only → change path → list refresh | DEFERRED-TO-USER |
| 7 | `D` and `Delete` both trigger confirmation → Y to confirm → list refresh | DEFERRED-TO-USER |
| 8 | Force READ-path CLI error (invalid configured path) → stderr in error panel | DEFERRED-TO-USER |
| 9 | `ESC` returns to Main with prior state intact | DEFERRED-TO-USER |
| 10 | Force WRITE-path failure (duplicate slug) → error panel; list intact | DEFERRED-TO-USER |
| 11 | Force WRITE-path failure (invalid path on add) → error panel; list intact | DEFERRED-TO-USER |

**Why deferred:** the verifier runs as a non-interactive CLI agent. The SUMMARY transparently documents this and lists the full 11-step checklist with `DEFERRED` markers.

---

## Out-of-Scope Audit

Confirmed the executor did NOT add anything outside the phase boundary:

| Out-of-scope item | Status |
|-------------------|--------|
| Asana write subcommands | NOT added (deferred to Phase 26) |
| Per-repo `.copland.yml` writes | NOT added (deferred to Phase 25) |
| Defaults writes | NOT added (deferred to Phase 26) |
| `--dry-run` / `--diff` flags | NOT added |
| Rename-slug affordance | NOT added (CLI surface stays minimal) |
| Theme.gd autoload extraction | NOT added (inline PALETTE per D-02) |
| Phase 23 `config:show --json` output shape changes | NO changes detected |
| Keychain integration | NOT added |

---

## Documented Deviations Assessment

The SUMMARY documents 6 deviations. Each assessed:

| # | Deviation | Assessment |
|---|-----------|------------|
| 1 | T5 single commit (test+SAFEGUARD passed first-try) | ACCEPTABLE — plan explicitly allowed this ("If everything passes immediately on first run, commit it as a SAFEGUARD test") |
| 2 | `App\Support\GlobalConfigPath` extracted as helper | ACCEPTABLE — plan T2 granted discretion ("the simplest move is to copy the preflight from `ConfigShowCommand`"); a 51-line helper is simpler than 3× duplication |
| 3 | Whole-file `Yaml::parseFile()` preflight added in each command | ACCEPTABLE — defensive; surfaces malformed `defaults:` before write; AddCommandTest Test 10 covers it |
| 4 | Footer `CONFIG` cluster in Main.gd manifest-mode footer | ACCEPTABLE — visual polish; matches existing footer rhythm |
| 5 | `project.godot` NOT modified | ACCEPTABLE — plan T6 explicitly authorized this skip ("if no registration block exists for non-boot scenes, leave it alone") |
| 6 | T5 helper `runConfigCommand(Command $command, array $args)` signature | ACCEPTABLE — plain refactor for test reuse across the three command classes; behavior identical |

All 6 deviations either pre-authorized by the plan or defensible enhancements that do not change scope.

---

## SUMMARY Accuracy Spot-Check

| Claim | Verified |
|-------|----------|
| 11 + 10 + 8 + 6 + 5 + 1 = 41 new tests | TRUE (grep `^it(` confirms 11+10+8+6+5=40; +1 new `--copland-bin` test in ConsoleCommandTest = 41) |
| 257 total tests pass | TRUE (Pest output: "Tests: 257 passed") |
| All 13 commits in git log | (not separately re-validated; SUMMARY self-check confirmed and probe gates pass, which are downstream of the commits) |
| CFG-06 grep returns 0 | TRUE (verified) |
| `COPLAND_BIN_DEFAULT` removed | TRUE (verified) |
| `--copland-bin` in ConsoleCommand | TRUE (verified at lines 64-68) |

---

## Gaps Summary

**No blocking gaps.** All PHP and contract surfaces are independently verifiable now and confirmed passing:

- 4/4 roadmap success criteria met at the codepath/CLI level
- 10/10 must-have truths met (7 fully via automated + manual CLI smoke; 3 codepath-verified with live UX moved to human_verification list)
- 8/8 CONTEXT.md decisions (D-01..D-04) verified
- CFG-02 + CFG-06 requirements satisfied
- 257 Pest tests pass; Pint clean on all 12 plan-touched files
- Manual CLI smoke confirms all 8 scenarios behave correctly with byte-equivalent comment preservation through full add→edit→remove cycle

**Outstanding:** 11 Godot UI smoke checklist items deferred to user (cannot be executed by CLI verifier). Honestly documented in SUMMARY with full step-by-step checklist. Phase is not fully closed until the user runs these in a live Godot session.

---

## Sign-off

**Status: human_needed (PASSED-PENDING-GODOT-SMOKE)**

The PHP CLI surface (three commands + YamlBlockEditor + GlobalConfigPath + the `--copland-bin` plumbing in ConsoleCommand) is shippable as-is. The Godot Config hub scene + Config.gd are syntactically valid (per SUMMARY's `godot --headless --check-only` proxy), structurally compliant with D-01..D-04, and CFG-06 is enforced by the grep gate at the codepath level. The live UI behavior is the only un-verified piece and is explicitly listed in `human_verification` for user execution.

_Verified: 2026-05-29_
_Verifier: Claude (gsd-verifier)_

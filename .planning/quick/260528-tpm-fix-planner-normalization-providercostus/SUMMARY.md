---
id: 260528-tpm
slug: fix-planner-readpaths-normalization
status: complete
mode: quick
branch: quick/planner-exact-diffs
date: 2026-05-29
---

# Summary: Fix planner `$readPaths` key normalization

## What changed

`app/Services/ClaudePlannerService.php::planTask()` — the `array_filter` closure over the planner's `changes` array now normalizes `$change['file']` through `$policy->assertToolPathAllowed(..., 'read_file')` (wrapped in `try { } catch (Throwable)`) before looking it up in `$readPaths`. `$readPaths` is keyed by the normalized path, so this restores symmetry: `src/x.php` read + `./src/x.php` change (and the reverse) now match. Disallowed/absolute/blocked paths still drop out.

`tests/Unit/ClaudePlannerServiceTest.php` — added regression test `planner keeps changes entries when changes path normalizes to a read path` that reads `src/file.txt` and emits a change for `./src/file.txt`; pre-fix the entry was silently dropped, post-fix it survives.

## Verification

- `./vendor/bin/pest` — 177 passed, 606 assertions
- `./vendor/bin/pint` — clean on both touched files

## Out of scope (decided up front)

- **Gemini's `providerCostUsd` accumulator concern** — skipped. The field doesn't exist on this branch (it shipped in PR #18, not yet merged). Would have required either rebasing #16 onto #18 (entangles PR review trails) or a follow-up on main post-merge. User chose to skip rather than defer.
- **Copilot's `str_contains($fileContent, $change['old'])` pre-check** — deferred. Defensive layer; executor still fails loudly on bad `old` text.

## Commits

- `fix(planner): normalize changes[] file path before $readPaths lookup`

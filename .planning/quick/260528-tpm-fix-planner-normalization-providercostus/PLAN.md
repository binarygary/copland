---
id: 260528-tpm
slug: fix-planner-readpaths-normalization
status: in-progress
mode: quick
branch: quick/planner-exact-diffs
---

# Quick Task: Fix planner `$readPaths` key normalization

## Context

PR #16 review (Copilot) flagged a real bug in `ClaudePlannerService::planTask`: `$readPaths` is keyed by the **normalized** path returned from `ExecutorPolicy::assertToolPathAllowed()`, but the `array_filter` over `changes` looks up by the **raw** LLM-emitted `$change['file']`. If the model uses `src/foo.php` in `read_file` and `./src/foo.php` in `changes` (or vice versa), the entry is silently dropped even though the file was actually read.

Gemini's second concern (`providerCostUsd` accumulation) is dropped from scope per user decision — that bug only manifests once PR #18's `LlmUsage::$providerCostUsd` field exists on this branch, and the field hasn't been merged yet.

## Change

`app/Services/ClaudePlannerService.php` — replace the inline `array_filter` lambda with one that normalizes `$change['file']` via `$policy->assertToolPathAllowed(..., 'read_file')` inside a `try { } catch (Throwable) { return false; }`. Disallowed / blocked / absolute paths drop out (same as they would in the executor); valid-but-differently-spelled paths now match their normalized form in `$readPaths`.

## Test

`tests/Unit/ClaudePlannerServiceTest.php` — add a regression test that reads a file as `src/x.txt` and emits a `changes` entry with `./src/x.txt`. Pre-fix this entry is silently dropped; post-fix it survives because both spellings normalize to the same key.

## Out of scope

- Provider-cost accumulator (needs PR #18 merged first; will follow up on main).
- `str_contains($fileContent, $change['old'])` defensive pre-check (Copilot's second suggestion; deferred).

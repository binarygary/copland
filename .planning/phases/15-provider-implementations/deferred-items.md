# Deferred Items — Phase 15

## Pre-existing makePlan() function conflict

**File:** tests/Unit/RunOrchestratorServiceTest.php:424
**Also declared in:** tests/Unit/ClaudeExecutorServiceTest.php:137
**Symptom:** Fatal error when running `./vendor/bin/pest` full suite — "Cannot redeclare function makePlan()"
**Impact:** Full test suite cannot run. Individual test files work fine (63 pass).
**Root cause:** PHP global namespace function collision across test helper files. Pest doesn't isolate global function declarations between test files.
**Fix needed:** Rename one of the makePlan() helpers or wrap them in a named namespace/closure in the respective test file.
**Out of scope for:** Plan 15-01 (not caused by this plan's changes)
**Recommended plan to fix:** Quick task or Phase 15 cleanup pass after Plan 03.

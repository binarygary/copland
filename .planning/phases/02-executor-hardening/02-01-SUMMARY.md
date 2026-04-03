---
phase: 02-executor-hardening
plan: 01
subsystem: config
tags: [executor, config, repo-profile, reads]
requires: []
provides:
  - Repo-scoped read-file line cap with default value of 300
  - Runtime plumbing from RepoConfig into executor repoProfile
affects: [repo-config, run-command, executor-policy]
tech-stack:
  added: []
  patterns: [repo-scoped-runtime-policy, config-driven-read-bounds]
key-files:
  created: []
  modified: [app/Config/RepoConfig.php, app/Commands/RunCommand.php, tests/Unit/RepoConfigTest.php]
key-decisions:
  - "Stored the read cap in per-repo config so large-file handling can be tuned without changing global defaults."
  - "Passed the cap through repoProfile rather than reading RepoConfig inside the executor to keep runtime policy construction explicit."
patterns-established:
  - "Executor policy inputs should be wired through repoProfile from command setup."
requirements-completed: [RELY-02]
duration: 8min
completed: 2026-04-03
---

# Phase 2 Plan 01 Summary

**Repo config now carries a per-repo read-file line cap and passes it into executor runtime policy construction**

## Accomplishments
- Added `read_file_max_lines: 300` to the default `.copland.yml` template.
- Added `RepoConfig::readFileMaxLines()` with a bounded integer fallback.
- Passed the configured limit through `RunCommand` repoProfile.
- Extended the repo config regression test to cover the new default and template line.

## Verification
- `php -l app/Config/RepoConfig.php`
- `php -l app/Commands/RunCommand.php`
- `./vendor/bin/pest tests/Unit/RepoConfigTest.php`

## Next Readiness

Wave 2 runtime changes can now consume a concrete executor read limit from repoProfile.

---
*Phase: 02-executor-hardening*
*Completed: 2026-04-03*

---
phase: 17-asana-integration
verified: 2026-04-08T20:30:00Z
status: gaps_found
score: 5/5 must-haves verified
gaps:
  - truth: "REQUIREMENTS.md reflects the implemented status of ASANA-02 and ASANA-03"
    status: failed
    reason: "ASANA-02 and ASANA-03 are fully implemented in code but REQUIREMENTS.md still shows them as unchecked (Pending). The traceability table also shows Pending for both."
    artifacts:
      - path: ".planning/REQUIREMENTS.md"
        issue: "Lines 33-34 and 72-73: ASANA-02 and ASANA-03 marked [ ] Pending despite being fully implemented"
    missing:
      - "Update ASANA-02 entry: '- [ ]' → '- [x]' and traceability row 'Pending' → 'Complete'"
      - "Update ASANA-03 entry: '- [ ]' → '- [x]' and traceability row 'Pending' → 'Complete'"
human_verification:
  - test: "Live Asana API: run copland against a repo configured with task_source: asana"
    expected: "Copland fetches open tasks from the configured Asana project; selector runs; if a task is selected, a PR is opened and a comment with the PR URL appears on the Asana task"
    why_human: "Requires a live Asana account, PAT token, and test project — cannot be verified without external service"
---

# Phase 17: Asana Integration Verification Report

**Phase Goal:** Users can configure Asana projects as a task source per repo; Copland fetches open Asana tasks, runs the same code pipeline, and posts the resulting PR link back as an Asana comment
**Verified:** 2026-04-08T20:30:00Z
**Status:** gaps_found (documentation gap only — all code verified)
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (from ROADMAP.md Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `~/.copland.yml` accepts asana block with project GIDs, tag/section filters, and `task_source: asana` per-repo | ✓ VERIFIED | `GlobalConfig::asanaToken()`, `asanaProjectForRepo()`, `asanaFiltersForRepo()` exist at lines 171-196; `RepoConfig::taskSource()` at line 113 |
| 2 | Copland fetches open tasks from Asana project via REST API and passes through same selector pipeline | ✓ VERIFIED | `AsanaService::getOpenTasks()` fetches `/projects/{gid}/tasks`; `AsanaTaskSource::fetchTasks()` returns result to orchestrator |
| 3 | When a GitHub PR is opened for an Asana task, Copland posts a comment with the PR URL to the Asana task | ✓ VERIFIED | `RunOrchestratorService` line 271 calls `$this->taskSource->addComment()` with PR URL; `AsanaTaskSource::addComment()` delegates to `AsanaService::addStory()` |
| 4 | Asana task GIDs handled as strings throughout pipeline without type errors or truncation | ✓ VERIFIED | `SelectionResult`, `RunResult`, `RunProgressSnapshot` all declare `string|int|null $selectedTaskId`; `AsanaTaskSource` casts `$taskId` to `string` before passing to AsanaService |
| 5 | When no Asana tasks available, Copland exits cleanly with informative message | ✓ VERIFIED | Empty task list from `getOpenTasks()` flows through prefilter → selector returns `skip_all` → orchestrator exits at line 62-74 with `status: skipped` and reason message |

**Score:** 5/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/AsanaService.php` | Asana REST API client | ✓ VERIFIED | Exists, 172 lines; `getOpenTasks()`, `addStory()`, `removeTag()`, `applyFilters()`, `requestJson()` all present |
| `app/Services/AsanaTaskSource.php` | TaskSource implementation for Asana | ✓ VERIFIED | `final class AsanaTaskSource implements TaskSource`, all 4 interface methods implemented |
| `app/Config/GlobalConfig.php` | Asana config getters | ✓ VERIFIED | `asanaToken()`, `asanaProjectForRepo()`, `asanaFiltersForRepo()` at lines 171-196 |
| `app/Config/RepoConfig.php` | taskSource getter | ✓ VERIFIED | `taskSource()` at line 113, returns `$this->data['task_source'] ?? 'github'` |
| `app/Commands/RunCommand.php` | Conditional TaskSource wiring | ✓ VERIFIED | Conditional `$taskSource` factory at lines 265-274; `taskSource: $taskSource` in orchestrator constructor |
| `app/Data/SelectionResult.php` | selectedTaskId property | ✓ VERIFIED | `string|int|null $selectedTaskId` at line 9 |
| `app/Data/RunResult.php` | selectedTaskId property | ✓ VERIFIED | `string|int|null $selectedTaskId` at line 12 |
| `app/Support/RunProgressSnapshot.php` | selectedTaskId property | ✓ VERIFIED | `string|int|null $selectedTaskId` at line 13 |
| `tests/Unit/AsanaServiceTest.php` | Unit tests for AsanaService | ✓ VERIFIED | 9 test cases using Guzzle MockHandler |
| `tests/Unit/AsanaTaskSourceTest.php` | Unit tests for AsanaTaskSource | ✓ VERIFIED | 5 test cases using Mockery |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `AsanaTaskSource.php` | `AsanaService.php` | `private AsanaService $asana` constructor injection | ✓ WIRED | Line 19: `private AsanaService $asana`; all calls route through it |
| `AsanaTaskSource.php` | `GitHubService.php` | `private GitHubService $github` for PR creation | ✓ WIRED | Line 20: `private GitHubService $github`; `openDraftPr()` delegates to `$this->github->createDraftPr()` |
| `RunCommand.php` | `AsanaTaskSource.php` | `new AsanaTaskSource()` when `task_source: asana` | ✓ WIRED | Lines 265-273: ternary selects `AsanaTaskSource` or `GitHubTaskSource` based on `$repoConfig->taskSource()` |
| `AsanaService.php` | Asana REST API | Guzzle `base_uri: https://app.asana.com/api/1.0/` | ✓ WIRED | Lines 27-28: Guzzle client configured with correct base URI |
| `ClaudeSelectorService.php` | `SelectionResult.php` | named arg `selectedTaskId:` | ✓ WIRED | Line 60: `selectedTaskId: $json['selected_task_id'] ?? null` |
| `RunOrchestratorService.php` | `taskSource->addComment()` | PR URL comment-back at completion | ✓ WIRED | Line 271-275: calls `addComment()` with PR URL in success path |

### Data-Flow Trace (Level 4)

Not applicable — all components are service/config classes, not UI renderers. The data flow is: Asana REST API → `AsanaService::getOpenTasks()` → `AsanaTaskSource::fetchTasks()` → `RunOrchestratorService` → selector pipeline. This flow is verified by test coverage with MockHandler.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Full test suite passes | `XDEBUG_MODE=off ./vendor/bin/pest --no-coverage` | 132 passed (430 assertions) | ✓ PASS |
| No remaining `selectedIssueNumber` references | `grep -rn "selectedIssueNumber" app/ resources/` | 0 matches | ✓ PASS |
| AsanaService syntax valid | `php -l app/Services/AsanaService.php` | No syntax errors | ✓ PASS |
| AsanaTaskSource syntax valid | `php -l app/Services/AsanaTaskSource.php` | No syntax errors | ✓ PASS |
| GlobalConfig has all Asana getters | `grep "asanaToken\|asanaProjectForRepo\|asanaFiltersForRepo" app/Config/GlobalConfig.php` | 3 matches (lines 171, 176, 187) | ✓ PASS |
| RunCommand wires AsanaTaskSource | `grep "taskSource.*asana" app/Commands/RunCommand.php` | Line 265 found | ✓ PASS |

### Requirements Coverage

| Requirement | Plans | Description | Status | Evidence |
|-------------|-------|-------------|--------|----------|
| ASANA-01 | 17-02 | User can map Asana projects to repos in `~/.copland.yml` | ✓ SATISFIED | `GlobalConfig::asanaToken()`, `asanaProjectForRepo()`, `asanaFiltersForRepo()`; `RepoConfig::taskSource()` |
| ASANA-02 | 17-03, 17-04 | Copland fetches open tasks from configured Asana project | ✓ SATISFIED (code) / ✗ REQUIREMENTS.md stale | `AsanaService::getOpenTasks()` implemented and tested; REQUIREMENTS.md still shows `[ ]` Pending |
| ASANA-03 | 17-03 | User can filter Asana tasks by tag or section name | ✓ SATISFIED (code) / ✗ REQUIREMENTS.md stale | `AsanaService::applyFilters()` implements AND logic for tags + section; 6 filter tests pass; REQUIREMENTS.md still shows `[ ]` Pending |
| ASANA-04 | 17-01, 17-04 | Copland adds comment to Asana task with GitHub PR link | ✓ SATISFIED | `RunOrchestratorService` calls `taskSource->addComment()` with PR URL; `AsanaTaskSource` delegates to `addStory()` |
| ASANA-05 | 17-01, 17-02, 17-04 | User can configure Asana as task source per repo | ✓ SATISFIED | `RepoConfig::taskSource()` returns `'asana'`; `RunCommand` wires `AsanaTaskSource` when `task_source: asana` |

**Orphaned requirements:** None — all ASANA-01 through ASANA-05 are claimed in phase plans.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Services/AsanaService.php` | 16 | `class AsanaService` (not `final`) | ℹ️ Info | Plan 03 specified `final class`; not final in implementation. No behavioral impact — class works correctly without `final`. |

No placeholder implementations, stub returns, or TODO comments found in any Asana integration files.

### Human Verification Required

#### 1. End-to-End Asana Pipeline with Live API

**Test:** Configure `~/.copland.yml` with a valid Asana PAT (`asana_token`) and a real project GID (`asana_project`). Set `task_source: asana` in a repo's `.copland.yml`. Run `copland run [repo]`.
**Expected:** Copland fetches open tasks from the Asana project, the selector picks a task, the executor implements it, a draft PR is opened on GitHub, and a comment containing the PR URL appears on the Asana task.
**Why human:** Requires live Asana account with API access, a real project with open tasks, and a configured GitHub repo. Cannot be exercised without external service credentials.

### Gaps Summary

The codebase fully implements the Phase 17 goal. All five success criteria from ROADMAP.md are satisfied in code and backed by 132 passing tests (including 9 AsanaService tests, 5 AsanaTaskSource tests, 11 GlobalConfig Asana tests, and 4 RepoConfig taskSource tests).

The single gap is a documentation inconsistency: `ASANA-02` (task fetching) and `ASANA-03` (tag/section filtering) are both fully implemented in code but `REQUIREMENTS.md` was not updated to mark them complete. The traceability table still shows `Pending` for both. This does not affect functionality but misrepresents the project's state.

**Action required:** Update `.planning/REQUIREMENTS.md` — change `[ ]` to `[x]` for ASANA-02 and ASANA-03, and change their traceability rows from `Pending` to `Complete`.

---

_Verified: 2026-04-08T20:30:00Z_
_Verifier: Claude (gsd-verifier)_

# Phase 17: Asana Integration - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-08
**Phase:** 17-asana-integration
**Areas discussed:** Config schema, Filtering (ASANA-03), SelectionResult widening, AsanaTaskSource design

---

## Config Schema

### PAT key name

| Option | Description | Selected |
|--------|-------------|----------|
| `asana_token` at top level | Mirrors `claude_api_key` placement | ✓ |
| `asana.token` nested | Everything Asana-related in one block | |

**User's choice:** `asana_token` at top level
**Notes:** Consistent with existing top-level credential pattern.

---

### Project-to-repo mapping structure

| Option | Description | Selected |
|--------|-------------|----------|
| Nested under `repos:` entries | Add `asana_project` + `asana_filters` keys to each repo entry | ✓ |
| Separate top-level `asana:` block | Distinct project-to-repo mapping list | |

**User's choice:** Nested under `repos:` entries
**Notes:** Keeps all per-repo config co-located. Exact schema selected:
```yaml
repos:
  - slug: owner/repo
    path: /path/to/repo
    asana_project: "1234567890"
    asana_filters:
      tags: [agent-ready]
      section: Backlog
```

---

### Per-repo task source activation

| Option | Description | Selected |
|--------|-------------|----------|
| `task_source: asana` in repo-level `.copland.yml` | Explicit opt-in; defaults to `github` | ✓ |
| Auto-detect from global config | If `asana_project` set, assume asana | |

**User's choice:** `task_source: asana` in repo-level `.copland.yml`
**Notes:** No breaking change for existing repos that don't set the key.

---

## Filtering (ASANA-03)

### Tag vs Section support

| Option | Description | Selected |
|--------|-------------|----------|
| Both, independently (AND when both set) | `tags: [...]` and/or `section: name` both optional | ✓ |
| Tags only | Closest to GitHub label filtering | |
| Section only | Structural container filtering | |

**User's choice:** Both independently supported
**Notes:** `tags` requires ALL listed tags; `section` requires exact section name match. AND logic when both configured.

---

### Filtering approach

| Option | Description | Selected |
|--------|-------------|----------|
| Fetch all open tasks, filter client-side | One API request, PHP filtering | ✓ |
| Asana API task search with server-side params | More complex, fewer results | |

**User's choice:** Client-side filtering in PHP
**Notes:** Sufficient for personal-scale projects.

---

## SelectionResult Widening

### Field rename vs in-place widen

| Option | Description | Selected |
|--------|-------------|----------|
| Rename to `selectedTaskId` + widen to `string|int|null` | Semantically correct for both GitHub and Asana | ✓ |
| Keep `selectedIssueNumber`, widen type | Fewer renames but confusing for Asana tasks | |

**User's choice:** Rename to `selectedTaskId` with `string|int|null` type
**Notes:** Call sites in `ClaudeSelectorService`, `RunOrchestratorService`, `PlanCommand`, prompt templates, and tests must all be updated.

---

## AsanaTaskSource Design

### openDraftPr() responsibility

| Option | Description | Selected |
|--------|-------------|----------|
| `AsanaTaskSource` holds `GitHubService` internally | `openDraftPr()` delegates to GitHub; `addComment()` goes to Asana | ✓ |
| Split the `TaskSource` interface | Separate `IssueSource` + `PrSource` | |

**User's choice:** AsanaTaskSource holds both AsanaService and GitHubService
**Notes:** Anticipated in Phase 16. No interface changes required.

---

### HTTP client approach

| Option | Description | Selected |
|--------|-------------|----------|
| `AsanaService` constructs Guzzle client internally | Follows `GitHubService` pattern | ✓ |
| Inject Guzzle client via constructor | Easier to mock; also consistent with GitHubService | |

**User's choice:** Construct Guzzle client internally in AsanaService
**Notes:** Follow GitHubService HTTP client setup pattern.

---

*Phase: 17-asana-integration*
*Discussion date: 2026-04-08*

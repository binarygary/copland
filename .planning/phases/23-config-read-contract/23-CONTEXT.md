# Phase 23: Config Read Contract - Context

**Gathered:** 2026-05-29
**Status:** Ready for planning
**Source:** Inline (small, well-pinned phase — discuss-phase skipped by user)

<domain>
## Phase Boundary

Phase 23 ships the **read** side of the v2.1 hybrid config architecture: a single `copland config show --json` subcommand that emits the merged global + per-repo configuration as JSON. This phase touches PHP only — no Godot work, no write paths. Subsequent phases (24-26) build the write subcommands on top of this shape.

The Godot console (and any other future consumer) reads this JSON instead of parsing YAML directly. That's the architectural invariant: PHP owns YAML schema knowledge; downstream consumers consume JSON.

</domain>

<decisions>
## Implementation Decisions

### Command surface
- New `app/Commands/ConfigCommand.php` with the Laravel Zero signature `config:show {--json}`. The bare `config:show` (no `--json`) prints a human-readable summary; `--json` emits machine-readable JSON. v2.1's downstream consumers always pass `--json`.
- Lives under the `config` command namespace because phases 24-26 add siblings (`config:repos:add`, `config:asana:set-token`, etc.).

### JSON shape (v1 schema)
Top-level keys:
- `schema_version: 1` — pin point for future consumers.
- `defaults`: `max_files_changed`, `max_lines_changed`, `base_branch`, `selector_model`, `planner_model`, `executor_model`.
- `asana_token_set`: boolean. **Never the raw token.** (Redaction guarantee — roadmap CFG-01 criterion #1.)
- `repos`: array of objects. Each entry:
  - `slug`: string
  - `path`: absolute path string
  - `asana_project`: string or null
  - `asana_filters`: array (empty if none)
  - `local_config`: object (the parsed per-repo `.copland.yml`) or null if the file is absent. When present: `task_source`, `repo_summary`, `conventions`, and any `llm` stage overrides. Fields that the YAML omits surface as null (no defaults filling — defaults belong at the top level).

### Stdout / stderr / exit discipline
- `--json` mode: stdout is exactly one JSON document followed by a single newline, and nothing else. No progress chatter, no log output, no banner.
- Non-`--json` mode: human-readable text to stdout.
- Exit 0 on success.
- Exit non-zero on: `~/.copland.yml` missing, `~/.copland.yml` malformed (YAML parse error), or any configured repo path that does not exist on disk. Error messages go to stderr.
- A configured repo whose `.copland.yml` is missing is **not** an error — its `local_config` is null and we move on.

### Schema documentation
- A fixture JSON file under `tests/fixtures/config/show-snapshot.json` is the canonical example. The command's `--help` output references it.
- Pest test asserts the live output's keys + types match the fixture's shape (not a byte-for-byte match — values vary).

### Token redaction (security-sensitive)
- The boolean `asana_token_set` is derived from `strlen(trim($token)) > 0`. Empty string and whitespace-only both count as "not set."
- There is NO debug flag, env var, or alternate mode that emits the raw token. (Phase 26 will let the user *set* it via a write subcommand, but read-side always redacts.)

### What this phase does NOT do
- No write subcommands (`config repos add`, etc.) — those are phases 24-26.
- No Godot integration — the console consumer is built on top of this in later phases.
- No caching of the snapshot — every invocation re-reads YAML. Phase is small enough that this is fine.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### v2.1 milestone artifacts
- `.planning/REQUIREMENTS.md` — CFG-01 full text + Out of Scope ("Secret rotation / keychain integration is out of scope for v2.1")
- `.planning/ROADMAP.md` — Phase 23 section (success criteria #1-#4)

### Existing config classes (mandatory reading for planner)
- `app/Config/GlobalConfig.php` — owns `~/.copland.yml`; methods used: `repos()`, `configuredRepos()`, `asanaToken()`, `asanaProjectForRepo()`, `asanaFiltersForRepo()`, `defaultMaxFiles()`, `defaultMaxLines()`, `defaultBaseBranch()`, `selectorModel()`, `plannerModel()`, `executorModel()`
- `app/Config/RepoConfig.php` — owns per-repo `.copland.yml`; methods used: `taskSource()`, `conventions()`, `llmConfig()`, plus `repoSummary()` if present

### Existing command pattern to mirror
- `app/Commands/StatusCommand.php` — smallest existing CLI command (one-liner-ish); shape your `ConfigCommand` similarly
- `app/Commands/IssuesCommand.php` — example of a command that reads config and prints structured output

### Test pattern to mirror
- `tests/Feature/` for any command-level integration test that uses a temp `HOME` to isolate `~/.copland.yml`
- `tests/Unit/Config/GlobalConfigTest.php` (if exists) for YAML-parsing patterns

</canonical_refs>

<specifics>
## Specific Ideas

### Example output shape (illustrative — fixture file owns the canonical version)

```json
{
  "schema_version": 1,
  "defaults": {
    "max_files_changed": 3,
    "max_lines_changed": 250,
    "base_branch": "main",
    "selector_model": "claude-haiku-4-5",
    "planner_model": "claude-sonnet-4-6",
    "executor_model": "claude-sonnet-4-6"
  },
  "asana_token_set": true,
  "repos": [
    {
      "slug": "owner/copland",
      "path": "/Users/garykovar/projects/codeable/copland",
      "asana_project": null,
      "asana_filters": [],
      "local_config": {
        "task_source": "github",
        "repo_summary": "PHP CLI tool...",
        "conventions": "...",
        "llm": {}
      }
    }
  ]
}
```

### Test coverage targets (Pest)
- Happy path: fixture global config + one fixture repo with a `.copland.yml` → assert shape matches fixture
- Missing per-repo `.copland.yml` → `local_config: null`, command still exits 0
- Empty `asana_token` (empty string) → `asana_token_set: false`
- Whitespace-only `asana_token` → `asana_token_set: false`
- Token set → `asana_token_set: true`, raw value absent from output
- Missing `~/.copland.yml` → non-zero exit, error to stderr
- Malformed `~/.copland.yml` (YAML syntax error) → non-zero exit, error to stderr
- Configured repo whose `path` does not exist → non-zero exit, error to stderr identifying the missing path

</specifics>

<deferred>
## Deferred Ideas

- Pretty-printed JSON (`--pretty` flag) — current output is compact one-line JSON; pretty-printing is trivial to add later if a consumer needs it.
- Schema migration / `schema_version: 2` handling — premature; we add this when a real consumer pins.
- Streaming output for very large config files — Copland is a personal tool; configs stay tiny.
- Live-reload / file-watcher mode for the console — out of v2.1 scope.

</deferred>

---

*Phase: 23-config-read-contract*
*Context gathered: 2026-05-29 inline (small phase, discuss-phase skipped per user)*

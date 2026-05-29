# Phase 24: Config Write — Global Repos List - Context

**Gathered:** 2026-05-29
**Status:** Ready for planning
**Source:** /gsd:discuss-phase 24

<domain>
## Phase Boundary

Phase 24 ships the **write** side of `~/.copland.yml`'s global `repos[]` list — three new CLI subcommands (`copland config:repos:add`, `config:repos:edit`, `config:repos:remove`) plus a Godot console screen that drives them via `OS.execute`.

This is the first write-side phase of v2.1. Decisions captured here lock the patterns Phases 25 and 26 will reuse:

- **YAML mutation strategy** (scoped-block replacement)
- **Godot config UI architecture** (single hub scene with sub-navigation)
- **`copland` binary discovery from Godot** (cmdline-arg from `copland console`, PATH fallback)

Scope is strictly slug + path. Asana fields belong to Phase 26 (CFG-03); per-repo `.copland.yml` belongs to Phase 25 (CFG-04).

</domain>

<decisions>
## Implementation Decisions

### YAML comment preservation: scoped-block replacement

- All write subcommands operate on `~/.copland.yml` using **scoped-block replacement**: locate the target block (here, `repos:`) via regex, parse + rewrite ONLY that block via Symfony YAML's `Yaml::dump()`, then splice the rewritten block back into the original file.
- Comments **outside** the target block are preserved byte-for-byte (the file's commented-out `# repos:`, `# llm:` examples and the default `defaults:` / `models:` annotations all survive untouched).
- Comments **inside** the target block (`repos:`) are lost on rewrite. Acceptable trade-off — users rarely annotate individual repo entries, and the alternative (surgical string edits) is too fragile against arbitrary YAML.
- When the target block does **not** exist in the file (e.g. the user removed `repos:` entirely or starts from a config that doesn't declare it), the rewrite appends the block at the end of the file with a single blank line separator.
- A small reusable helper (e.g. `App\Support\YamlBlockEditor` or similar — name owned by the planner) encapsulates locate-parse-rewrite-splice. **Phases 25 and 26 use this same helper** for their target blocks (`asana_token:`, `defaults:`, `models:`, per-repo `.copland.yml` fields).
- Edge cases the helper must handle: missing trailing newline, CRLF line endings, blocks at start-of-file vs end-of-file, blocks indented under a parent (not relevant for top-level `repos:` but relevant for Phase 25's per-repo fields).

### Godot console architecture: single Config hub scene

- New `console-godot/scenes/Config.tscn` + `console-godot/scripts/Config.gd`. Hosts **four sub-views** in a single scene: Repos (this phase), Per-Repo (Phase 25), Asana (Phase 26), Defaults & Models (Phase 26).
- Sub-view navigation: top tab strip OR left rail (the planner chooses based on screen layout fit; both are equivalent for this decision).
- `Main.gd` gains **one** new keybinding (suggested: `C`) that transitions the scene to `Config.tscn` via `get_tree().change_scene_to_file()` or similar. ESC from the Config hub returns to Main.
- The hub scene is responsible for: reading `copland config:show --json` to populate panels, rendering the active sub-view, dispatching writes via `OS.execute`, refreshing the snapshot after a successful write (re-call `config:show --json`), and surfacing CLI stderr on non-zero exits.
- Phase 24 ships **only the Repos sub-view**; the other three panels are stubbed with `# TODO: Phase 25/26` placeholders so the navigation structure is in place but doesn't ship empty unfinished UI.
- Avoid adding helpers to `Main.gd` for the Config hub — `Config.gd` is self-contained. If shared theme/typography constants emerge, extract them to a small autoload (`scripts/Theme.gd`) rather than cross-referencing `Main.gd`.

### `copland` binary discovery: cmdline-arg from `copland console` with PATH fallback

- `copland console` (the PHP CLI subcommand that launches Godot) passes `--copland-bin /path/to/copland` as a Godot launch argument.
- The Godot scripts (`Main.gd` and the new `Config.gd`) read the arg via `OS.get_cmdline_args()` on startup and store it in a single shared constant or autoload.
- **Fallback for manual Godot launches** (developer pressing F5 in the editor): probe `OS.execute("which", ["copland"])` (Unix) and use the first match. If neither the cmdline arg nor PATH yields a binary, surface a startup error in the console (modal or persistent banner) instead of crashing on the first `OS.execute` call.
- Retire `COPLAND_BIN_DEFAULT := "/Users/garykovar/projects/codeable/copland/copland"` from `Main.gd` — it currently hardcodes Gary's path and would ship broken on anyone else's machine.

### Slug rename UX + edit scope: slug immutable, slug+path only

- `config:repos:add --slug <slug> --path <abs-path>` — both required, both validated (slug matches `[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+`, path resolves to an existing directory on disk).
- `config:repos:edit --slug <slug> --path <abs-path>` — locates by slug, rewrites only path. Slug is **immutable** in this surface.
- `config:repos:remove --slug <slug>` — drops the entry. No `--force`, no confirmation flag (the Godot UI can add its own confirmation step before invoking).
- **Renaming a slug** (e.g. GitHub repo gets renamed) requires `remove --slug <old>` then `add --slug <new> --path <path>`. The Godot UI may optionally offer a "rename slug" affordance that wraps the two calls atomically, but the CLI surface stays minimal.
- `add` does **NOT** accept `--asana-project` or `--asana-filters`. Those flags arrive in Phase 26 with their own subcommand (`config:asana:set ...`). Keeps CFG-02 scope clean and avoids forward-compatibility flags that would ship dead.
- Duplicate-slug add → exit non-zero with stderr `Repo '<slug>' already exists. Use config:repos:edit to change its path.`
- Edit/remove on a nonexistent slug → exit non-zero with stderr `Repo '<slug>' not found in ~/.copland.yml.`
- Invalid path on add/edit → exit non-zero with stderr `Path '<path>' does not exist or is not a directory.` (Mirrors the validation Phase 23's CFG-01 error path already uses.)

### Out-of-scope reminders for the planner

- No write paths for Asana / per-repo / defaults — those are Phases 25/26.
- No Godot work beyond the Repos sub-view + hub-scene navigation skeleton.
- No keychain integration for any credentials.
- No `--dry-run` / `--diff` preview flags (deferred per REQUIREMENTS.md Future bucket).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### v2.1 milestone artifacts
- `.planning/REQUIREMENTS.md` — CFG-02 + CFG-06 full text; Future + Out of Scope bounds
- `.planning/ROADMAP.md` — Phase 24 section (4 success criteria + `Depends on: Phase 23`)
- `.planning/phases/23-config-read-contract/23-CONTEXT.md` — JSON snapshot shape Phase 24 reuses for refresh-after-write
- `.planning/phases/23-config-read-contract/23-01-SUMMARY.md` — what shipped + the `ConfigShowService` API the new write subcommands lean on for verification reads

### Existing PHP infrastructure (mandatory reading for planner)
- `app/Config/GlobalConfig.php` — owns `~/.copland.yml`; the write subcommands extend it (or add a sibling `GlobalConfigWriter`)
- `app/Services/ConfigShowService.php` — Phase 23's snapshot builder; the Godot refresh path re-invokes the command that wraps it
- `app/Commands/ConfigShowCommand.php` — existing `config:show` command, the namespace pattern (`config:repos:add` etc.) mirrors its registration

### Existing Godot infrastructure
- `console-godot/scripts/Main.gd` — the 2495-line existing console; the new keybinding (`C`) hooks here, and `COPLAND_BIN_DEFAULT` at line 172 is retired in this phase
- `console-godot/project.godot` — register the new `scenes/Config.tscn` as a scene

### Existing test patterns
- `tests/Feature/ConfigShowCommandTest.php` — feature-test pattern with tmp `HOME`; new write tests should mirror its `setUp/tearDown` HOME isolation
- `tests/Unit/ConfigShowServiceTest.php` — unit-test pattern for a service that reads `~/.copland.yml`

</canonical_refs>

<specifics>
## Specific Ideas

### CLI subcommand surface (Laravel Zero signatures)

```
config:repos:add {--slug=} {--path=}
config:repos:edit {--slug=} {--path=}
config:repos:remove {--slug=}
```

### Sample failure messages (stderr; exact wording owned by planner but in this spirit)

- `Repo 'owner/foo' already exists. Use config:repos:edit to change its path.`
- `Repo 'owner/foo' not found in ~/.copland.yml.`
- `Path '/missing/path' does not exist or is not a directory.`
- `Missing required flag: --slug`

### Test coverage targets (Pest, in addition to Phase 23's patterns)

- Each subcommand: happy path against a tmp `HOME` with a fixture `~/.copland.yml`
- Comment preservation: load a fixture with a comment block above `repos:` and a comment block below it; assert both survive byte-for-byte after each subcommand
- Duplicate-slug add → non-zero exit + correct stderr
- Edit/remove on missing slug → non-zero exit + correct stderr
- Invalid path on add/edit → non-zero exit + correct stderr
- Empty `repos: []` start state — add fills it; remove (when only one entry) leaves `repos: []` not `repos:`
- File-missing → reuse Phase 23's preflight; non-zero exit + correct stderr

### Godot Config hub keybindings (suggested, planner can adjust)

- `C` from Main → open Config hub
- `ESC` from Config → return to Main
- `1` / `2` / `3` / `4` inside Config → switch between Repos / Per-Repo / Asana / Defaults sub-views (other three are stubs in Phase 24)
- `A` inside Repos sub-view → "add" affordance (opens an inline form)
- `E` on a selected repo row → "edit path"
- `D` (or `Delete`) on a selected repo row → "remove" with a confirmation prompt before invoking the CLI

</specifics>

<deferred>
## Deferred Ideas

- `--dry-run` / `--diff` preview before write (REQUIREMENTS.md Future bucket)
- Rename-slug affordance in the Godot UI wrapping remove+add atomically (mentioned above; not in the must-do list — planner can include or defer)
- Comment preservation INSIDE the `repos:` block (out of scope; full preservation is the surgical-edit strategy we rejected)
- Bulk import / export of repos (REQUIREMENTS.md Future bucket)
- Theme autoload (`scripts/Theme.gd`) extraction — only if shared style emerges naturally between Main and Config; not required for Phase 24 alone
- Optimistic Godot UI updates (mutate local state before CLI returns) — for v2.1 we always refresh from `config:show --json` after a successful write

</deferred>

---

*Phase: 24-config-write-global-repos-list*
*Context gathered: 2026-05-29 via /gsd:discuss-phase*

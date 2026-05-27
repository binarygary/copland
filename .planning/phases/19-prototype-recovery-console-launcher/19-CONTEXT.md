# Phase 19: Prototype Recovery + Console Launcher - Context

**Gathered:** 2026-05-26
**Status:** Ready for planning

<domain>
## Phase Boundary

Restore the Godot 4.2+ prototype from `backup/local-main-diverged-20260526` onto `main` under `console-godot/` (preserving the existing `console-godot/assets/{fonts,textures,themes}/` subtrees), and add a new Laravel Zero command `copland console` that launches the restored Godot project as a separate GUI process. The Godot project already reads its task data directly from `~/.copland/tasks/` via `HOME` (see `console-godot/scripts/TaskLoader.gd` on the backup branch) — `copland console` only needs to launch it, not configure paths. Out of scope: bundling Godot, writing task.md/status.md (Phase 20), per-run artifacts (Phase 21), README/docs updates (Phase 22).

</domain>

<decisions>
## Implementation Decisions

### Restore mechanism
- **D-01:** Restore as a **single checkout commit**. Use `git checkout backup/local-main-diverged-20260526 -- console-godot/` (which leaves `console-godot/assets/` untouched since they already match), then commit all restored files in one commit. Loses authorship history from the backup branch, but the prototype is being adopted as a whole artifact — not as a series of evolving changes — so single-commit framing matches intent.
- **D-02:** Restore `console-godot/README.md` and `console-godot/TODO.md` **verbatim** from the backup branch. Do NOT edit them to reflect v2.0 reality in this phase. Documentation alignment (mentioning `copland console`, retargeting deferred items to v2.1) is owned by Phase 22 (CONS-02/CONS-03). Keeps the restore commit clean and avoids mixing "restore from backup" with "write new docs" in one phase.
- **D-03:** Files to restore (full list, from the backup tree): `console-godot/project.godot`, `console-godot/icon.svg`, `console-godot/README.md`, `console-godot/TODO.md`, `console-godot/scenes/Main.tscn`, `console-godot/scripts/Main.gd`, `console-godot/scripts/TaskLoader.gd`. The `console-godot/assets/{fonts,textures,themes}/` directories are already present on `main` and are intentionally NOT re-restored.

### Launch mechanism
- **D-04:** `copland console` launches Godot via **macOS `open -a Godot`**, NOT via direct binary invocation. Concrete invocation shape: `open -a Godot --args --path /abs/path/to/console-godot/`. macOS Launch Services resolves "Godot" to whatever `Godot.app` the user has installed (typically `/Applications/Godot.app`), so the CLI does no PATH lookup, no app-bundle probing, and exposes no `godot_bin` config key. Honors the OUT-OF-SCOPE constraint "Bundling the Godot runtime with Copland — user installs Godot separately" in `.planning/REQUIREMENTS.md`.
- **D-05:** macOS-only for v2.0. Linux launch path (likely a `godot` PATH lookup fallback) is deferred — `.planning/REQUIREMENTS.md` already scopes Copland to macOS/Linux, and Linux can be added when a Linux user actually needs it. Document the macOS-only constraint where the command lives.
- **D-06:** `copland console` runs `open` **without `-W`** — the command returns immediately after Godot.app is launched. The Godot window appears as its own GUI process and the terminal is freed. Matches the UX of `open -a Slack` and similar GUI launchers. No `--wait` flag is added (premature; can be added later if anyone wants it).

### Error UX
- **D-07:** **Preflight checks with targeted messages**, then non-zero exit on failure. Preflight runs before invoking `open`:
  1. Verify `console-godot/project.godot` exists relative to the project root the CLI is executing in. On failure: `error: console-godot/ not found — run from the Copland project root or restore the prototype (see .planning/phases/19-...).` Exit code non-zero.
  2. Verify `Godot.app` is locatable. Preferred probe: `mdfind "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"` returning at least one hit; fallback probe: `osascript -e 'id of app "Godot"'` exiting 0. On failure: `error: Godot.app not found — install Godot 4.2+ (brew install --cask godot, or https://godotengine.org/).` Exit code non-zero.
- **D-08:** No silent fallbacks. If either preflight fails, the command does not attempt `open -a Godot` — that would surface a less specific failure and might pop a macOS chooser dialog. Preflight first, launch only on success.

### Project-root resolution
- **D-09:** `console-godot/` path passed to `open --args --path` is resolved as an **absolute path** relative to the Copland project root (the directory containing `composer.json` / `app/`), not the CWD where the user invoked `copland console`. Reason: the user may invoke `copland console` from any directory (e.g. their home), but the Godot project lives next to the PHP code. Resolution mechanism: Laravel Zero exposes `base_path()` — use it.

### Claude's Discretion
- Specific implementation details of the preflight probes (which Symfony Process pattern, whether to use `mdfind` first or `osascript` first, exact error message wording) — planner/executor pick what reads cleanly.
- Whether the new `ConsoleCommand` class lives at `app/Commands/ConsoleCommand.php` (it should — matches existing convention) and its `$signature` exact form (likely `console` with no args for v2.0).
- Test approach for the new command — Pest tests should follow the patterns established by existing command tests; injectable seam for the `open`/preflight runner so tests don't actually launch Godot.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope and requirements
- `.planning/ROADMAP.md` §"Phase 19: Prototype Recovery + Console Launcher" — phase goal, success criteria, requirements list (GODOT-01, GODOT-02, GODOT-03)
- `.planning/REQUIREMENTS.md` §"Prototype Recovery" — full text of GODOT-01/02/03; also the §"Out of Scope" section (Godot runtime bundling is explicitly excluded)
- `.planning/PROJECT.md` §"Current Milestone: v2.0 Godot Console" — milestone framing, key context (read-only console, additive-only PHP backend)

### Prototype source (read from backup branch)
- `git show backup/local-main-diverged-20260526:console-godot/project.godot` — Godot 4.2 project config, viewport size, input bindings
- `git show backup/local-main-diverged-20260526:console-godot/scripts/Main.gd` — UI controller; reads `HOME` directly, no CLI-supplied paths needed
- `git show backup/local-main-diverged-20260526:console-godot/scripts/TaskLoader.gd` — confirms `~/.copland/tasks/<repo>/<task>/{task.md, status.md}` shape that Phase 20 must materialize
- `git show backup/local-main-diverged-20260526:console-godot/README.md` — prototype run instructions (Godot 4.2+, F5 to run)
- `git show backup/local-main-diverged-20260526:console-godot/TODO.md` — deferred items (run drill-in, live-tail, Retina) that explicitly belong to v2.1

### Command conventions
- `app/Commands/AutomateCommand.php` — closest analog: a Laravel Zero command that shells out to macOS-specific commands (launchctl) with preflight checks. Use as a structural reference for `ConsoleCommand`.
- `app/Commands/StatusCommand.php`, `app/Commands/RunCommand.php` — additional Laravel Zero command signature/registration examples.

### Codebase intel
- `.planning/codebase/STRUCTURE.md` — repo layout
- `.planning/codebase/CONVENTIONS.md` — naming, error-handling, and Symfony Process patterns to follow
- `.planning/codebase/TESTING.md` — Pest test patterns and the injectable-runner seam pattern (`$runner` parameter)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Laravel Zero command pattern** (`app/Commands/*.php`): all commands extend `LaravelZero\Framework\Commands\Command`, declare `protected $signature`, and implement `handle()`. `ConsoleCommand` follows the same shape — no new infrastructure.
- **`AutomateCommand` macOS shell-out pattern**: existing command already shells out to macOS-specific binaries (launchctl), checks for prerequisites, and prints actionable errors. Same approach applies to `open -a Godot`.
- **Symfony Process** (already a project dependency via `symfony/process`): standard way to invoke shell commands in this codebase. `GitService` (`app/Services/GitService.php`) demonstrates the `$runner` callable-injection seam for testability — `ConsoleCommand` can reuse that pattern so tests don't launch Godot.
- **`base_path()` helper** (Laravel Zero): resolves the project root regardless of CWD. Use it to build the absolute path to `console-godot/` for `open --args --path`.
- **`console-godot/assets/{fonts,textures,themes}/` already on main**: do NOT delete or recreate these — `git checkout backup -- console-godot/` will leave them as-is.

### Established Patterns
- **PascalCase command class names**, file names match (`ConsoleCommand.php`).
- **Throw `RuntimeException` for operational failures** with descriptive messages, or use `$this->error()` + return non-zero exit code from `handle()`. `AutomateCommand` is the reference for the latter pattern in command context.
- **Tests inject a `$runner` callable** so production code calls `Symfony\Component\Process\Process` while tests pass a closure returning canned exit codes/output. See `GitServiceTest.php` (per `.planning/codebase/CONVENTIONS.md`).

### Integration Points
- New file: `app/Commands/ConsoleCommand.php` — registered automatically by Laravel Zero's command discovery.
- New file: `tests/Feature/ConsoleCommandTest.php` (or `tests/Unit/`, matching existing test layout) — covers preflight failure paths and the success path (mocked runner).
- No changes to `RunOrchestratorService`, `GitService`, config classes, or any existing command. This phase is purely additive — restore static files + add one command.

</code_context>

<specifics>
## Specific Ideas

- User explicitly asked whether the Godot app could be "compiled to open" (avoid needing a binary install). The trade-off was walked through; the user landed on `open -a Godot` after seeing that bundling Godot is already listed as out-of-scope in REQUIREMENTS.md. This decision is owner-confirmed, not Claude's pick — preserve it.
- The Godot prototype's `TaskLoader.gd` reads `OS.get_environment("HOME") + "/.copland/tasks"` directly. There is NO need for `copland console` to pass a tasks directory via argument or env var — pointing happens by virtue of running under the user's `HOME`. The "pointed at `~/.copland/tasks/`" wording in the success criteria is satisfied by the launch itself, not by an explicit argument.
- The Godot CLI flag `--path <dir>` (passed through `open --args`) tells Godot which project to open — without it, `open -a Godot` would pop the Godot project manager.

</specifics>

<deferred>
## Deferred Ideas

- **Bundling Godot runtime** — user raised it during discussion; explicitly rejected for v2.0 and remains in REQUIREMENTS.md §"Out of Scope". Future milestone may revisit if distribution complexity becomes painful.
- **Linux launch path** — a `godot` PATH lookup fallback for Linux users. Defer until a Linux user actually exists; v2.0 is macOS-only in practice even though Copland targets macOS/Linux.
- **`--wait` flag for blocking launch** — capturing Godot's exit code in the CLI. Not needed today; add only if a real use case appears.
- **`godot_bin` config key in `~/.copland.yml`** — escape hatch for non-standard Godot installs. Skipped under D-04 because `open -a` handles the common case. Revisit if users actually hit it.
- **README/TODO doc alignment with v2.0 reality** — owned by Phase 22 (CONS-02/CONS-03). The restored files stay verbatim in this phase.

</deferred>

---

*Phase: 19-Prototype Recovery + Console Launcher*
*Context gathered: 2026-05-26*

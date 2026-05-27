# Phase 19: Prototype Recovery + Console Launcher - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-26
**Phase:** 19-Prototype Recovery + Console Launcher
**Areas discussed:** Restore mechanism, Restore content (verbatim vs curated), Godot binary discovery → launch mechanism, Launch mode, Error UX

---

## Restore mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Single checkout commit | `git checkout backup -- console-godot/` then one commit. Loses authorship history but adopts the prototype as a whole artifact. | ✓ |
| Cherry-pick prototype commits | Preserves authorship/intent; may require conflict resolution if files were renamed. | |
| Verbatim file copy | `git show backup:path > path` per file, no shared history, cleanest diff. | |

**User's choice:** Single checkout commit
**Notes:** The prototype is being adopted as one artifact; per-file authorship preservation isn't worth the cherry-pick complexity for a single-author backup branch.

---

## Restore content (verbatim vs curated)

| Option | Description | Selected |
|--------|-------------|----------|
| Verbatim restore | Restore README.md and TODO.md exactly as on backup branch. Doc updates owned by Phase 22 (CONS-02/CONS-03). | ✓ |
| Curate during restore | Edit README/TODO to reflect v2.0 (mention `copland console`, retarget deferred items to v2.1) before committing. | |

**User's choice:** Verbatim restore
**Notes:** Keeps the restore commit clean; avoids mixing "restore from backup" with "write new docs" in one phase.

---

## Godot binary discovery / launch mechanism

User initially raised a freeform question: "Can't the app be compiled to open? Instead of needing to specify the binary?" — prompting a re-frame of the question into three concrete launch strategies.

### Re-framed: how should `copland console` actually launch Godot?

| Option | Description | Selected |
|--------|-------------|----------|
| macOS `open -a Godot` | `open -a Godot --args --path /abs/path/to/console-godot/`. macOS resolves Godot.app on its own. No binary discovery code. Honors "no bundling" constraint. macOS-only. | ✓ |
| Explicit binary discovery | PATH → /Applications/Godot.app/Contents/MacOS/Godot → GODOT_BIN env → ~/.copland.yml. Works on Linux too. More code. | |
| Reconsider bundling | Export Godot project to a standalone .app. Conflicts with REQUIREMENTS.md "Out of Scope". Not recommended. | |

**User's choice:** macOS `open -a Godot`
**Notes:** User initially considered bundling, but agreed once it surfaced that bundling Godot is already in the REQUIREMENTS.md out-of-scope list. Linux launch is deferred until a Linux user actually exists.

---

## Launch mode

| Option | Description | Selected |
|--------|-------------|----------|
| Return immediately | `open -a Godot ...` without `-W`. CLI exits cleanly; Godot runs as separate GUI process. Matches `open -a Slack` UX. | ✓ |
| Block until Godot quits | `open -a Godot -W ...`. CLI returns only when window closes. Captures exit code; keeps terminal busy. | |
| Flag-controlled | Default return-immediately, accept `--wait` for blocking. Small surface area but covers both. Probably premature. | |

**User's choice:** Return immediately (default)
**Notes:** GUI app launcher UX. No `--wait` flag added; can be added later if a use case appears.

---

## Error UX

| Option | Description | Selected |
|--------|-------------|----------|
| Preflight + targeted messages | Check console-godot/project.godot exists AND Godot.app is locatable (`mdfind` or `osascript`); print specific message per failure case. Non-zero exit. | ✓ |
| Try-and-explain | Just run `open`; on failure print stderr + one-line install hint. Conflates "Godot missing" with other `open` errors. | |
| Preflight + generic message | Same preflight, single generic message. Worst UX. | |

**User's choice:** Preflight + targeted messages
**Notes:** Specific, actionable errors with concrete install hints (e.g. `brew install --cask godot`). Preflight runs before `open` invocation so no spurious macOS chooser dialog appears.

---

## Claude's Discretion

- Exact wording of preflight error messages (concrete pattern captured in D-07; planner may refine).
- Whether `mdfind` or `osascript` runs first in the Godot.app probe — either works; planner picks the cleaner Symfony Process invocation.
- New `ConsoleCommand` class location (`app/Commands/ConsoleCommand.php` — matches convention) and exact `$signature` form (likely just `console`).
- Test layout — follow existing `tests/` structure with an injectable runner seam so tests don't actually invoke `open -a Godot`.

## Deferred Ideas

- Bundling the Godot runtime — explicitly rejected for v2.0 per REQUIREMENTS.md §"Out of Scope".
- Linux launch fallback — defer until a Linux user exists.
- `--wait` flag for blocking launches — defer until a use case appears.
- `godot_bin` config key in `~/.copland.yml` — defer; `open -a` handles the common case.
- README/TODO doc alignment with v2.0 reality — owned by Phase 22 (CONS-02/CONS-03), not this phase.

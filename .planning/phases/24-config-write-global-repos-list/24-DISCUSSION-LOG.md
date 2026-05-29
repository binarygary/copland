# Phase 24 Discussion Log

**Date:** 2026-05-29
**Mode:** /gsd:discuss-phase (default)
**Areas selected:** 4 of 4 (all)

---

## Area 1: YAML comment preservation strategy

**Question:** How should write subcommands handle YAML comments in `~/.copland.yml`?

**Options presented:**
1. Scoped-block replacement *(Recommended)*
2. Surgical string edits per field
3. Accept comment loss, emit UPGRADE notice
4. Snapshot original + diff-based replay

**User selection:** Scoped-block replacement

**Rationale:** Rewrites only the target YAML block (`repos:` for this phase; later phases reuse for `defaults:`, `models:`, `asana_token:`, per-repo fields). Comments outside the block survive byte-for-byte. Comments inside individual repo entries are lost — acceptable trade-off since users rarely annotate those, and the surgical-edit alternative is too fragile.

---

## Area 2: Godot screen architecture

**Question:** Where do the new config screens live in the Godot project?

**Options presented:**
1. Single Config hub scene with sub-navigation *(Recommended)*
2. Separate scene per config surface
3. Extend Main.gd in place

**User selection:** Single Config hub scene with sub-navigation

**Rationale:** Main.gd is already 2495 lines; adding 4 config surfaces would push it well past 3000. A dedicated `scenes/Config.tscn` + `scripts/Config.gd` hosting all four sub-views (Repos in Phase 24; Per-Repo, Asana, Defaults stubs through Phase 26) keeps Main.gd focused on monitoring and gives one new file ownership of all v2.1 config UI.

---

## Area 3: `copland` binary discovery from Godot

**Question:** How should the Godot console find the `copland` binary when shelling out for writes?

**Options presented:**
1. Passed via command-line arg from `copland console` *(Recommended)*
2. PATH probe at console startup
3. Godot project setting / config file
4. Keep hardcoded `COPLAND_BIN_DEFAULT`

**User selection:** Passed via command-line arg from `copland console`

**Rationale:** `copland console` already launches Godot; passing `--copland-bin /path/to/copland` ensures the console always invokes the same binary that launched it. PATH probe is a fallback for manual Godot launches (developer pressing F5). Retires the existing hardcoded path in `Main.gd:172` which is currently Gary-specific.

---

## Area 4: Slug rename UX + edit scope

**Question:** What does `config:repos:edit` accept, and does `add` take Asana fields now?

**Options presented:**
1. Slug immutable; add takes slug+path only *(Recommended)*
2. Slug immutable; add accepts optional Asana flags now
3. Allow slug rename via edit

**User selection:** Slug immutable; add takes slug+path only

**Rationale:** Keeps CFG-02 boundary clean. Rename = remove+add (the Godot UI may wrap atomically). Asana fields strictly Phase 26 (CFG-03) — no forward-compatibility flags that would ship dead.

---

## Deferred Ideas Captured

- `--dry-run` / `--diff` preview flags
- Rename-slug affordance in Godot UI wrapping remove+add atomically
- Comment preservation inside individual repo entries
- Bulk import / export
- Theme autoload extraction (only if shared style emerges naturally)
- Optimistic Godot UI updates

## Scope Creep Redirected

None — user kept questions focused on Phase 24 implementation.

## Claude's Discretion (areas not asked about)

- Exact CLI flag names beyond the basic shape (planner finalizes)
- Tab strip vs left rail for Config hub sub-navigation (both equivalent for the decision)
- Exact wording of stderr error messages (spirit captured in CONTEXT.md; planner refines)
- Whether to extract a shared YAML-block-editor helper now or inline it and refactor in Phase 25 (planner judges based on code volume)

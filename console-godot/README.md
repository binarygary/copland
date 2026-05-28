# Copland Console — Godot Prototype

Visual control plane for the Copland overnight agent. **Read-only** at this
stage; the CLI remains the source of truth for the task lifecycle.

## Run

### From the CLI (recommended)

- `php ./copland console` from the project root.

This preflights Godot and the `console-godot/` project then shells out via
macOS `open -a Godot` (Phase 19 D-04/D-05).

### From Godot's project manager

1. Install Godot 4.2 or newer (https://godotengine.org/).
2. From Godot's project manager: **Import** → pick this folder's `project.godot`.
3. Press **F5** to run, or click the play button.

## Path contract

```
~/.copland/tasks/<repo-slug-safe>/<task_id>/
├── task.md                 # frontmatter: id, title, repo_path, repo_slug, created_at  + body
├── status.md               # frontmatter: state, updated_at  + transitions table
└── runs/
    └── <run-id>/           # POSIX-safe ISO timestamp (colons → dashes), e.g. 2026-05-27T14-48-59-613Z
        ├── status.md       # frontmatter: state, updated_at
        └── outcome.md      # frontmatter: 9 keys per Phase 21 D-05
```

The frontmatter parser at `scripts/TaskLoader.gd:218-256` only handles
top-level scalar pairs with single/double-quote stripping — writer output
must stay inside those limits.

## Slug normalization

- GitHub `owner/repo` becomes `owner__repo` on disk (Phase 20 D-05). For
  example, `binarygary/copland` becomes `binarygary__copland/` under
  `~/.copland/tasks/`.
- Asana sources are normalized to the registered repo's path basename
  (Phase 20 D-06).

## States

The writer emits exactly 8 states; `TaskLoader.gd` STATES matches them
exactly: `new`, `selected`, `planning`, `planned`, `executing`, `verifying`,
`pr_open`, `blocked`.

A `merged` state was originally in STATES but never written by the
orchestrator — it was removed in Phase 22 per D-07. PR-merge polling that
would write `merged` is deferred to v2.1.

## Layout

```
┌─ COPLAND ───────────────────────────────────────────────────────┐
│ WORKFLOW STATES  │       TASK MANIFEST       │    DOSSIER       │
│ • ALL TASKS  06  │  ▸ T002  Verifier should… │  T002            │
│ • NEW        01  │  ▸ T001  Wire footer to…  │  EXECUTING       │
│ • PLANNING   01  │    T003  Selector promp…  │  ...             │
│ • EXECUTING  01  │    ...                    │                  │
│ ...              │                           │                  │
└─────────────────────────────────────────────────────────────────┘
  ↑/↓ select   TAB cycle pane   ENTER drill in   ESC back   Q quit
```

## Keyboard

| Key       | Action                                     |
|-----------|--------------------------------------------|
| `↑` / `↓` | Move selection within the focused pane     |
| `TAB`     | Cycle focus between states / tasks panes   |
| `ENTER`   | From states pane: jump into task list      |
| `ESC`     | From tasks: back to states; from states: clear state filter |
| `Q`       | Quit                                       |

## Data — real vs. sample

- **Real**: scans `~/.copland/tasks/<repo-slug-safe>/<task_id>/{task.md, status.md, runs/<run-id>/{status.md, outcome.md}}`
  and populates the manifest from disk.
- **Sample**: `scripts/TaskLoader.gd::sample_tasks()` provides hardcoded
  fallback tasks when `~/.copland/tasks/` is empty or missing — useful for
  iterating on visuals without running the CLI.

## Where data lives — `tasks/` vs `runs.jsonl`

Copland writes to two different surfaces that overlap by design but serve
different purposes. `~/.copland/tasks/<repo>/<id>/` is **live console
state**: human-readable markdown with YAML frontmatter, mutating per
lifecycle transition. It is the source of truth for what the Godot console
renders — ephemeral mid-run, terminal state pins after the run completes.
`~/.copland/logs/runs.jsonl` is an **append-only audit trail**: one JSON
record per `copland run` invocation, never modified after append, not
consumed by the console — canonical for cost analytics and retrospective
grepping.

What's running right now? → `tasks/`. What happened over the last 30
nights? → `runs.jsonl`.

## Divergences from the original prototype design

- `merged` state was originally listed in `TaskLoader.gd` STATES but the
  writer never emitted it; removed in Phase 22.
- `runs/<run-id>/outcome.md` is a Phase 21 addition the original prototype
  didn't anticipate; its 9 frontmatter keys are: `run_id`, `status`,
  `pr_number`, `pr_url`, `cost_usd`, `started_at`, `finished_at`,
  `failure_reason`, `partial`.
- The original "Real / Sample" path string was
  `~/.copland/tasks/<repo>/<id>/{task.md, status.md}`; the shipped layout
  is the full tree shown in `## Path contract` above.

## Non-goals (this prototype)

- No editing, task creation, or workflow transitions.
- No shell-out, AI execution, or GitHub calls.
- No animations beyond focus-state changes.

These belong in the CLI; the console is purely a visual layer.

## Visual direction

1930s machine-age orchestration console — Art Deco / Streamline Moderne.
Brass and cream on charcoal, geometric chevrons, no neon, no game HUD,
no terminal emulation. If something starts to feel like a web dashboard
or a cyberpunk HUD, redirect it.

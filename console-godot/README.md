# Copland Console — Godot Prototype

Visual control plane for the Copland overnight agent. **Read-only** at this
stage; the CLI remains the source of truth for the task lifecycle.

## Run

1. Install Godot 4.2 or newer (https://godotengine.org/).
2. From Godot's project manager: **Import** → pick this folder's `project.godot`.
3. Press **F5** to run, or click the play button.

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

## Data

`scripts/TaskLoader.gd`:

- **Real**: scans `~/.copland/tasks/<repo>/<id>/{task.md, status.md}` and
  populates the manifest from disk.
- **Sample**: falls back to hardcoded sample tasks when that directory is
  empty or missing — useful for iterating on visuals without running the CLI.

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

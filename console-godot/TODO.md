# Copland Console — Deferred

## Run drill-in selection

In the task drill-in view, allow ↑/↓ to select among run rows when runs exist,
and `ENTER` to open a deeper view for that run (run id, status, path, prompts,
tool calls if/when those are emitted).

Currently the drill-in renders runs as a static list. Worth wiring once tasks
have actually been run — your `runs/` directories are empty today.

## Live-tail of an executing run

When a task is in the `EXECUTING` state, the dossier (or a dedicated stream
panel) should tail the running executor's tool calls in real time.

Requires the CLI to emit structured progress to a file (e.g. NDJSON in
`~/.copland/tasks/<repo>/<task>/runs/<run-id>/events.log`) or to a unix socket
the console can poll/stream.

This is the biggest "operational console" payoff — when a task transitions
to EXECUTING, the console becomes a live monitoring surface.

## UI scale on Retina

Current setup: `stretch/mode = canvas_items`, `aspect = keep_height`. Designed
for 1680×1050 viewport. On wider screens the viewport stays the same height
but gains horizontal room. On Retina this looks right because canvas_items
mode handles HiDPI cleanly.

If we ever switch to `stretch/mode = disabled` for pixel-perfect rendering,
set `display/window/stretch/scale = 2.0` to compensate for Retina, otherwise
text renders at half size.

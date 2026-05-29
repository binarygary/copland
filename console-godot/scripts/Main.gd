extends Control

const TaskLoader := preload("res://scripts/TaskLoader.gd")

# Copland Visual Console — iteration 2.
#
# Scale, weighting, materiality, restrained motion.
# Target: an alternate-history orchestration console from a machine-age ops
# center. Not a dashboard. Not a HUD. Not a fake terminal.
#
# Architecture stays simple — one Control + inner ornament classes — but
# typography, spacing, panel surfaces, and selection state now carry the
# weight that ornamentation used to.

# ─────────────────────────────────────────────────────────────────────────────
# Palette — extended with recessed/highlight tones for surface materiality.
# No new accent colors; only new tones of the existing brass / cream / dark.
# ─────────────────────────────────────────────────────────────────────────────

const PALETTE := {
    "bg":          Color(0.082, 0.075, 0.066),  # darkest — deep charcoal floor
    "tray":        Color(0.110, 0.100, 0.086),  # footer / header surround
    "panel":       Color(0.137, 0.125, 0.106),  # base panel face
    "panel_lo":    Color(0.108, 0.099, 0.085),  # recessed body interior
    "panel_hi":    Color(0.180, 0.161, 0.133),  # selected row tint
    "edge_hi":     Color(0.241, 0.214, 0.176),  # subtle top edge highlight
    "cream":       Color(0.945, 0.898, 0.788),  # primary text
    "cream_lo":    Color(0.788, 0.741, 0.635),  # secondary text
    "dim":         Color(0.561, 0.518, 0.443),
    "faint":       Color(0.341, 0.314, 0.267),
    "rule":        Color(0.220, 0.196, 0.157),  # quiet structural rules
    "brass":       Color(0.812, 0.682, 0.388),  # primary brass
    "brass_hi":    Color(0.961, 0.835, 0.510),  # selected / focused
    "brass_dim":   Color(0.467, 0.388, 0.220),  # quiet brass for inactive edges
    "teal":        Color(0.353, 0.541, 0.557),
    "teal_hi":     Color(0.510, 0.741, 0.757),
    "danger":      Color(0.792, 0.412, 0.310),
    "merged":      Color(0.451, 0.620, 0.467),
    "shadow":      Color(0, 0, 0, 0.45),
}

const STATE_COLORS := {
    "new":       "dim",
    "selected":  "brass",
    "planning":  "teal",
    "planned":   "teal_hi",
    "executing": "brass_hi",
    "verifying": "teal",
    "pr_open":   "brass_hi",
    "merged":    "merged",
    "blocked":   "danger",
}

const STATE_LABELS := {
    "new":       "NEW",
    "selected":  "SELECTED",
    "planning":  "PLANNING",
    "planned":   "PLANNED",
    "executing": "EXECUTING",
    "verifying": "VERIFYING",
    "pr_open":   "PR OPEN",
    "merged":    "MERGED",
    "blocked":   "BLOCKED",
}

# States that mean a run is already mid-flight for a task. "Run now" refuses to
# relaunch these so the user can't spawn a duplicate run (and a duplicate PR).
const IN_FLIGHT_STATES := ["selected", "planning", "planned", "executing", "verifying"]

# After launching a run we can't trust the optimistic row state (a manifest
# re-pull reverts it before the detached CLI writes 'selected' to the run-store).
# So we also remember launch times locally and refuse a relaunch within this
# window — long enough for the run-store to catch up, after which IN_FLIGHT_STATES
# takes over. Self-expiring so a failed/reset task can still be re-run later.
const RELAUNCH_GRACE_MS := 180000

# ─────────────────────────────────────────────────────────────────────────────
# Type scale — much larger than iter-1. Console-sized, not app-sized.
# ─────────────────────────────────────────────────────────────────────────────

const TYPE := {
    "title":         86,   # COPLAND — the crown
    "subtitle":      14,   # designation under title — quieter than iter-2
    "brand_tag":     11,   # STATION ONE / RUN MANIFEST
    "section":       20,   # WORKFLOW STATES / TASK MANIFEST / DOSSIER
    "task_title":    21,   # selected task title
    "task_title_lo": 17,   # unselected task title
    "task_id":       20,   # T001 badge
    "task_meta":     12,   # quieter than iter-2 — must not compete with title
    "state_row":     15,
    "state_count":   13,
    "detail_id":     36,
    "detail_title":  26,
    "detail_state":  15,
    "detail_meta":   13,
    "detail_body":   15,
    "files":         13,
    "footer_label":  13,
    "footer_key":    14,
    "footer_brand":  11,
}

# Letter-spacing helper for tracked-out labels (we manually insert spaces
# since Godot 4's Label doesn't expose tracking without a custom font).
func _tracked(s: String, gap: int = 1) -> String:
    var spaces := " ".repeat(gap)
    var out := ""
    for i in s.length():
        if i > 0:
            out += spaces
        out += s[i]
    return out


# Format an ISO-8601 timestamp into a compact operational form.
# "2026-05-15T06:04:14.905337-04:00"  →  "2026-05-15  ·  06:04:14"
# Leaves anything we don't recognize unchanged.
func _format_iso(s: String) -> String:
    if s == "":
        return ""
    var t_idx := s.find("T")
    if t_idx < 0:
        return s
    var date_part := s.substr(0, t_idx)
    var rest := s.substr(t_idx + 1)
    # Strip fractional seconds and zone
    var time_end := rest.length()
    for sep in [".", "+", "-", "Z"]:
        var i := rest.find(sep)
        if i >= 0 and i < time_end:
            time_end = i
    var time_part := rest.substr(0, time_end)
    return "%s   ·   %s" % [date_part, time_part]

# ─────────────────────────────────────────────────────────────────────────────
# Runtime state
# ─────────────────────────────────────────────────────────────────────────────

var tasks: Array = []
var filtered_tasks: Array = []
# Filter is "repo|state" where either may be "*" / "all" for "any".
var selected_filter_key: String = "*|all"
var selected_filter_index: int = 0          # index into _navigable_options()
var selected_task_index: int = 0
var focus_region: int = 1                   # 0 = states, 1 = tasks

# UI refs
var state_list_box: VBoxContainer
var task_list_box: VBoxContainer
var task_scroll: ScrollContainer
var detail_id_badge: Label
var detail_title: Label
var detail_state_label: Label
var detail_meta_label: Label
var detail_summary: RichTextLabel
var detail_files_box: VBoxContainer
var detail_empty: Label
var footer_clock: Label
var footer_clusters_row: HBoxContainer
var body_hbox: HBoxContainer
var focus_glow: Control

# Drill-in view
var drill_in_visible: bool = false
var drill_in_panel: PanelContainer
var drill_id_badge: Label
var drill_state_label: Label
var drill_title_label: Label
var drill_meta_box: VBoxContainer
var drill_notes_label: RichTextLabel
var drill_runs_box: VBoxContainer

# Refresh
var refresh_timer: Timer
const REFRESH_INTERVAL := 2.0
# Re-pull the manifest from the CLI every Nth tick (15 × 2s ≈ 30s). The pull
# runs on a worker thread (see tasks_thread) so it never stalls the UI.
const TASKS_PULL_EVERY := 15

# Operations log + async CLI spawn
#
# Path resolution order:
#   1. --copland-bin cmdline arg (passed by `copland console`)
#   2. $COPLAND_BIN env var (developer escape hatch for `godot --editor` launches)
#   3. `which copland` on PATH
#   4. empty string → startup error banner in the ops log; no thread spawn
#
# Spawn runs on a worker Thread so `copland run`/`plan` (which can take minutes)
# don't freeze the UI. Main polls the thread in _process and surfaces output
# when the worker finishes.
const MAX_OPS_LOG_LINES := 6
var copland_bin: String = ""

# "repo#issue" -> Time.get_ticks_msec() when a run was launched via "Run now".
# Backs the relaunch guard so it survives manifest re-pulls (see RELAUNCH_GRACE_MS).
var launched_at_ms: Dictionary = {}
var ops_log_panel: PanelContainer
var ops_log_lines_box: VBoxContainer
var ops_log_buffer: Array = []
var ops_thread: Thread = null
var ops_thread_label: String = ""
# Background manifest pull (copland tasks --json). Separate from ops_thread so a
# long-running `copland run` and a routine manifest refresh never contend.
var tasks_thread: Thread = null
var tasks_pull_counter: int = 0
var status_dot: Control
var status_label: Label

# Modal text input (single-line)
var modal_layer: Control
var modal_title_label: Label
var modal_hint_label: Label
var modal_input: LineEdit
var modal_callback: Callable

# Multi-line text modal (notes editor)
var text_modal_layer: Control
var text_modal_title_label: Label
var text_modal_hint_label: Label
var text_modal_input: TextEdit
var text_modal_callback: Callable

# Console-managed repo registry (separate from CLI's config.yml since the CLI
# has no `register` command). Read alongside ~/.copland/tasks/<slug>/.
const CONSOLE_REPOS_FILE := "console-repos.txt"
var registered_repos: Array = []   # filesystem paths

# Motion
var pulse_t: float = 0.0
var sweep_t: float = 0.0
var drift_t: float = 0.0

# ─────────────────────────────────────────────────────────────────────────────
# Lifecycle
# ─────────────────────────────────────────────────────────────────────────────

func _ready() -> void:
    _load_registered_repos()
    copland_bin = _resolve_copland_bin()
    # The CLI (`copland tasks --json`) is the source of truth: GitHub backlog +
    # prefilter + run-store overlay. Initial pull is synchronous (a one-time
    # startup cost); periodic refreshes run on a worker thread. Fall back to the
    # local store + sample data only when the CLI can't be reached.
    var cli_tasks = TaskLoader.load_from_cli(copland_bin)
    tasks = cli_tasks if cli_tasks != null else TaskLoader.load_real_or_sample(registered_repos)
    _build_ui()
    _build_modal()
    _build_text_modal()
    _apply_state_filter()
    _refresh_state_list()
    _refresh_task_list()
    _refresh_details()
    _refresh_focus_styles()
    set_process(true)

    refresh_timer = Timer.new()
    refresh_timer.wait_time = REFRESH_INTERVAL
    refresh_timer.autostart = true
    refresh_timer.timeout.connect(_on_refresh_tick)
    add_child(refresh_timer)


func _on_refresh_tick() -> void:
    # Light tick (every REFRESH_INTERVAL): pick up externally-edited drill-in
    # notes promptly without re-pulling the whole manifest.
    if drill_in_visible:
        _populate_drill_in()

    # Heavy pull on a slower cadence, dispatched to a worker thread so the
    # synchronous CLI call never stalls the UI. Results land in
    # _poll_tasks_thread() (called from _process).
    tasks_pull_counter += 1
    if tasks_pull_counter >= TASKS_PULL_EVERY:
        tasks_pull_counter = 0
        _start_tasks_pull()


# Kick off a manifest refresh on a worker thread. No-op if a pull is already in
# flight or the binary is unresolved.
func _start_tasks_pull() -> void:
    if tasks_thread != null:
        return
    if copland_bin == "":
        return
    tasks_thread = Thread.new()
    tasks_thread.start(_tasks_pull_worker.bind(copland_bin))


# Runs off the main loop — must not touch the scene tree (TaskLoader only uses
# OS.execute / FileAccess / JSON).
func _tasks_pull_worker(bin: String) -> Variant:
    return TaskLoader.load_from_cli(bin)


func _poll_tasks_thread() -> void:
    if tasks_thread == null or not tasks_thread.is_started():
        return
    if tasks_thread.is_alive():
        return
    var result = tasks_thread.wait_to_finish()
    tasks_thread = null
    # null → CLI unreachable / bad output: keep the current manifest rather than
    # blanking the view on a transient failure.
    if typeof(result) == TYPE_ARRAY:
        _apply_new_tasks(result)


func _exit_tree() -> void:
    # Join the short-lived manifest-pull thread so it isn't freed mid-run.
    if tasks_thread != null and tasks_thread.is_started():
        tasks_thread.wait_to_finish()
        tasks_thread = null


# Diff incoming tasks against the current set and, if changed, swap them in
# while preserving the user's selection (by id) and scroll position.
func _apply_new_tasks(new_tasks: Array) -> void:
    var prev_selected_id := ""
    if not filtered_tasks.is_empty() and selected_task_index < filtered_tasks.size():
        prev_selected_id = String(filtered_tasks[selected_task_index].id)

    if _tasks_signature(new_tasks) == _tasks_signature(tasks):
        if drill_in_visible:
            _populate_drill_in()
        return

    # Detect state transitions before swapping `tasks` so changed rows flash.
    var prev_state_map: Dictionary = {}
    for t in tasks:
        prev_state_map[String(t.id)] = String(t.state)
    var transitioned_ids: Array = []
    for t in new_tasks:
        var id := String(t.id)
        if prev_state_map.has(id) and prev_state_map[id] != String(t.state):
            transitioned_ids.append(id)

    tasks = new_tasks
    _apply_state_filter()

    if prev_selected_id != "":
        for i in filtered_tasks.size():
            if String(filtered_tasks[i].id) == prev_selected_id:
                selected_task_index = i
                break

    var prev_scroll := task_scroll.scroll_vertical if task_scroll else 0
    _refresh_state_list()
    _refresh_task_list()
    _refresh_details()
    if task_scroll:
        task_scroll.scroll_vertical = prev_scroll
    if drill_in_visible:
        _populate_drill_in()

    # Flash rows whose state just changed
    for id in transitioned_ids:
        _attach_flash_to_row(id)


func _attach_flash_to_row(task_id: String) -> void:
    if task_list_box == null:
        return
    for row in task_list_box.get_children():
        if not row.has_meta("task_id"):
            continue
        if String(row.get_meta("task_id")) == task_id:
            var overlay := FlashOverlay.new()
            overlay.set_anchors_preset(Control.PRESET_FULL_RECT)
            overlay.mouse_filter = Control.MOUSE_FILTER_IGNORE
            row.add_child(overlay)
            return


# Coarse signature for change detection — covers task set, ordering, and the
# fields the UI cares about (state, title, runs count, updated timestamp).
func _tasks_signature(arr: Array) -> String:
    var parts: Array = []
    for t in arr:
        parts.append("%s|%s|%s|%s|%d" % [
            String(t.get("id", "")),
            String(t.get("state", "")),
            String(t.get("title", "")),
            String(t.get("updated", "")),
            int(t.get("runs_count", 0)),
        ])
    return "\n".join(parts)


func _process(dt: float) -> void:
    pulse_t = fmod(pulse_t + dt, 2.4)
    sweep_t = fmod(sweep_t + dt, 9.0)
    drift_t = fmod(drift_t + dt, 11.0)
    if footer_clock:
        footer_clock.text = Time.get_datetime_string_from_system(false, true)
    _update_focus_glow()
    queue_redraw()
    _pulse_selected_row()
    _poll_ops_thread()
    _poll_tasks_thread()


func _update_focus_glow() -> void:
    if focus_glow == null:
        return
    var p: PanelContainer = null
    if drill_in_visible and drill_in_panel != null:
        p = drill_in_panel
    elif body_hbox != null:
        var panels: Array = []
        for c in body_hbox.get_children():
            if c is PanelContainer:
                panels.append(c)
        if panels.size() < 3:
            return
        var idx := 0 if focus_region == 0 else 1
        p = panels[idx]
    if p == null:
        return
    var center: Vector2 = p.global_position + p.size * 0.5 - global_position
    var wobble: float = sin(drift_t * TAU / 11.0) * 6.0
    focus_glow.target = center + Vector2(0, wobble)
    focus_glow.intensity = 0.22 + 0.05 * sin(drift_t * TAU / 5.5)
    focus_glow.queue_redraw()


func _input(event: InputEvent) -> void:
    if not (event is InputEventKey):
        return
    var k: InputEventKey = event
    if not k.pressed:
        return

    # When a modal is up, only ESC (cancel) and CMD/CTRL+ENTER (text modal
    # submit) reach the main handler; everything else flows into the active
    # text widget.
    if _modal_visible():
        if k.keycode == KEY_ESCAPE:
            _hide_modal()
            get_viewport().set_input_as_handled()
        return
    if _text_modal_visible():
        if k.keycode == KEY_ESCAPE:
            _hide_text_modal()
            get_viewport().set_input_as_handled()
        elif (k.keycode == KEY_ENTER or k.keycode == KEY_KP_ENTER) and (k.meta_pressed or k.ctrl_pressed):
            _submit_text_modal()
            get_viewport().set_input_as_handled()
        return

    match k.keycode:
        KEY_ESCAPE:
            if drill_in_visible:
                _hide_drill_in()
            elif focus_region == 1:
                focus_region = 0
                _refresh_focus_styles()
            else:
                if selected_filter_key != "*|all":
                    selected_filter_key = "*|all"
                    selected_filter_index = 0
                    _apply_state_filter()
                    _refresh_state_list()
                    _refresh_task_list()
                    _refresh_details()
        KEY_TAB:
            if drill_in_visible:
                return
            focus_region = 1 - focus_region
            _refresh_focus_styles()
        KEY_UP:
            if drill_in_visible:
                return
            _move_selection(-1)
        KEY_DOWN:
            if drill_in_visible:
                return
            _move_selection(1)
        KEY_ENTER, KEY_KP_ENTER:
            if drill_in_visible:
                return
            if focus_region == 0:
                focus_region = 1
                _refresh_focus_styles()
            elif focus_region == 1:
                _show_drill_in()
        KEY_R:
            _action_run_now()
            get_viewport().set_input_as_handled()
        KEY_S:
            _spawn_copland("STATUS", PackedStringArray(["status"]))
            get_viewport().set_input_as_handled()
        KEY_A:
            _action_add_repo()
            get_viewport().set_input_as_handled()
        KEY_E:
            _action_edit_notes()
            get_viewport().set_input_as_handled()
        KEY_C:
            get_tree().change_scene_to_file("res://scenes/Config.tscn")
            get_viewport().set_input_as_handled()


func _move_selection(delta: int) -> void:
    if focus_region == 0:
        var options := _navigable_options()
        if options.is_empty():
            return
        selected_filter_index = wrapi(selected_filter_index + delta, 0, options.size())
        selected_filter_key = String(options[selected_filter_index].key)
        _apply_state_filter()
        _refresh_state_list()
        _refresh_task_list()
        _refresh_details()
    else:
        if filtered_tasks.is_empty():
            return
        selected_task_index = wrapi(selected_task_index + delta, 0, filtered_tasks.size())
        _refresh_task_list()
        _refresh_details()
        _ensure_selected_visible()


func _ensure_selected_visible() -> void:
    if task_scroll == null or selected_task_index >= task_list_box.get_child_count():
        return
    var row: Control = task_list_box.get_child(selected_task_index)
    if row == null:
        return
    var top: float = row.position.y
    var bot: float = top + row.size.y
    var view_top: float = task_scroll.scroll_vertical
    var view_bot: float = view_top + task_scroll.size.y
    if top < view_top:
        task_scroll.scroll_vertical = int(top)
    elif bot > view_bot:
        task_scroll.scroll_vertical = int(bot - task_scroll.size.y)


# ─────────────────────────────────────────────────────────────────────────────
# Data shaping
# ─────────────────────────────────────────────────────────────────────────────

# Filter model
#
# The sidebar shows a global "ALL TASKS" row followed by one group per repo.
# A row's key is "repo|state" where either may be a wildcard:
#   "*|all"                    → every task in every repo
#   "<slug>|all"               → every task in that repo
#   "<slug>|<state>"           → tasks in that repo + state
#
# _navigable_options() returns just the selectable rows (no repo headers),
# in display order. _render_options() returns the full display sequence
# including non-selectable "repo_header" rows.

func _unique_repos() -> Array:
    var seen: Dictionary = {}
    var ordered: Array = []
    for t in tasks:
        var r := String(t.get("repo", ""))
        if r != "" and not seen.has(r):
            seen[r] = true
            ordered.append(r)
    return ordered


func _navigable_options() -> Array:
    var opts: Array = []
    opts.append({
        "kind": "global", "repo": "*", "state": "all",
        "label": "ALL TASKS", "key": "*|all",
    })
    for repo in _unique_repos():
        opts.append({
            "kind": "state", "repo": repo, "state": "all",
            "label": "ALL", "key": "%s|all" % repo,
        })
        for s in TaskLoader.STATES:
            opts.append({
                "kind": "state", "repo": repo, "state": s,
                "label": STATE_LABELS.get(s, s.to_upper()),
                "key": "%s|%s" % [repo, s],
            })
    return opts


func _render_options() -> Array:
    # Same as _navigable_options() but with "repo_header" rows interleaved.
    var opts: Array = []
    opts.append({
        "kind": "global", "repo": "*", "state": "all",
        "label": "ALL TASKS", "key": "*|all",
    })
    for repo in _unique_repos():
        opts.append({"kind": "repo_header", "repo": repo, "label": repo})
        opts.append({
            "kind": "state", "repo": repo, "state": "all",
            "label": "ALL", "key": "%s|all" % repo,
        })
        for s in TaskLoader.STATES:
            opts.append({
                "kind": "state", "repo": repo, "state": s,
                "label": STATE_LABELS.get(s, s.to_upper()),
                "key": "%s|%s" % [repo, s],
            })
    return opts


func _apply_state_filter() -> void:
    var parts := selected_filter_key.split("|")
    var repo: String = parts[0] if parts.size() > 0 else "*"
    var state: String = parts[1] if parts.size() > 1 else "all"
    filtered_tasks = []
    for t in tasks:
        if t.get("is_stub", false):
            continue   # repo stubs only seed the sidebar, never the manifest
        if repo != "*" and String(t.get("repo", "")) != repo:
            continue
        if state != "all" and String(t.get("state", "")) != state:
            continue
        filtered_tasks.append(t)
    selected_task_index = clamp(selected_task_index, 0, max(0, filtered_tasks.size() - 1))


func _option_count(opt: Dictionary) -> int:
    var repo: String = opt.get("repo", "*")
    var state: String = opt.get("state", "all")
    var count := 0
    for t in tasks:
        if t.get("is_stub", false):
            continue
        if repo != "*" and String(t.get("repo", "")) != repo:
            continue
        if state != "all" and String(t.get("state", "")) != state:
            continue
        count += 1
    return count

# ─────────────────────────────────────────────────────────────────────────────
# UI construction
# ─────────────────────────────────────────────────────────────────────────────

func _build_ui() -> void:
    set_anchors_preset(Control.PRESET_FULL_RECT)

    # Deep floor + corner vignette + warm focus glow.
    # The vignette darkens the corners; the focus glow drifts a faint warm
    # halo behind whichever panel currently has focus. Both are restrained.
    var bg := ColorRect.new()
    bg.color = PALETTE.bg
    bg.set_anchors_preset(Control.PRESET_FULL_RECT)
    bg.mouse_filter = Control.MOUSE_FILTER_IGNORE
    add_child(bg)

    var vignette := CornerVignette.new()
    vignette.set_anchors_preset(Control.PRESET_FULL_RECT)
    vignette.mouse_filter = Control.MOUSE_FILTER_IGNORE
    add_child(vignette)

    focus_glow = FocusGlow.new()
    focus_glow.set_anchors_preset(Control.PRESET_FULL_RECT)
    focus_glow.mouse_filter = Control.MOUSE_FILTER_IGNORE
    add_child(focus_glow)

    # Generous outer margin — console mounted on a wall, not packed to edges
    var root_v := VBoxContainer.new()
    root_v.set_anchors_preset(Control.PRESET_FULL_RECT)
    root_v.offset_left = 56
    root_v.offset_right = -56
    root_v.offset_top = 34
    root_v.offset_bottom = -28
    root_v.add_theme_constant_override("separation", 18)
    add_child(root_v)

    # ── Header crown ─────────────────────────────────────────────────────────
    var header := HeaderCrown.new()
    header.custom_minimum_size = Vector2(0, 184)
    root_v.add_child(header)

    # ── Body — weighted 3-panel ──────────────────────────────────────────────
    body_hbox = HBoxContainer.new()
    body_hbox.size_flags_vertical = Control.SIZE_EXPAND_FILL
    body_hbox.add_theme_constant_override("separation", 22)
    root_v.add_child(body_hbox)

    # Left — slim sidebar, supporting role. State list is scrollable so the
    # panel's intrinsic min height doesn't grow with repo count and push the
    # footer offscreen.
    var left_panel := _make_panel("WORKFLOW STATES")
    left_panel.custom_minimum_size = Vector2(220, 0)
    left_panel.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    left_panel.size_flags_stretch_ratio = 0.12
    body_hbox.add_child(left_panel)
    var left_body: VBoxContainer = left_panel.get_meta("body")
    var state_scroll := ScrollContainer.new()
    state_scroll.size_flags_vertical = Control.SIZE_EXPAND_FILL
    state_scroll.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    state_scroll.horizontal_scroll_mode = ScrollContainer.SCROLL_MODE_DISABLED
    left_body.add_child(state_scroll)
    state_list_box = VBoxContainer.new()
    state_list_box.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    state_list_box.add_theme_constant_override("separation", 4)
    state_scroll.add_child(state_list_box)

    # Center — dominant; routed work concentrates here
    var center_panel := _make_panel("TASK MANIFEST")
    center_panel.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    center_panel.size_flags_stretch_ratio = 0.62
    body_hbox.add_child(center_panel)
    var center_body: VBoxContainer = center_panel.get_meta("body")
    task_scroll = ScrollContainer.new()
    task_scroll.size_flags_vertical = Control.SIZE_EXPAND_FILL
    task_scroll.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    task_scroll.horizontal_scroll_mode = ScrollContainer.SCROLL_MODE_DISABLED
    center_body.add_child(task_scroll)
    task_list_box = VBoxContainer.new()
    task_list_box.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    task_list_box.add_theme_constant_override("separation", 14)
    task_scroll.add_child(task_list_box)

    # Right — denser reference card
    var right_panel := _make_panel("DOSSIER")
    right_panel.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    right_panel.size_flags_stretch_ratio = 0.28
    right_panel.custom_minimum_size = Vector2(440, 0)
    body_hbox.add_child(right_panel)
    _build_details_pane(right_panel.get_meta("body"))

    # ── Drill-in view — sibling of body_hbox, hidden until ENTER ────────────
    drill_in_panel = _make_panel("TASK FILE")
    drill_in_panel.size_flags_vertical = Control.SIZE_EXPAND_FILL
    drill_in_panel.visible = false
    root_v.add_child(drill_in_panel)
    _build_drill_in_pane(drill_in_panel.get_meta("body"))

    # ── Operations log strip ────────────────────────────────────────────────
    # Floor at title + one line. Grows upward (pushing body up) as content
    # streams in; capped by the 6-line buffer in _append_ops_log.
    ops_log_panel = _make_ops_log_panel()
    ops_log_panel.custom_minimum_size = Vector2(0, 56)
    root_v.add_child(ops_log_panel)
    _append_ops_log("Console online. Press S for STATUS, I for ISSUES.", true)

    # ── Footer control rail ──────────────────────────────────────────────────
    var footer := _make_footer_rail()
    footer.custom_minimum_size = Vector2(0, 72)
    root_v.add_child(footer)


# ── Panel factory ────────────────────────────────────────────────────────────

func _make_panel(title: String) -> PanelContainer:
    var panel := PanelContainer.new()
    panel.size_flags_vertical = Control.SIZE_EXPAND_FILL

    var sb := StyleBoxFlat.new()
    sb.bg_color = PALETTE.panel
    sb.border_color = PALETTE.brass_dim
    sb.set_border_width_all(1)
    sb.content_margin_left = 26
    sb.content_margin_right = 26
    sb.content_margin_top = 22
    sb.content_margin_bottom = 22
    sb.shadow_color = PALETTE.shadow
    sb.shadow_size = 6
    sb.shadow_offset = Vector2(0, 4)
    sb.anti_aliasing = true
    panel.add_theme_stylebox_override("panel", sb)

    var inner := VBoxContainer.new()
    inner.size_flags_vertical = Control.SIZE_EXPAND_FILL
    inner.add_theme_constant_override("separation", 16)
    panel.add_child(inner)

    # Section title row — architectural, with tracked letters and double rule
    var head := HBoxContainer.new()
    head.add_theme_constant_override("separation", 14)
    inner.add_child(head)

    var diamond := Diamond.new()
    diamond.custom_minimum_size = Vector2(12, 12)
    diamond.color = PALETTE.brass
    var diamond_wrap := CenterContainer.new()
    diamond_wrap.custom_minimum_size = Vector2(12, 12)
    diamond_wrap.add_child(diamond)
    head.add_child(diamond_wrap)

    var title_lbl := Label.new()
    title_lbl.text = _tracked(title, 1)
    title_lbl.add_theme_color_override("font_color", PALETTE.brass)
    title_lbl.add_theme_font_size_override("font_size", TYPE.section)
    head.add_child(title_lbl)

    var head_spacer := Control.new()
    head_spacer.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    head.add_child(head_spacer)

    # Double rule below section header — primary brass + faint shadow rule
    var rule_stack := VBoxContainer.new()
    rule_stack.add_theme_constant_override("separation", 3)
    inner.add_child(rule_stack)

    var rule_a := RuleLine.new(PALETTE.brass, 1.5)
    rule_a.custom_minimum_size = Vector2(0, 2)
    rule_stack.add_child(rule_a)

    var rule_b := RuleLine.new(PALETTE.rule, 1.0)
    rule_b.custom_minimum_size = Vector2(0, 1)
    rule_stack.add_child(rule_b)

    # Recessed interior — darker than panel face, with a top edge highlight
    # and a bottom edge shadow to read as inset. WellOverlay draws faint
    # corner darkening so the recessed feel doesn't depend on borders alone.
    var well := PanelContainer.new()
    well.size_flags_vertical = Control.SIZE_EXPAND_FILL
    var well_sb := StyleBoxFlat.new()
    well_sb.bg_color = PALETTE.panel_lo
    well_sb.set_border_width_all(0)
    well_sb.border_width_top = 1
    well_sb.border_width_bottom = 1
    well_sb.border_color = PALETTE.edge_hi
    well_sb.content_margin_left = 4
    well_sb.content_margin_right = 4
    well_sb.content_margin_top = 14
    well_sb.content_margin_bottom = 10
    well.add_theme_stylebox_override("panel", well_sb)
    inner.add_child(well)

    var well_overlay := WellOverlay.new()
    well_overlay.set_anchors_preset(Control.PRESET_FULL_RECT)
    well_overlay.mouse_filter = Control.MOUSE_FILTER_IGNORE
    well.add_child(well_overlay)

    var body_box := VBoxContainer.new()
    body_box.size_flags_vertical = Control.SIZE_EXPAND_FILL
    body_box.add_theme_constant_override("separation", 10)
    well.add_child(body_box)

    panel.set_meta("body", body_box)
    panel.set_meta("style", sb)
    return panel


# ── State list ───────────────────────────────────────────────────────────────

func _refresh_state_list() -> void:
    for c in state_list_box.get_children():
        c.queue_free()

    var render_opts := _render_options()
    var navigable := _navigable_options()

    # Resync selected_filter_index against the current navigable list
    selected_filter_index = -1
    for i in navigable.size():
        if String(navigable[i].key) == selected_filter_key:
            selected_filter_index = i
            break
    if selected_filter_index < 0:
        selected_filter_index = 0
        selected_filter_key = String(navigable[0].key) if not navigable.is_empty() else "*|all"

    for opt in render_opts:
        var kind := String(opt.kind)
        if kind == "repo_header":
            state_list_box.add_child(_make_repo_header(String(opt.label)))
        else:
            var is_selected := String(opt.get("key", "")) == selected_filter_key
            state_list_box.add_child(_make_state_row(opt, _option_count(opt), is_selected))


func _make_repo_header(repo: String) -> Control:
    var v := VBoxContainer.new()
    v.add_theme_constant_override("separation", 4)

    var spacer := Control.new()
    spacer.custom_minimum_size = Vector2(0, 6)
    v.add_child(spacer)

    var lbl := Label.new()
    lbl.text = _tracked(repo.to_upper(), 1)
    lbl.add_theme_color_override("font_color", PALETTE.faint)
    lbl.add_theme_font_size_override("font_size", 10)
    v.add_child(lbl)

    var rule := RuleLine.new(PALETTE.rule, 1.0)
    rule.custom_minimum_size = Vector2(0, 1)
    v.add_child(rule)

    return v


func _make_state_row(opt: Dictionary, count: int, is_selected: bool) -> PanelContainer:
    var kind := String(opt.kind)
    var label_text := String(opt.label)
    var dot_color: Color = PALETTE.brass
    if kind == "state":
        var state := String(opt.state)
        if state == "all":
            dot_color = PALETTE.brass
        else:
            dot_color = PALETTE[STATE_COLORS.get(state, "dim")]

    var panel := PanelContainer.new()
    var sb := StyleBoxFlat.new()
    if is_selected:
        sb.bg_color = PALETTE.panel_hi
        sb.border_width_left = 4
        sb.border_color = PALETTE.brass
    else:
        sb.bg_color = Color(0, 0, 0, 0)
        sb.border_width_left = 4
        sb.border_color = Color(0, 0, 0, 0)
    # Indent state rows under a repo group for hierarchy
    var indent := 22 if kind == "state" else 14
    sb.content_margin_left = indent
    sb.content_margin_right = 12
    sb.content_margin_top = 9
    sb.content_margin_bottom = 9
    panel.add_theme_stylebox_override("panel", sb)

    var row := HBoxContainer.new()
    row.add_theme_constant_override("separation", 12)
    panel.add_child(row)

    var dot := Dot.new()
    dot.custom_minimum_size = Vector2(10, 10)
    dot.color = dot_color
    var dot_wrap := CenterContainer.new()
    dot_wrap.custom_minimum_size = Vector2(10, 10)
    dot_wrap.add_child(dot)
    row.add_child(dot_wrap)

    var label := Label.new()
    label.text = label_text
    label.add_theme_color_override(
        "font_color", PALETTE.cream if is_selected else PALETTE.cream_lo)
    label.add_theme_font_size_override("font_size", TYPE.state_row)
    label.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    row.add_child(label)

    var count_lbl := Label.new()
    count_lbl.text = str(count).pad_zeros(2)
    count_lbl.add_theme_color_override(
        "font_color", PALETTE.brass if is_selected else PALETTE.faint)
    count_lbl.add_theme_font_size_override("font_size", TYPE.state_count)
    row.add_child(count_lbl)

    return panel


# ── Task list ────────────────────────────────────────────────────────────────

func _refresh_task_list() -> void:
    for c in task_list_box.get_children():
        c.queue_free()

    if filtered_tasks.is_empty():
        var empty := Label.new()
        empty.text = _tracked("— NO TASKS IN SELECTED STATE —", 1)
        empty.add_theme_color_override("font_color", PALETTE.faint)
        empty.add_theme_font_size_override("font_size", TYPE.task_meta)
        empty.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
        empty.size_flags_vertical = Control.SIZE_EXPAND_FILL
        task_list_box.add_child(empty)
        return

    for i in filtered_tasks.size():
        var t: Dictionary = filtered_tasks[i]
        var row := _make_task_row(t, i == selected_task_index)
        task_list_box.add_child(row)


func _make_task_row(t: Dictionary, is_selected: bool) -> PanelContainer:
    var panel := PanelContainer.new()
    var sb := StyleBoxFlat.new()
    if is_selected:
        sb.bg_color = PALETTE.panel_hi
        sb.border_width_left = 6
        sb.border_color = PALETTE.brass
        sb.border_width_top = 1
        sb.shadow_color = Color(0, 0, 0, 0.7)
        sb.shadow_size = 10
        sb.shadow_offset = Vector2(0, 5)
    else:
        sb.bg_color = Color(1, 1, 1, 0.012)
        sb.border_width_left = 6
        sb.border_color = PALETTE.rule
    sb.content_margin_left = 22
    sb.content_margin_right = 18
    sb.content_margin_top = 18
    sb.content_margin_bottom = 18
    panel.add_theme_stylebox_override("panel", sb)
    panel.set_meta("style", sb)
    panel.set_meta("selected", is_selected)
    panel.set_meta("task_id", String(t.id))

    # Selected card: overlay handles inner-edge brass glow + slow shimmer.
    # Sits behind the content so highlights bleed in from the left ribbon.
    if is_selected:
        var glow := SelectedRowOverlay.new()
        glow.set_anchors_preset(Control.PRESET_FULL_RECT)
        glow.mouse_filter = Control.MOUSE_FILTER_IGNORE
        panel.add_child(glow)
        panel.set_meta("overlay", glow)

    var row := HBoxContainer.new()
    row.add_theme_constant_override("separation", 22)
    panel.add_child(row)

    var dot := Dot.new()
    dot.custom_minimum_size = Vector2(14, 14)
    dot.color = PALETTE[STATE_COLORS.get(t.state, "dim")]
    var dot_wrap := CenterContainer.new()
    dot_wrap.custom_minimum_size = Vector2(14, 14)
    dot_wrap.add_child(dot)
    row.add_child(dot_wrap)

    var id_lbl := Label.new()
    id_lbl.text = String(t.id)
    id_lbl.add_theme_color_override(
        "font_color", PALETTE.brass_hi if is_selected else PALETTE.brass_dim)
    id_lbl.add_theme_font_size_override("font_size", TYPE.task_id)
    id_lbl.custom_minimum_size = Vector2(72, 0)
    row.add_child(id_lbl)

    var center := VBoxContainer.new()
    center.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    center.add_theme_constant_override("separation", 6)
    row.add_child(center)

    var title_lbl := Label.new()
    title_lbl.text = String(t.title)
    title_lbl.add_theme_color_override(
        "font_color", PALETTE.cream if is_selected else PALETTE.cream_lo)
    title_lbl.add_theme_font_size_override(
        "font_size", TYPE.task_title if is_selected else TYPE.task_title_lo)
    title_lbl.clip_text = true
    center.add_child(title_lbl)

    var meta_parts: Array = []
    if String(t.get("issue", "")) != "":
        meta_parts.append(String(t.issue))
    meta_parts.append(STATE_LABELS.get(t.state, String(t.state).to_upper()))
    if String(t.get("repo", "")) != "":
        meta_parts.append(String(t.repo))
    var meta_lbl := Label.new()
    meta_lbl.text = "   ◆   ".join(meta_parts)
    meta_lbl.add_theme_color_override("font_color", PALETTE.faint)
    meta_lbl.add_theme_font_size_override("font_size", TYPE.task_meta)
    center.add_child(meta_lbl)

    var chev := Label.new()
    chev.text = "▶" if is_selected else " "
    chev.add_theme_color_override("font_color", PALETTE.brass_hi)
    chev.add_theme_font_size_override("font_size", 16)
    var chev_wrap := CenterContainer.new()
    chev_wrap.custom_minimum_size = Vector2(18, 0)
    chev_wrap.add_child(chev)
    row.add_child(chev_wrap)

    return panel


# ── Details pane ─────────────────────────────────────────────────────────────

func _build_details_pane(box: VBoxContainer) -> void:
    box.add_theme_constant_override("separation", 18)

    # ID badge + state pill — large, ceremonial
    var head := HBoxContainer.new()
    head.add_theme_constant_override("separation", 22)
    box.add_child(head)

    detail_id_badge = Label.new()
    detail_id_badge.text = "T000"
    detail_id_badge.add_theme_color_override("font_color", PALETTE.brass)
    detail_id_badge.add_theme_font_size_override("font_size", TYPE.detail_id)
    head.add_child(detail_id_badge)

    var sep := VSeparator.new()
    head.add_child(sep)

    detail_state_label = Label.new()
    detail_state_label.text = ""
    detail_state_label.add_theme_color_override("font_color", PALETTE.teal_hi)
    detail_state_label.add_theme_font_size_override("font_size", TYPE.detail_state)
    detail_state_label.size_flags_vertical = Control.SIZE_SHRINK_CENTER
    head.add_child(detail_state_label)

    # Task title — large, breathing
    detail_title = Label.new()
    detail_title.text = ""
    detail_title.add_theme_color_override("font_color", PALETTE.cream)
    detail_title.add_theme_font_size_override("font_size", TYPE.detail_title)
    detail_title.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
    box.add_child(detail_title)

    var rule_stack := VBoxContainer.new()
    rule_stack.add_theme_constant_override("separation", 2)
    box.add_child(rule_stack)
    var rule_a := RuleLine.new(PALETTE.brass_dim, 1.0)
    rule_a.custom_minimum_size = Vector2(0, 1)
    rule_stack.add_child(rule_a)
    var rule_b := RuleLine.new(PALETTE.rule, 1.0)
    rule_b.custom_minimum_size = Vector2(0, 1)
    rule_stack.add_child(rule_b)

    # Meta block — fixed-column key/value pairs
    detail_meta_label = Label.new()
    detail_meta_label.text = ""
    detail_meta_label.add_theme_color_override("font_color", PALETTE.cream_lo)
    detail_meta_label.add_theme_font_size_override("font_size", TYPE.detail_meta)
    detail_meta_label.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
    box.add_child(detail_meta_label)

    # Summary section
    var summary_title := Label.new()
    summary_title.text = _tracked("SUMMARY", 2)
    summary_title.add_theme_color_override("font_color", PALETTE.brass)
    summary_title.add_theme_font_size_override("font_size", 12)
    box.add_child(summary_title)

    var summary_scroll := ScrollContainer.new()
    summary_scroll.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    summary_scroll.size_flags_vertical = Control.SIZE_EXPAND_FILL
    summary_scroll.horizontal_scroll_mode = ScrollContainer.SCROLL_MODE_DISABLED
    box.add_child(summary_scroll)

    detail_summary = RichTextLabel.new()
    detail_summary.fit_content = false
    detail_summary.bbcode_enabled = true
    detail_summary.add_theme_color_override("default_color", PALETTE.cream)
    detail_summary.add_theme_font_size_override("normal_font_size", TYPE.detail_body)
    detail_summary.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    detail_summary.size_flags_vertical = Control.SIZE_EXPAND_FILL
    summary_scroll.add_child(detail_summary)

    # Files-in-scope section
    var files_title := Label.new()
    files_title.text = _tracked("FILES IN SCOPE", 2)
    files_title.add_theme_color_override("font_color", PALETTE.brass)
    files_title.add_theme_font_size_override("font_size", 12)
    box.add_child(files_title)

    detail_files_box = VBoxContainer.new()
    detail_files_box.add_theme_constant_override("separation", 4)
    box.add_child(detail_files_box)

    detail_empty = Label.new()
    detail_empty.text = _tracked("— NO TASK SELECTED —", 1)
    detail_empty.add_theme_color_override("font_color", PALETTE.faint)
    detail_empty.add_theme_font_size_override("font_size", 12)
    detail_empty.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
    detail_empty.size_flags_vertical = Control.SIZE_SHRINK_CENTER
    box.add_child(detail_empty)


func _refresh_details() -> void:
    if filtered_tasks.is_empty():
        detail_id_badge.text = "————"
        detail_title.text = ""
        detail_state_label.text = ""
        detail_meta_label.text = ""
        detail_summary.text = ""
        for c in detail_files_box.get_children():
            c.queue_free()
        detail_empty.visible = true
        return

    detail_empty.visible = false
    var t: Dictionary = filtered_tasks[selected_task_index]
    detail_id_badge.text = String(t.id)
    detail_title.text = String(t.title)
    detail_state_label.text = "◆  " + STATE_LABELS.get(t.state, String(t.state).to_upper())
    detail_state_label.add_theme_color_override(
        "font_color", PALETTE[STATE_COLORS.get(t.state, "dim")])

    var meta_lines: Array = []
    if String(t.get("repo", "")) != "":
        meta_lines.append("REPO       " + String(t.repo))
    if String(t.get("issue", "")) != "":
        meta_lines.append("ISSUE      " + String(t.issue))
    if String(t.get("branch", "")) != "":
        meta_lines.append("BRANCH     " + String(t.branch))
    if String(t.get("created", "")) != "":
        meta_lines.append("CREATED    " + String(t.created))
    detail_meta_label.text = "\n".join(meta_lines)

    var summary := String(t.get("summary", ""))
    if summary == "":
        detail_summary.text = "[i][color=#7A6F5E]No summary recorded.[/color][/i]"
    else:
        detail_summary.text = summary

    for c in detail_files_box.get_children():
        c.queue_free()
    var files: Array = t.get("files_to_change", [])
    if files.is_empty():
        var none := Label.new()
        none.text = "   —"
        none.add_theme_color_override("font_color", PALETTE.faint)
        none.add_theme_font_size_override("font_size", TYPE.files)
        detail_files_box.add_child(none)
    else:
        for f in files:
            var row := HBoxContainer.new()
            row.add_theme_constant_override("separation", 8)
            var bullet := Label.new()
            bullet.text = "▸"
            bullet.add_theme_color_override("font_color", PALETTE.brass_dim)
            bullet.add_theme_font_size_override("font_size", TYPE.files)
            row.add_child(bullet)
            var path := Label.new()
            path.text = String(f)
            path.add_theme_color_override("font_color", PALETTE.cream_lo)
            path.add_theme_font_size_override("font_size", TYPE.files)
            row.add_child(path)
            detail_files_box.add_child(row)


# ── Drill-in pane ────────────────────────────────────────────────────────────

func _build_drill_in_pane(box: VBoxContainer) -> void:
    box.add_theme_constant_override("separation", 20)

    # Top row: huge ID badge + state pill
    var head := HBoxContainer.new()
    head.add_theme_constant_override("separation", 26)
    box.add_child(head)

    drill_id_badge = Label.new()
    drill_id_badge.text = "T000"
    drill_id_badge.add_theme_color_override("font_color", PALETTE.brass)
    drill_id_badge.add_theme_font_size_override("font_size", 48)
    head.add_child(drill_id_badge)

    var sep := VSeparator.new()
    head.add_child(sep)

    drill_state_label = Label.new()
    drill_state_label.text = ""
    drill_state_label.add_theme_color_override("font_color", PALETTE.teal_hi)
    drill_state_label.add_theme_font_size_override("font_size", 18)
    drill_state_label.size_flags_vertical = Control.SIZE_SHRINK_CENTER
    head.add_child(drill_state_label)

    drill_title_label = Label.new()
    drill_title_label.text = ""
    drill_title_label.add_theme_color_override("font_color", PALETTE.cream)
    drill_title_label.add_theme_font_size_override("font_size", 30)
    drill_title_label.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
    box.add_child(drill_title_label)

    var rule_stack := VBoxContainer.new()
    rule_stack.add_theme_constant_override("separation", 2)
    box.add_child(rule_stack)
    var rule_a := RuleLine.new(PALETTE.brass_dim, 1.0)
    rule_a.custom_minimum_size = Vector2(0, 1)
    rule_stack.add_child(rule_a)
    var rule_b := RuleLine.new(PALETTE.rule, 1.0)
    rule_b.custom_minimum_size = Vector2(0, 1)
    rule_stack.add_child(rule_b)

    var meta_title := Label.new()
    meta_title.text = _tracked("METADATA", 2)
    meta_title.add_theme_color_override("font_color", PALETTE.brass)
    meta_title.add_theme_font_size_override("font_size", 12)
    box.add_child(meta_title)

    drill_meta_box = VBoxContainer.new()
    drill_meta_box.add_theme_constant_override("separation", 4)
    box.add_child(drill_meta_box)

    var spacer_notes := Control.new()
    spacer_notes.custom_minimum_size = Vector2(0, 8)
    box.add_child(spacer_notes)

    var notes_title := Label.new()
    notes_title.text = _tracked("NOTES", 2)
    notes_title.add_theme_color_override("font_color", PALETTE.brass)
    notes_title.add_theme_font_size_override("font_size", 12)
    box.add_child(notes_title)

    var notes_rule := RuleLine.new(PALETTE.rule, 1.0)
    notes_rule.custom_minimum_size = Vector2(0, 1)
    box.add_child(notes_rule)

    drill_notes_label = RichTextLabel.new()
    drill_notes_label.fit_content = true
    drill_notes_label.bbcode_enabled = true
    drill_notes_label.scroll_active = false
    drill_notes_label.add_theme_color_override("default_color", PALETTE.cream_lo)
    drill_notes_label.add_theme_font_size_override("normal_font_size", 13)
    drill_notes_label.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    box.add_child(drill_notes_label)

    var spacer1 := Control.new()
    spacer1.custom_minimum_size = Vector2(0, 8)
    box.add_child(spacer1)

    var runs_title := Label.new()
    runs_title.text = _tracked("RUN HISTORY", 2)
    runs_title.add_theme_color_override("font_color", PALETTE.brass)
    runs_title.add_theme_font_size_override("font_size", 12)
    box.add_child(runs_title)

    var runs_rule := RuleLine.new(PALETTE.rule, 1.0)
    runs_rule.custom_minimum_size = Vector2(0, 1)
    box.add_child(runs_rule)

    var runs_scroll := ScrollContainer.new()
    runs_scroll.size_flags_vertical = Control.SIZE_EXPAND_FILL
    runs_scroll.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    runs_scroll.horizontal_scroll_mode = ScrollContainer.SCROLL_MODE_DISABLED
    box.add_child(runs_scroll)

    drill_runs_box = VBoxContainer.new()
    drill_runs_box.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    drill_runs_box.add_theme_constant_override("separation", 6)
    runs_scroll.add_child(drill_runs_box)


func _populate_drill_in() -> void:
    if filtered_tasks.is_empty():
        return
    var t: Dictionary = filtered_tasks[selected_task_index]

    drill_id_badge.text = String(t.id)
    drill_title_label.text = String(t.title)
    drill_state_label.text = "◆  " + STATE_LABELS.get(t.state, String(t.state).to_upper())
    drill_state_label.add_theme_color_override(
        "font_color", PALETTE[STATE_COLORS.get(t.state, "dim")])

    # Meta block — fixed-column key/value rows
    for c in drill_meta_box.get_children():
        c.queue_free()
    var meta := [
        ["REPO",       String(t.get("repo", ""))],
        ["REPO PATH",  String(t.get("repo_path", ""))],
        ["CREATED",    _format_iso(String(t.get("created", "")))],
        ["UPDATED",    _format_iso(String(t.get("updated", "")))],
        ["TASK DIR",   String(t.get("task_dir", "")) if t.get("is_real", false) else "— sample task —"],
    ]
    for row_data in meta:
        var k: String = row_data[0]
        var v: String = row_data[1]
        if v == "":
            continue
        var row := HBoxContainer.new()
        row.add_theme_constant_override("separation", 18)
        var key := Label.new()
        key.text = k
        key.add_theme_color_override("font_color", PALETTE.faint)
        key.add_theme_font_size_override("font_size", 12)
        key.custom_minimum_size = Vector2(110, 0)
        row.add_child(key)
        var val := Label.new()
        val.text = v
        val.add_theme_color_override("font_color", PALETTE.cream_lo)
        val.add_theme_font_size_override("font_size", 14)
        val.size_flags_horizontal = Control.SIZE_EXPAND_FILL
        val.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
        row.add_child(val)
        drill_meta_box.add_child(row)

    # Surface the latest run's failure reason in danger color when present — this
    # is the "why" behind a blocked/failed task that was previously invisible.
    var failure := String(t.get("failure_reason", "")).strip_edges()
    if failure != "":
        var frow := HBoxContainer.new()
        frow.add_theme_constant_override("separation", 18)
        var fkey := Label.new()
        fkey.text = "WHY"
        fkey.add_theme_color_override("font_color", PALETTE.danger)
        fkey.add_theme_font_size_override("font_size", 12)
        fkey.custom_minimum_size = Vector2(110, 0)
        frow.add_child(fkey)
        var fval := Label.new()
        fval.text = failure
        fval.add_theme_color_override("font_color", PALETTE.danger)
        fval.add_theme_font_size_override("font_size", 14)
        fval.size_flags_horizontal = Control.SIZE_EXPAND_FILL
        fval.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
        frow.add_child(fval)
        drill_meta_box.add_child(frow)

    # Notes — read notes.md sidecar if present
    var notes_path := String(t.get("task_dir", "")) + "/notes.md"
    if String(t.get("task_dir", "")) != "" and FileAccess.file_exists(notes_path):
        var nf := FileAccess.open(notes_path, FileAccess.READ)
        if nf != null:
            var notes_text := nf.get_as_text().strip_edges()
            if notes_text == "":
                drill_notes_label.text = "[i][color=#7A6F5E]Empty notes file — press E to edit.[/color][/i]"
            else:
                drill_notes_label.text = notes_text
    else:
        drill_notes_label.text = "[i][color=#7A6F5E]No notes recorded — press E to add.[/color][/i]"

    # Run history
    for c in drill_runs_box.get_children():
        c.queue_free()
    var runs: Array = []
    if t.get("is_real", false) and String(t.get("task_dir", "")) != "":
        runs = TaskLoader.load_runs(String(t.task_dir))
    if runs.is_empty():
        var empty := Label.new()
        empty.text = _tracked("— NO RUNS RECORDED —", 1)
        empty.add_theme_color_override("font_color", PALETTE.faint)
        empty.add_theme_font_size_override("font_size", 12)
        empty.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
        empty.size_flags_horizontal = Control.SIZE_EXPAND_FILL
        drill_runs_box.add_child(empty)
    else:
        for r in runs:
            drill_runs_box.add_child(_make_run_row(r))


func _make_run_row(r: Dictionary) -> Control:
    var panel := PanelContainer.new()
    var sb := StyleBoxFlat.new()
    sb.bg_color = Color(1, 1, 1, 0.012)
    sb.border_width_left = 3
    sb.border_color = PALETTE.brass_dim
    sb.content_margin_left = 14
    sb.content_margin_right = 12
    sb.content_margin_top = 10
    sb.content_margin_bottom = 10
    panel.add_theme_stylebox_override("panel", sb)

    var row := HBoxContainer.new()
    row.add_theme_constant_override("separation", 18)
    panel.add_child(row)

    var dot := Dot.new()
    dot.custom_minimum_size = Vector2(8, 8)
    var state_key: String = String(r.get("state", "dim"))
    dot.color = PALETTE[STATE_COLORS.get(state_key, "dim")]
    var dot_wrap := CenterContainer.new()
    dot_wrap.custom_minimum_size = Vector2(8, 8)
    dot_wrap.add_child(dot)
    row.add_child(dot_wrap)

    var id_lbl := Label.new()
    id_lbl.text = String(r.id)
    id_lbl.add_theme_color_override("font_color", PALETTE.brass_dim)
    id_lbl.add_theme_font_size_override("font_size", 13)
    id_lbl.custom_minimum_size = Vector2(150, 0)
    row.add_child(id_lbl)

    var state_lbl := Label.new()
    state_lbl.text = STATE_LABELS.get(state_key, state_key.to_upper())
    state_lbl.add_theme_color_override("font_color", PALETTE.cream_lo)
    state_lbl.add_theme_font_size_override("font_size", 13)
    state_lbl.custom_minimum_size = Vector2(120, 0)
    row.add_child(state_lbl)

    var updated_lbl := Label.new()
    updated_lbl.text = String(r.get("updated", ""))
    updated_lbl.add_theme_color_override("font_color", PALETTE.faint)
    updated_lbl.add_theme_font_size_override("font_size", 12)
    updated_lbl.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    row.add_child(updated_lbl)

    return panel


func _show_drill_in() -> void:
    if filtered_tasks.is_empty():
        return
    drill_in_visible = true
    _populate_drill_in()
    body_hbox.visible = false
    drill_in_panel.visible = true
    _set_footer_clusters("drill_in")


func _hide_drill_in() -> void:
    drill_in_visible = false
    drill_in_panel.visible = false
    body_hbox.visible = true
    _set_footer_clusters("manifest")


# Footer cluster set varies by view mode — drill-in has a much smaller set
# of operative commands.
func _set_footer_clusters(mode: String) -> void:
    if footer_clusters_row == null:
        return
    for c in footer_clusters_row.get_children():
        c.queue_free()

    var clusters: Array
    if mode == "drill_in":
        clusters = [
            {"label": "ACTION", "commands": [["ESC", "BACK"]]},
            {"label": "RUN",    "commands": [["R", "RUN NOW"]]},
            {"label": "NOTES",  "commands": [["E", "EDIT NOTES"]]},
            {"label": "UTILITY", "commands": [["S", "STATUS"]]},
        ]
    else:
        clusters = [
            {"label": "NAVIGATION", "commands": [["↑ ↓", "SELECT"], ["TAB", "CYCLE PANE"]]},
            {"label": "ACTION",     "commands": [["ENTER", "DRILL IN"], ["ESC", "BACK"]]},
            {"label": "RUN",        "commands": [["R", "RUN NOW"]]},
            {"label": "CREATE",     "commands": [["A", "ADD REPO"]]},
            {"label": "CONFIG",     "commands": [["C", "CONFIG"]]},
            {"label": "UTILITY",    "commands": [["S", "STATUS"]]},
        ]
    for i in clusters.size():
        if i > 0:
            footer_clusters_row.add_child(_make_cluster_separator())
        footer_clusters_row.add_child(_make_cluster(clusters[i]))


# ── Operations log ───────────────────────────────────────────────────────────

func _make_ops_log_panel() -> PanelContainer:
    var p := PanelContainer.new()
    var sb := StyleBoxFlat.new()
    sb.bg_color = PALETTE.tray
    sb.border_color = PALETTE.brass_dim
    sb.set_border_width_all(0)
    sb.border_width_top = 1
    sb.content_margin_left = 26
    sb.content_margin_right = 26
    sb.content_margin_top = 12
    sb.content_margin_bottom = 12
    p.add_theme_stylebox_override("panel", sb)

    var inner := VBoxContainer.new()
    inner.add_theme_constant_override("separation", 6)
    p.add_child(inner)

    var head := HBoxContainer.new()
    head.add_theme_constant_override("separation", 12)
    inner.add_child(head)

    var diamond := Diamond.new()
    diamond.color = PALETTE.brass_dim
    diamond.custom_minimum_size = Vector2(8, 8)
    var dwrap := CenterContainer.new()
    dwrap.custom_minimum_size = Vector2(8, 8)
    dwrap.add_child(diamond)
    head.add_child(dwrap)

    var title := Label.new()
    title.text = _tracked("OPERATIONS LOG", 1)
    title.add_theme_color_override("font_color", PALETTE.brass_dim)
    title.add_theme_font_size_override("font_size", 11)
    head.add_child(title)

    var head_rule := RuleLine.new(PALETTE.rule, 1.0)
    head_rule.custom_minimum_size = Vector2(0, 1)
    inner.add_child(head_rule)

    var ops_scroll := ScrollContainer.new()
    ops_scroll.custom_minimum_size = Vector2(0, 100)
    ops_scroll.horizontal_scroll_mode = ScrollContainer.SCROLL_MODE_DISABLED
    ops_scroll.vertical_scroll_mode = ScrollContainer.SCROLL_MODE_DISABLED
    inner.add_child(ops_scroll)

    ops_log_lines_box = VBoxContainer.new()
    ops_log_lines_box.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    ops_log_lines_box.add_theme_constant_override("separation", 2)
    ops_scroll.add_child(ops_log_lines_box)

    return p


func _append_ops_log(line: String, dim: bool = false) -> void:
    # Split multi-line strings into individual rows
    for sub in line.split("\n"):
        if String(sub).strip_edges() == "":
            continue
        ops_log_buffer.append({"text": String(sub), "dim": dim})
    while ops_log_buffer.size() > MAX_OPS_LOG_LINES:
        ops_log_buffer.pop_front()
    _render_ops_log()


func _render_ops_log() -> void:
    if ops_log_lines_box == null:
        return
    for c in ops_log_lines_box.get_children():
        c.queue_free()
    for entry in ops_log_buffer:
        var lbl := Label.new()
        lbl.text = String(entry.text)
        lbl.add_theme_color_override("font_color",
            PALETTE.faint if entry.get("dim", false) else PALETTE.cream_lo)
        lbl.add_theme_font_size_override("font_size", 11)
        lbl.clip_text = true
        ops_log_lines_box.add_child(lbl)


# Resolve which copland binary to invoke. Called once at startup, cached in copland_bin.
# Order: --copland-bin cmdline arg (from `copland console`) → $COPLAND_BIN env var
# → `which copland` on PATH → empty (caller surfaces error in the ops log).
#
# Duplicated in Config.gd::_resolve_copland_bin — kept inline rather than
# autoload-extracted per Phase 24 CONTEXT.md D-02 (defer Theme/CoplandBin
# autoloads until shared style emerges).
func _resolve_copland_bin() -> String:
    var args := OS.get_cmdline_args()
    for i in args.size():
        if args[i] == "--copland-bin" and i + 1 < args.size():
            var bin: String = args[i + 1]
            if FileAccess.file_exists(bin):
                return bin
    var env := OS.get_environment("COPLAND_BIN")
    if env != "" and FileAccess.file_exists(env):
        return env
    var probe := []
    var rc := OS.execute("which", ["copland"], probe)
    if rc == 0 and not probe.is_empty():
        var path: String = String(probe[0]).strip_edges()
        if path != "" and FileAccess.file_exists(path):
            return path
    return ""


# Async spawn. Returns immediately. The worker thread runs OS.execute()
# synchronously off the main loop; _poll_ops_thread() picks up the result
# in _process and updates the log + status indicator.
func _spawn_copland(label: String, args: PackedStringArray) -> void:
    if ops_thread != null:
        _append_ops_log("Process already running — wait for %s." % ops_thread_label, true)
        return
    if copland_bin == "":
        copland_bin = _resolve_copland_bin()
    if copland_bin == "":
        # Resolution exhausted all four options. Surface a clear error and abort
        # rather than crashing on the first OS.execute call.
        _append_ops_log("error: copland binary not found — pass --copland-bin, set $COPLAND_BIN, or add copland to PATH", true)
        return
    _append_ops_log("$ %s %s" % [copland_bin.get_file(), " ".join(args)], true)
    ops_thread_label = label
    ops_thread = Thread.new()
    ops_thread.start(_run_copland_worker.bind(copland_bin, args))
    _set_status("RUNNING: %s" % label, true)


func _run_copland_worker(bin: String, args: PackedStringArray) -> Dictionary:
    var output: Array = []
    var exit_code: int = OS.execute(bin, args, output, true)
    return {"exit_code": exit_code, "output": output}


func _poll_ops_thread() -> void:
    if ops_thread == null:
        return
    if not ops_thread.is_started():
        return
    if ops_thread.is_alive():
        return
    var result: Dictionary = ops_thread.wait_to_finish()
    var output: Array = result.get("output", [])
    for chunk in output:
        _append_ops_log(String(chunk))
    var exit_code: int = int(result.get("exit_code", -1))
    if exit_code == 0:
        _append_ops_log("── %s complete ──" % ops_thread_label, true)
    else:
        _append_ops_log("── %s failed (exit %d) ──" % [ops_thread_label, exit_code], true)
    ops_thread = null
    ops_thread_label = ""
    _set_status("MONITORING", false)


# "Run now" — kick a full, targeted run for the selected task and return
# immediately. The CLI bypasses the selector (--issue <n>) and writes run-store
# status as it progresses; the manifest poll reflects real state on the next
# tick. We optimistically flip the row to SELECTED so the action registers
# instantly. Launched detached via OS.create_process so a multi-minute run never
# holds the ops slot or blocks app close (unlike the blocking _spawn_copland).
func _action_run_now() -> void:
    if filtered_tasks.is_empty():
        _append_ops_log("Run now — no task selected.", true)
        return

    var t: Dictionary = filtered_tasks[selected_task_index]
    var repo := String(t.get("repo", ""))
    var issue := String(t.get("issue", "")).lstrip("#")
    if repo == "" or issue == "":
        _append_ops_log("Run now — task has no repo/issue (sample data?). Skipped.", true)
        return

    var state := String(t.get("state", ""))
    if state in IN_FLIGHT_STATES:
        _append_ops_log("Run now — #%s is already %s. Not relaunching." % [issue, state.to_upper()], true)
        return

    # Guard the window between launch and the run-store reflecting 'selected',
    # during which the optimistic row state can revert on a poll. Keyed by
    # repo+issue so it can't be defeated by a re-pull.
    var run_key := "%s#%s" % [repo, issue]
    var now_ms := Time.get_ticks_msec()
    if launched_at_ms.has(run_key) and now_ms - int(launched_at_ms[run_key]) < RELAUNCH_GRACE_MS:
        _append_ops_log("Run now — #%s was just launched. Not relaunching." % issue, true)
        return

    if copland_bin == "":
        copland_bin = _resolve_copland_bin()
    if copland_bin == "":
        _append_ops_log("Run now — copland binary not found (pass --copland-bin, set $COPLAND_BIN, or add to PATH).", true)
        return

    var args := PackedStringArray(["run", repo, "--issue", issue])
    var pid := OS.create_process(copland_bin, args)
    if pid <= 0:
        _append_ops_log("Run now — failed to launch copland (pid %d)." % pid, true)
        return

    _append_ops_log("$ %s run %s --issue %s  (background, pid %d)" % [copland_bin.get_file(), repo, issue, pid], true)
    launched_at_ms[run_key] = now_ms

    # Optimistic local flip: 'selected' is the first status the orchestrator
    # writes, so this matches what the next poll will confirm. filtered_tasks
    # shares dict refs with `tasks`, so mutating here sticks until the re-pull.
    t["state"] = "selected"
    _refresh_task_list()
    _refresh_details()
    if drill_in_visible:
        _populate_drill_in()


func _set_status(text: String, busy: bool) -> void:
    if status_label:
        status_label.text = text
        status_label.add_theme_color_override("font_color",
            PALETTE.brass_hi if busy else PALETTE.cream_lo)
    if status_dot and status_dot is Dot:
        (status_dot as Dot).color = PALETTE.brass_hi if busy else PALETTE.merged
        status_dot.queue_redraw()


# ── Modal text input ─────────────────────────────────────────────────────────

func _build_modal() -> void:
    modal_layer = Control.new()
    modal_layer.set_anchors_preset(Control.PRESET_FULL_RECT)
    modal_layer.visible = false
    modal_layer.mouse_filter = Control.MOUSE_FILTER_STOP
    add_child(modal_layer)

    var backdrop := ColorRect.new()
    backdrop.color = Color(0, 0, 0, 0.6)
    backdrop.set_anchors_preset(Control.PRESET_FULL_RECT)
    modal_layer.add_child(backdrop)

    var center := CenterContainer.new()
    center.set_anchors_preset(Control.PRESET_FULL_RECT)
    modal_layer.add_child(center)

    var panel := PanelContainer.new()
    panel.custom_minimum_size = Vector2(680, 0)
    var sb := StyleBoxFlat.new()
    sb.bg_color = PALETTE.panel
    sb.border_color = PALETTE.brass
    sb.set_border_width_all(2)
    sb.content_margin_left = 36
    sb.content_margin_right = 36
    sb.content_margin_top = 30
    sb.content_margin_bottom = 28
    sb.shadow_color = Color(0, 0, 0, 0.7)
    sb.shadow_size = 16
    sb.shadow_offset = Vector2(0, 6)
    panel.add_theme_stylebox_override("panel", sb)
    center.add_child(panel)

    var inner := VBoxContainer.new()
    inner.add_theme_constant_override("separation", 12)
    panel.add_child(inner)

    modal_title_label = Label.new()
    modal_title_label.add_theme_color_override("font_color", PALETTE.brass)
    modal_title_label.add_theme_font_size_override("font_size", 20)
    inner.add_child(modal_title_label)

    var rule := RuleLine.new(PALETTE.brass_dim, 1.0)
    rule.custom_minimum_size = Vector2(0, 1)
    inner.add_child(rule)

    modal_hint_label = Label.new()
    modal_hint_label.add_theme_color_override("font_color", PALETTE.dim)
    modal_hint_label.add_theme_font_size_override("font_size", 13)
    modal_hint_label.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
    inner.add_child(modal_hint_label)

    var spacer := Control.new()
    spacer.custom_minimum_size = Vector2(0, 6)
    inner.add_child(spacer)

    modal_input = LineEdit.new()
    modal_input.add_theme_color_override("font_color", PALETTE.cream)
    modal_input.add_theme_font_size_override("font_size", 16)
    modal_input.custom_minimum_size = Vector2(600, 38)
    var input_sb := StyleBoxFlat.new()
    input_sb.bg_color = PALETTE.panel_lo
    input_sb.border_color = PALETTE.brass_dim
    input_sb.set_border_width_all(1)
    input_sb.content_margin_left = 10
    input_sb.content_margin_right = 10
    input_sb.content_margin_top = 6
    input_sb.content_margin_bottom = 6
    modal_input.add_theme_stylebox_override("normal", input_sb)
    inner.add_child(modal_input)

    var spacer2 := Control.new()
    spacer2.custom_minimum_size = Vector2(0, 4)
    inner.add_child(spacer2)

    var hint_row := Label.new()
    hint_row.text = _tracked("ENTER SUBMIT     ESC CANCEL", 1)
    hint_row.add_theme_color_override("font_color", PALETTE.faint)
    hint_row.add_theme_font_size_override("font_size", 11)
    inner.add_child(hint_row)

    modal_input.text_submitted.connect(_on_modal_submit)


func _show_modal(title: String, hint: String, placeholder: String, default_text: String, cb: Callable) -> void:
    modal_title_label.text = _tracked(title, 1)
    modal_hint_label.text = hint
    modal_input.placeholder_text = placeholder
    modal_input.text = default_text
    modal_callback = cb
    modal_layer.visible = true
    modal_input.grab_focus()
    modal_input.caret_column = default_text.length()


func _hide_modal() -> void:
    modal_layer.visible = false
    modal_callback = Callable()


func _on_modal_submit(text: String) -> void:
    var cb := modal_callback
    _hide_modal()
    if cb.is_valid():
        cb.call(text)


func _modal_visible() -> bool:
    return modal_layer != null and modal_layer.visible


# ── Multi-line text modal (notes editor) ─────────────────────────────────────

func _build_text_modal() -> void:
    text_modal_layer = Control.new()
    text_modal_layer.set_anchors_preset(Control.PRESET_FULL_RECT)
    text_modal_layer.visible = false
    text_modal_layer.mouse_filter = Control.MOUSE_FILTER_STOP
    add_child(text_modal_layer)

    var backdrop := ColorRect.new()
    backdrop.color = Color(0, 0, 0, 0.6)
    backdrop.set_anchors_preset(Control.PRESET_FULL_RECT)
    text_modal_layer.add_child(backdrop)

    var center := CenterContainer.new()
    center.set_anchors_preset(Control.PRESET_FULL_RECT)
    text_modal_layer.add_child(center)

    var panel := PanelContainer.new()
    panel.custom_minimum_size = Vector2(960, 580)
    var sb := StyleBoxFlat.new()
    sb.bg_color = PALETTE.panel
    sb.border_color = PALETTE.brass
    sb.set_border_width_all(2)
    sb.content_margin_left = 36
    sb.content_margin_right = 36
    sb.content_margin_top = 30
    sb.content_margin_bottom = 28
    sb.shadow_color = Color(0, 0, 0, 0.7)
    sb.shadow_size = 18
    sb.shadow_offset = Vector2(0, 7)
    panel.add_theme_stylebox_override("panel", sb)
    center.add_child(panel)

    var inner := VBoxContainer.new()
    inner.add_theme_constant_override("separation", 12)
    panel.add_child(inner)

    text_modal_title_label = Label.new()
    text_modal_title_label.add_theme_color_override("font_color", PALETTE.brass)
    text_modal_title_label.add_theme_font_size_override("font_size", 20)
    inner.add_child(text_modal_title_label)

    var rule := RuleLine.new(PALETTE.brass_dim, 1.0)
    rule.custom_minimum_size = Vector2(0, 1)
    inner.add_child(rule)

    text_modal_hint_label = Label.new()
    text_modal_hint_label.add_theme_color_override("font_color", PALETTE.dim)
    text_modal_hint_label.add_theme_font_size_override("font_size", 13)
    text_modal_hint_label.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
    inner.add_child(text_modal_hint_label)

    var spacer := Control.new()
    spacer.custom_minimum_size = Vector2(0, 6)
    inner.add_child(spacer)

    text_modal_input = TextEdit.new()
    text_modal_input.add_theme_color_override("font_color", PALETTE.cream)
    text_modal_input.add_theme_font_size_override("font_size", 14)
    text_modal_input.custom_minimum_size = Vector2(880, 420)
    text_modal_input.wrap_mode = TextEdit.LINE_WRAPPING_BOUNDARY
    var ts := StyleBoxFlat.new()
    ts.bg_color = PALETTE.panel_lo
    ts.border_color = PALETTE.brass_dim
    ts.set_border_width_all(1)
    ts.content_margin_left = 12
    ts.content_margin_right = 12
    ts.content_margin_top = 10
    ts.content_margin_bottom = 10
    text_modal_input.add_theme_stylebox_override("normal", ts)
    text_modal_input.add_theme_stylebox_override("focus", ts)
    inner.add_child(text_modal_input)

    var spacer2 := Control.new()
    spacer2.custom_minimum_size = Vector2(0, 4)
    inner.add_child(spacer2)

    var hint_row := Label.new()
    hint_row.text = _tracked("⌘ + ENTER SAVE     ESC CANCEL", 1)
    hint_row.add_theme_color_override("font_color", PALETTE.faint)
    hint_row.add_theme_font_size_override("font_size", 11)
    inner.add_child(hint_row)


func _show_text_modal(title: String, hint: String, default_text: String, cb: Callable) -> void:
    text_modal_title_label.text = _tracked(title, 1)
    text_modal_hint_label.text = hint
    text_modal_input.text = default_text
    text_modal_callback = cb
    text_modal_layer.visible = true
    text_modal_input.grab_focus()
    # Move caret to end
    var lines := default_text.split("\n")
    text_modal_input.set_caret_line(max(0, lines.size() - 1))
    var last_line: String = lines[lines.size() - 1] if lines.size() > 0 else ""
    text_modal_input.set_caret_column(last_line.length())


func _hide_text_modal() -> void:
    text_modal_layer.visible = false
    text_modal_callback = Callable()


func _submit_text_modal() -> void:
    var cb := text_modal_callback
    var text: String = text_modal_input.text
    _hide_text_modal()
    if cb.is_valid():
        cb.call(text)


func _text_modal_visible() -> bool:
    return text_modal_layer != null and text_modal_layer.visible


# ── Repo registry (console-managed) ──────────────────────────────────────────

func _console_dir() -> String:
    var home := OS.get_environment("HOME")
    return home + "/.copland" if home != "" else ""


func _repos_file_path() -> String:
    var base := _console_dir()
    return "" if base == "" else "%s/%s" % [base, CONSOLE_REPOS_FILE]


func _load_registered_repos() -> void:
    registered_repos.clear()
    var path := _repos_file_path()
    if path == "" or not FileAccess.file_exists(path):
        return
    var f := FileAccess.open(path, FileAccess.READ)
    if f == null:
        return
    while not f.eof_reached():
        var line: String = f.get_line().strip_edges()
        if line != "" and not line.begins_with("#"):
            registered_repos.append(line)


func _save_registered_repos() -> void:
    var path := _repos_file_path()
    if path == "":
        _append_ops_log("Cannot write repos file — no HOME.", true)
        return
    var f := FileAccess.open(path, FileAccess.WRITE)
    if f == null:
        _append_ops_log("Failed to open %s for write." % path, true)
        return
    for p in registered_repos:
        f.store_line(String(p))


# Returns the absolute filesystem path for the currently-filtered repo, or
# the first registered repo, or "" if none are known.
func _current_repo_path() -> String:
    var parts := selected_filter_key.split("|")
    var slug: String = parts[0] if parts.size() > 0 else "*"
    if slug != "*":
        # Find the task_dir for any task in this repo to derive repo_path
        for t in tasks:
            if String(t.get("repo", "")) == slug:
                var rp := String(t.get("repo_path", ""))
                if rp != "":
                    return rp
    if not registered_repos.is_empty():
        return String(registered_repos[0])
    # Last resort: look up any task's repo_path
    for t in tasks:
        var rp2 := String(t.get("repo_path", ""))
        if rp2 != "":
            return rp2
    return ""


# ── Operations: new task, add repo, edit notes ───────────────────────────────

# _action_new_task / _on_new_task_submit removed: they shelled out to
# `copland task create`, which is not a registered PHP CLI command — the
# call would always fail. Task creation is intentionally CLI-only via
# `copland run` against a GitHub issue. See Copilot review #4 on PR #4.


func _display_repo_path() -> String:
    var p := _current_repo_path()
    return p if p != "" else "(current working directory)"


func _action_add_repo() -> void:
    _show_modal(
        "REGISTER REPOSITORY",
        "Absolute path to the repository on disk. The repo will appear in the sidebar; tasks created against it will use this path as --repo.",
        "/Users/you/projects/some-repo",
        "",
        _on_add_repo_submit)


func _on_add_repo_submit(text: String) -> void:
    var path := text.strip_edges()
    if path == "":
        _append_ops_log("Add repo cancelled — empty path.", true)
        return
    # De-dup
    for existing in registered_repos:
        if String(existing) == path:
            _append_ops_log("Repo already registered: %s" % path, true)
            return
    registered_repos.append(path)
    _save_registered_repos()
    _append_ops_log("Repo registered: %s" % path, true)
    # Trigger immediate refresh so the new repo shows up
    _on_refresh_tick()


func _action_edit_notes() -> void:
    if not drill_in_visible:
        return
    if filtered_tasks.is_empty():
        return
    var t: Dictionary = filtered_tasks[selected_task_index]
    var task_dir := String(t.get("task_dir", ""))
    if task_dir == "":
        _append_ops_log("Cannot edit notes — sample task has no on-disk file.", true)
        return
    var notes_path := task_dir + "/notes.md"
    var content := ""
    if FileAccess.file_exists(notes_path):
        var fr := FileAccess.open(notes_path, FileAccess.READ)
        if fr != null:
            content = fr.get_as_text()
    if content == "":
        content = "# Notes — %s\n\n" % String(t.get("id", ""))
    _show_text_modal(
        "EDIT NOTES — " + String(t.get("id", "")),
        "Free-form context for this task. Saved to notes.md alongside task.md/status.md.",
        content,
        _on_notes_submit)


func _on_notes_submit(text: String) -> void:
    if filtered_tasks.is_empty():
        return
    var t: Dictionary = filtered_tasks[selected_task_index]
    var notes_path := String(t.get("task_dir", "")) + "/notes.md"
    if notes_path == "/notes.md":
        _append_ops_log("Cannot save notes — task has no on-disk file.", true)
        return
    var f := FileAccess.open(notes_path, FileAccess.WRITE)
    if f == null:
        _append_ops_log("Failed to write notes: %s" % notes_path, true)
        return
    f.store_string(text)
    _append_ops_log("Notes saved: %s" % notes_path.get_file(), true)
    if drill_in_visible:
        _populate_drill_in()


# ── Footer rail ──────────────────────────────────────────────────────────────

func _make_footer_rail() -> PanelContainer:
    var tray := PanelContainer.new()

    var sb := StyleBoxFlat.new()
    sb.bg_color = PALETTE.tray
    sb.set_border_width_all(0)
    sb.border_width_top = 1
    sb.border_color = PALETTE.brass_dim
    sb.content_margin_left = 28
    sb.content_margin_right = 28
    sb.content_margin_top = 18
    sb.content_margin_bottom = 18
    sb.shadow_color = Color(0, 0, 0, 0.4)
    sb.shadow_size = 6
    sb.shadow_offset = Vector2(0, -3)
    tray.add_theme_stylebox_override("panel", sb)

    # FooterRail draws the illuminated top hairline + cluster separators.
    # Layered below the command row.
    var rail := FooterRail.new()
    rail.set_anchors_preset(Control.PRESET_FULL_RECT)
    rail.mouse_filter = Control.MOUSE_FILTER_IGNORE
    tray.add_child(rail)

    var row := HBoxContainer.new()
    row.add_theme_constant_override("separation", 22)
    tray.add_child(row)

    # Cluster region — rebuilt when view mode changes (manifest vs drill-in).
    footer_clusters_row = HBoxContainer.new()
    footer_clusters_row.add_theme_constant_override("separation", 22)
    row.add_child(footer_clusters_row)

    var spacer := Control.new()
    spacer.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    row.add_child(spacer)

    # Right side — mounted status + clock module. Constant across modes.
    row.add_child(_make_cluster_separator())
    row.add_child(_make_status_module())

    _set_footer_clusters("manifest")

    return tray


func _make_status_module() -> Control:
    var v := VBoxContainer.new()
    v.add_theme_constant_override("separation", 6)
    v.size_flags_vertical = Control.SIZE_SHRINK_CENTER
    v.size_flags_horizontal = Control.SIZE_SHRINK_END

    var tag := Label.new()
    tag.text = _tracked("STATUS", 1)
    tag.add_theme_color_override("font_color", PALETTE.faint)
    tag.add_theme_font_size_override("font_size", 10)
    tag.horizontal_alignment = HORIZONTAL_ALIGNMENT_RIGHT
    v.add_child(tag)

    var line := HBoxContainer.new()
    line.add_theme_constant_override("separation", 10)
    line.alignment = BoxContainer.ALIGNMENT_END
    v.add_child(line)

    var indicator := Dot.new()
    indicator.color = PALETTE.merged
    indicator.custom_minimum_size = Vector2(9, 9)
    var indicator_wrap := CenterContainer.new()
    indicator_wrap.custom_minimum_size = Vector2(9, 9)
    indicator_wrap.add_child(indicator)
    line.add_child(indicator_wrap)
    status_dot = indicator

    status_label = Label.new()
    status_label.text = "MONITORING"
    status_label.add_theme_color_override("font_color", PALETTE.cream_lo)
    status_label.add_theme_font_size_override("font_size", TYPE.footer_key)
    line.add_child(status_label)

    var sep := Label.new()
    sep.text = "·"
    sep.add_theme_color_override("font_color", PALETTE.faint)
    sep.add_theme_font_size_override("font_size", TYPE.footer_key)
    line.add_child(sep)

    footer_clock = Label.new()
    footer_clock.text = ""
    footer_clock.add_theme_color_override("font_color", PALETTE.brass_dim)
    footer_clock.add_theme_font_size_override("font_size", TYPE.footer_brand)
    line.add_child(footer_clock)

    return v


func _make_cluster(cluster: Dictionary) -> Control:
    var v := VBoxContainer.new()
    v.add_theme_constant_override("separation", 6)
    v.size_flags_vertical = Control.SIZE_SHRINK_CENTER

    var tag := Label.new()
    tag.text = _tracked(cluster.label, 1)
    tag.add_theme_color_override("font_color", PALETTE.faint)
    tag.add_theme_font_size_override("font_size", 10)
    v.add_child(tag)

    var row := HBoxContainer.new()
    row.add_theme_constant_override("separation", 18)
    for cmd in cluster.commands:
        row.add_child(_make_command_module(cmd[0], cmd[1]))
    v.add_child(row)

    return v


func _make_cluster_separator() -> Control:
    var v := VBoxContainer.new()
    v.add_theme_constant_override("separation", 4)
    v.size_flags_vertical = Control.SIZE_SHRINK_CENTER

    var top_rule := VerticalRule.new()
    top_rule.color = PALETTE.brass_dim
    top_rule.custom_minimum_size = Vector2(1, 14)
    var top_wrap := CenterContainer.new()
    top_wrap.add_child(top_rule)
    v.add_child(top_wrap)

    var chip := Diamond.new()
    chip.color = PALETTE.brass_dim
    chip.custom_minimum_size = Vector2(6, 6)
    var chip_wrap := CenterContainer.new()
    chip_wrap.custom_minimum_size = Vector2(6, 6)
    chip_wrap.add_child(chip)
    v.add_child(chip_wrap)

    var bot_rule := VerticalRule.new()
    bot_rule.color = PALETTE.brass_dim
    bot_rule.custom_minimum_size = Vector2(1, 14)
    var bot_wrap := CenterContainer.new()
    bot_wrap.add_child(bot_rule)
    v.add_child(bot_wrap)

    return v


func _make_command_module(key: String, label: String) -> Control:
    var module := HBoxContainer.new()
    module.add_theme_constant_override("separation", 10)

    var key_panel := PanelContainer.new()
    var ksb := StyleBoxFlat.new()
    ksb.bg_color = PALETTE.panel
    ksb.border_color = PALETTE.brass_dim
    ksb.set_border_width_all(1)
    ksb.content_margin_left = 10
    ksb.content_margin_right = 10
    ksb.content_margin_top = 4
    ksb.content_margin_bottom = 4
    key_panel.add_theme_stylebox_override("panel", ksb)
    var key_lbl := Label.new()
    key_lbl.text = key
    key_lbl.add_theme_color_override("font_color", PALETTE.brass_hi)
    key_lbl.add_theme_font_size_override("font_size", TYPE.footer_key)
    key_panel.add_child(key_lbl)
    var key_wrap := CenterContainer.new()
    key_wrap.add_child(key_panel)
    module.add_child(key_wrap)

    var label_lbl := Label.new()
    label_lbl.text = label
    label_lbl.add_theme_color_override("font_color", PALETTE.cream_lo)
    label_lbl.add_theme_font_size_override("font_size", TYPE.footer_label)
    label_lbl.size_flags_vertical = Control.SIZE_SHRINK_CENTER
    module.add_child(label_lbl)

    return module




# ─────────────────────────────────────────────────────────────────────────────
# Focus + motion
# ─────────────────────────────────────────────────────────────────────────────

func _refresh_focus_styles() -> void:
    if body_hbox == null:
        return
    var panels: Array = []
    for c in body_hbox.get_children():
        if c is PanelContainer:
            panels.append(c)
    if panels.size() < 3:
        return
    var active_idx := -1
    if focus_region == 0:
        active_idx = 0
    elif focus_region == 1:
        active_idx = 1
    for i in panels.size():
        var p: PanelContainer = panels[i]
        var sb: StyleBoxFlat = p.get_meta("style")
        if i == active_idx:
            sb.border_color = PALETTE.brass
            sb.set_border_width_all(2)
            sb.shadow_color = Color(PALETTE.brass.r, PALETTE.brass.g, PALETTE.brass.b, 0.18)
            sb.shadow_size = 10
        else:
            sb.border_color = PALETTE.brass_dim
            sb.set_border_width_all(1)
            sb.shadow_color = PALETTE.shadow
            sb.shadow_size = 6


# Slow brass pulse on the selected task row — relay-system rhythm
func _pulse_selected_row() -> void:
    if focus_region != 1:
        return
    if selected_task_index >= task_list_box.get_child_count():
        return
    var row := task_list_box.get_child(selected_task_index)
    if not (row is PanelContainer):
        return
    if not row.get_meta("selected", false):
        return
    var sb: StyleBoxFlat = row.get_meta("style")
    # 0..1..0 over 2.4s
    var phase: float = abs(sin(pulse_t * PI / 2.4))
    var c: Color = PALETTE.brass
    var c_hi: Color = PALETTE.brass_hi
    sb.border_color = c.lerp(c_hi, phase)
    var alpha: float = 0.10 + 0.10 * phase
    sb.shadow_color = Color(c_hi.r, c_hi.g, c_hi.b, alpha)
    sb.shadow_size = int(3 + 4 * phase)


# =============================================================================
# Custom-drawn ornaments
# =============================================================================

class HeaderCrown extends Control:
    var t: float = 0.0

    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE
        set_process(true)

    func _process(dt: float) -> void:
        t = fmod(t + dt, 9.0)
        queue_redraw()

    func _draw() -> void:
        var w := size.x
        var h := size.y
        var brass := Color(0.812, 0.682, 0.388)
        var brass_hi := Color(0.961, 0.835, 0.510)
        var brass_dim := Color(0.467, 0.388, 0.220)
        var rule := Color(0.220, 0.196, 0.157)
        var tray_c := Color(0.110, 0.100, 0.086)
        var faint := Color(0.341, 0.314, 0.267)
        var cream_lo := Color(0.788, 0.741, 0.635)
        var shadow := Color(0, 0, 0, 0.55)

        # Header tray surface — slightly darker than the floor
        draw_rect(Rect2(0, 0, w, h), tray_c, true)
        # Subtle bottom shadow into floor
        for i in range(6):
            var a: float = 0.07 * (1.0 - float(i) / 6.0)
            draw_rect(Rect2(0, h + i, w, 1), Color(0, 0, 0, a), true)

        # Top double rule frame
        draw_line(Vector2(0, 1), Vector2(w, 1), brass_dim, 1.0)
        draw_line(Vector2(0, 4), Vector2(w, 4), rule, 1.0)

        # Bottom heavy rule + thin shadow rule
        draw_line(Vector2(0, h - 4), Vector2(w, h - 4), brass, 2.0)
        draw_line(Vector2(0, h - 1), Vector2(w, h - 1), brass_dim, 1.0)

        var font := ThemeDB.fallback_font
        var title_size := 92
        var sub_size := 14
        var brand_size := 11
        var pad := 60.0

        # Title — extreme tracking gives it crown weight without a custom font
        var title := "C      O      P      L      A      N      D"
        var t_dims := font.get_string_size(title, HORIZONTAL_ALIGNMENT_CENTER, -1, title_size)

        var cx: float = w / 2.0
        var title_y: float = h * 0.60
        var sub_y: float = title_y + 22

        # Soft shadow under title — light, restrained
        draw_string(font, Vector2(cx - t_dims.x / 2.0 + 1, title_y + 2),
            title, HORIZONTAL_ALIGNMENT_CENTER, -1, title_size, shadow)
        # Title proper
        draw_string(font, Vector2(cx - t_dims.x / 2.0, title_y),
            title, HORIZONTAL_ALIGNMENT_CENTER, -1, title_size, brass)

        # System designation — architectural framing mounted under the title.
        # Symmetric rules extend from the subtitle to consistent anchors at
        # 14% in from each edge, with terminator chips at the outer ends and
        # quarter-tick marks at the rule midpoints. No chevron stacks.
        var subtitle := "C E N T R A L    O R C H E S T R A T I O N    C O N S O L E"
        var s_dims := font.get_string_size(subtitle, HORIZONTAL_ALIGNMENT_CENTER, -1, sub_size)
        var sub_x: float = cx - s_dims.x / 2.0
        # Anchor the rules to the exact same x as the panel edges below
        # (HeaderCrown sits inside the same VBox as the body, so x=0 here
        # equals the left edge of the first panel and x=w equals the right
        # edge of the last panel).
        var rule_pad := 24.0
        var asc: float = font.get_ascent(sub_size)
        var desc: float = font.get_descent(sub_size)
        var rule_y: float = sub_y - (asc - desc) / 2.0
        var rule_left_x0: float = 0.0
        var rule_left_x1: float = sub_x - rule_pad
        var rule_right_x0: float = sub_x + s_dims.x + rule_pad
        var rule_right_x1: float = w

        draw_line(Vector2(rule_left_x0, rule_y),
            Vector2(rule_left_x1, rule_y), brass_dim, 1.0)
        draw_line(Vector2(rule_right_x0, rule_y),
            Vector2(rule_right_x1, rule_y), brass_dim, 1.0)
        # Terminator chips — small brass blocks aligned with the panel edges
        draw_rect(Rect2(rule_left_x0, rule_y - 3, 3, 6), brass, true)
        draw_rect(Rect2(rule_right_x1 - 3, rule_y - 3, 3, 6), brass, true)
        # Quarter-tick marks — small notches up & down at rule midpoints
        var lmid: float = (rule_left_x0 + rule_left_x1) / 2.0
        var rmid: float = (rule_right_x0 + rule_right_x1) / 2.0
        for x in [lmid, rmid]:
            draw_line(Vector2(x, rule_y - 4), Vector2(x, rule_y - 1), brass_dim, 1.0)
            draw_line(Vector2(x, rule_y + 1), Vector2(x, rule_y + 4), brass_dim, 1.0)

        draw_string(font, Vector2(sub_x, sub_y),
            subtitle, HORIZONTAL_ALIGNMENT_CENTER, -1, sub_size, cream_lo)

        # Slow brass sweep along the bottom rule — relay tracker
        var phase: float = t / 9.0
        var sweep_x: float = -120.0 + (w + 240.0) * phase
        var sweep_alpha: float = sin(phase * PI) * 0.45
        var sweep_color := Color(brass_hi.r, brass_hi.g, brass_hi.b, sweep_alpha)
        var sweep_y: float = h - 4
        draw_line(Vector2(sweep_x - 90, sweep_y), Vector2(sweep_x + 90, sweep_y), sweep_color, 2.0)

        # Tag rows — engraved plate labels at the upper corners
        var left_tag := "⌖   DIRECTORATE  I     CONSOLE  1"
        draw_string(font, Vector2(pad - 14, 26),
            left_tag, HORIZONTAL_ALIGNMENT_LEFT, -1, brand_size, brass_dim)
        var right_tag := "RUN  MANIFEST     SER.  III     0.4"
        var r_dims := font.get_string_size(right_tag, HORIZONTAL_ALIGNMENT_RIGHT, -1, brand_size)
        draw_string(font, Vector2(w - pad - r_dims.x + 14, 26),
            right_tag, HORIZONTAL_ALIGNMENT_RIGHT, -1, brand_size, brass_dim)

        # Quiet faint mark under the brand tags
        draw_line(Vector2(pad - 14, 36), Vector2(pad - 14 + 220, 36), faint, 1.0)
        draw_line(Vector2(w - pad - 220 + 14, 36), Vector2(w - pad + 14, 36), faint, 1.0)


    func _draw_chevron_stack(anchor: Vector2, length: float, brass: Color, brass_dim: Color, mirrored: bool) -> void:
        var dir: float = -1.0 if mirrored else 1.0
        var bar_h := 2.0
        var gap := 7.0
        var bars := [length, length * 0.72, length * 0.45]
        for i in bars.size():
            var bl: float = bars[i]
            var y: float = anchor.y + i * (bar_h + gap)
            var x0: float = anchor.x
            var x1: float = anchor.x + bl * dir
            draw_line(Vector2(x0, y), Vector2(x1, y),
                brass if i == 0 else brass_dim, bar_h)
        # End cap chevron — proportional to first bar
        var tip_x: float = anchor.x + length * dir
        var cy: float = anchor.y
        var s := 10.0
        var pts := PackedVector2Array([
            Vector2(tip_x, cy - s),
            Vector2(tip_x + s * dir, cy),
            Vector2(tip_x, cy + s),
        ])
        draw_polyline(pts, brass, 1.5)


class RuleLine extends Control:
    var color: Color
    var thickness: float

    func _init(c: Color = Color.WHITE, th: float = 1.0) -> void:
        color = c
        thickness = th
        mouse_filter = Control.MOUSE_FILTER_IGNORE

    func _draw() -> void:
        var y: float = size.y / 2.0
        draw_line(Vector2(0, y), Vector2(size.x, y), color, thickness)


class VerticalRule extends Control:
    var color: Color = Color.WHITE

    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE

    func _draw() -> void:
        var x: float = size.x / 2.0
        draw_line(Vector2(x, 0), Vector2(x, size.y), color, 1.0)


class Diamond extends Control:
    var color: Color = Color.WHITE

    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE
        queue_redraw()

    func _draw() -> void:
        var w := size.x
        var h := size.y
        var pts := PackedVector2Array([
            Vector2(w / 2.0, 0),
            Vector2(w, h / 2.0),
            Vector2(w / 2.0, h),
            Vector2(0, h / 2.0),
        ])
        draw_colored_polygon(pts, color)


class Dot extends Control:
    var color: Color = Color.WHITE

    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE
        queue_redraw()

    func _draw() -> void:
        var r: float = min(size.x, size.y) / 2.0
        var c: Vector2 = Vector2(size.x / 2.0, size.y / 2.0)
        draw_circle(c, r + 2.0, Color(color.r, color.g, color.b, 0.18))
        draw_circle(c, r, color)


# Darkens the four corners of the canvas. Concentric translucent rings
# fade from corner inward — environmental, not a visible gradient.
class CornerVignette extends Control:
    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE

    func _draw() -> void:
        var w := size.x
        var h := size.y
        var corners := [
            Vector2(0, 0),
            Vector2(w, 0),
            Vector2(0, h),
            Vector2(w, h),
        ]
        for corner in corners:
            for i in range(10):
                var radius: float = 280.0 - float(i) * 22.0
                var alpha: float = 0.028 * (1.0 - float(i) / 10.0)
                draw_circle(corner, radius, Color(0, 0, 0, alpha))


# Soft brass-tinted radial halo drifting behind the focused panel.
# Provides directional environmental lighting without an obvious glow.
class FocusGlow extends Control:
    var target: Vector2 = Vector2.ZERO
    var intensity: float = 0.22

    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE

    func _draw() -> void:
        var base := Color(0.812, 0.682, 0.388)
        for i in range(14):
            var radius: float = 360.0 - float(i) * 22.0
            var k: float = 1.0 - float(i) / 14.0
            var alpha: float = 0.022 * intensity * pow(k, 1.6)
            draw_circle(target, radius, Color(base.r, base.g, base.b, alpha))


# Overlay drawn behind the row content on the currently-selected task.
# Two layers — both static. No drifting elements.
#  1. Inner left glow — brass bleed from the ribbon into the card
#  2. Bevel — top inner highlight, bottom inner shadow
class SelectedRowOverlay extends Control:
    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE

    func _draw() -> void:
        var w := size.x
        var h := size.y
        var brass := Color(0.812, 0.682, 0.388)
        var brass_hi := Color(0.961, 0.835, 0.510)

        # 1. Inner left glow — vertical brass band fading rightward
        var glow_w: float = min(w * 0.42, 280.0)
        var steps := 20
        for i in range(steps):
            var k: float = 1.0 - float(i) / float(steps)
            var x_step: float = glow_w * float(i) / float(steps)
            var x1: float = glow_w * float(i + 1) / float(steps)
            var alpha: float = 0.075 * pow(k, 1.6)
            draw_rect(Rect2(x_step, 0, x1 - x_step, h),
                Color(brass.r, brass.g, brass.b, alpha), true)

        # 2. Bevel — top inner highlight, bottom inner shadow
        draw_line(Vector2(0, 0.5), Vector2(w, 0.5),
            Color(brass_hi.r, brass_hi.g, brass_hi.b, 0.28), 1.0)
        draw_line(Vector2(0, h - 0.5), Vector2(w, h - 0.5),
            Color(0, 0, 0, 0.55), 1.0)


# Footer tray illumination — single hairline glow along the inner top edge,
# echoing a brass rail mounted inside the console face. Pulses very slowly.
class FooterRail extends Control:
    var t: float = 0.0

    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE
        set_process(true)

    func _process(dt: float) -> void:
        t = fmod(t + dt, 7.0)
        queue_redraw()

    func _draw() -> void:
        var w := size.x
        var brass_hi := Color(0.961, 0.835, 0.510)
        var brass := Color(0.812, 0.682, 0.388)
        # Slow breath on the rail alpha — relay illumination
        var phase: float = (sin(t * TAU / 7.0) + 1.0) * 0.5
        var rail_alpha: float = 0.10 + 0.10 * phase
        draw_line(Vector2(0, 2.5), Vector2(w, 2.5),
            Color(brass_hi.r, brass_hi.g, brass_hi.b, rail_alpha), 1.0)
        draw_line(Vector2(0, 4), Vector2(w, 4),
            Color(brass.r, brass.g, brass.b, 0.08), 1.0)


# Subtle corner darkening inside each panel's recessed well, reading as
# inset machining shadow. Drawn behind content; alpha kept very low.
class WellOverlay extends Control:
    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE

    func _draw() -> void:
        var w := size.x
        var h := size.y
        var corners := [
            Vector2(0, 0),
            Vector2(w, 0),
            Vector2(0, h),
            Vector2(w, h),
        ]
        for corner in corners:
            for i in range(6):
                var radius: float = 100.0 - float(i) * 14.0
                var alpha: float = 0.035 * (1.0 - float(i) / 6.0)
                draw_circle(corner, radius, Color(0, 0, 0, alpha))


# Transient brass flash drawn over a task row when its state transitions.
# Eases out over 1.5s and self-deletes. Self-contained — no external state.
class FlashOverlay extends Control:
    var t: float = 0.0
    const DURATION := 1.5

    func _ready() -> void:
        mouse_filter = Control.MOUSE_FILTER_IGNORE
        set_process(true)

    func _process(dt: float) -> void:
        t += dt
        if t >= DURATION:
            queue_free()
            return
        queue_redraw()

    func _draw() -> void:
        var w := size.x
        var h := size.y
        var brass_hi := Color(0.961, 0.835, 0.510)
        var k: float = 1.0 - (t / DURATION)
        var eased: float = pow(k, 2.2)
        # Card-wide brass tint
        draw_rect(Rect2(0, 0, w, h),
            Color(brass_hi.r, brass_hi.g, brass_hi.b, 0.16 * eased), true)
        # Strong left ribbon override
        draw_rect(Rect2(0, 0, 6, h),
            Color(brass_hi.r, brass_hi.g, brass_hi.b, 0.85 * eased), true)
        # Top/bottom highlight to read as illumination, not selection
        draw_line(Vector2(0, 0.5), Vector2(w, 0.5),
            Color(brass_hi.r, brass_hi.g, brass_hi.b, 0.55 * eased), 1.0)

extends Control

# Copland Config hub scene.
#
# Four tabs (Repos / Per-Repo / Asana / Defaults). Only the Repos sub-view
# is live in Phase 24 — the other three render a "Coming in Phase 25/26"
# placeholder in a shared ContentArea (no per-tab sub-view nodes).
#
# CFG-06 INVARIANT: this script NEVER opens ~/.copland.yml directly for
# reading or writing. All YAML I/O goes through the copland CLI:
#   reads  → `copland config:show --json`
#   writes → `copland config:repos:add|edit|remove`
# A grep gate in the Phase 24 verification step asserts no FileAccess
# call in this file targets `.copland.yml`.

# Palette + type scale: duplicate of Main.gd's PALETTE/TYPE constants
# (deferred autoload extraction per Phase 24 CONTEXT.md D-02 — Theme.gd
# arrives when shared style emerges naturally between Main and Config).
const PALETTE := {
    "bg":          Color(0.082, 0.075, 0.066),
    "tray":        Color(0.110, 0.100, 0.086),
    "panel":       Color(0.137, 0.125, 0.106),
    "panel_lo":    Color(0.108, 0.099, 0.085),
    "panel_hi":    Color(0.180, 0.161, 0.133),
    "cream":       Color(0.945, 0.898, 0.788),
    "cream_lo":    Color(0.788, 0.741, 0.635),
    "dim":         Color(0.561, 0.518, 0.443),
    "faint":       Color(0.341, 0.314, 0.267),
    "rule":        Color(0.220, 0.196, 0.157),
    "brass":       Color(0.812, 0.682, 0.388),
    "brass_hi":    Color(0.961, 0.835, 0.510),
    "brass_dim":   Color(0.467, 0.388, 0.220),
    "danger":      Color(0.792, 0.412, 0.310),
    "merged":      Color(0.451, 0.620, 0.467),
}

const TAB_LABELS := ["Repos", "Per-Repo", "Asana", "Defaults"]
const TAB_PLACEHOLDERS := {
    1: "Coming in Phase 25",
    2: "Coming in Phase 26",
    3: "Coming in Phase 26",
}

var copland_bin: String = ""
var active_tab: int = 0
var current_repos: Array = []
var selected_repo_index: int = 0

# UI refs
var tab_strip_row: HBoxContainer
var content_area: MarginContainer
var repos_view: Control
var repos_rows_box: VBoxContainer
var error_panel: PanelContainer
var error_label: Label
var startup_banner: Label

# Modal: add/edit slug+path (two LineEdits)
var modal_layer: Control
var modal_title_label: Label
var modal_slug_input: LineEdit
var modal_path_input: LineEdit
var modal_mode: String = ""  # "add" | "edit"
var modal_edit_slug: String = ""  # locked slug when editing

# Modal: remove confirmation
var confirm_layer: Control
var confirm_label: Label
var confirm_target_slug: String = ""


func _ready() -> void:
    _build_layout()
    copland_bin = _resolve_copland_bin()
    if copland_bin == "":
        _show_startup_error("copland binary not found — pass --copland-bin, set $COPLAND_BIN, or add copland to PATH")
        return
    _refresh_snapshot()


# Resolve which copland binary to invoke.
# Order: --copland-bin cmdline arg → $COPLAND_BIN env var → `which copland` → empty.
#
# Duplicate of Main.gd::_resolve_copland_bin — kept inline rather than
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


# ── Layout ───────────────────────────────────────────────────────────────────

func _build_layout() -> void:
    var bg := ColorRect.new()
    bg.color = PALETTE.bg
    bg.set_anchors_preset(Control.PRESET_FULL_RECT)
    bg.mouse_filter = Control.MOUSE_FILTER_IGNORE
    add_child(bg)

    var root := VBoxContainer.new()
    root.set_anchors_preset(Control.PRESET_FULL_RECT)
    root.add_theme_constant_override("separation", 10)
    add_child(root)

    # Header
    var header := MarginContainer.new()
    header.add_theme_constant_override("margin_top", 24)
    header.add_theme_constant_override("margin_left", 36)
    header.add_theme_constant_override("margin_right", 36)
    header.add_theme_constant_override("margin_bottom", 12)
    root.add_child(header)
    var header_label := Label.new()
    header_label.text = "COPLAND  ·  CONFIG"
    header_label.add_theme_color_override("font_color", PALETTE.brass)
    header_label.add_theme_font_size_override("font_size", 28)
    header.add_child(header_label)

    # Tab strip
    var tab_wrap := MarginContainer.new()
    tab_wrap.add_theme_constant_override("margin_left", 36)
    tab_wrap.add_theme_constant_override("margin_right", 36)
    root.add_child(tab_wrap)
    tab_strip_row = HBoxContainer.new()
    tab_strip_row.add_theme_constant_override("separation", 24)
    tab_wrap.add_child(tab_strip_row)
    _build_tab_strip()

    # Underline rule
    var rule := ColorRect.new()
    rule.color = PALETTE.rule
    rule.custom_minimum_size = Vector2(0, 1)
    root.add_child(rule)

    # Content area (shared MarginContainer — placeholders share this)
    content_area = MarginContainer.new()
    content_area.add_theme_constant_override("margin_left", 36)
    content_area.add_theme_constant_override("margin_right", 36)
    content_area.add_theme_constant_override("margin_top", 18)
    content_area.add_theme_constant_override("margin_bottom", 18)
    content_area.size_flags_vertical = Control.SIZE_EXPAND_FILL
    root.add_child(content_area)

    # Footer hint
    var footer_wrap := MarginContainer.new()
    footer_wrap.add_theme_constant_override("margin_left", 36)
    footer_wrap.add_theme_constant_override("margin_right", 36)
    footer_wrap.add_theme_constant_override("margin_bottom", 18)
    root.add_child(footer_wrap)
    var footer_label := Label.new()
    footer_label.text = "1/2/3/4 switch tabs  ·  A add  ·  E edit  ·  D/Del remove  ·  ESC back to Main"
    footer_label.add_theme_color_override("font_color", PALETTE.dim)
    footer_label.add_theme_font_size_override("font_size", 13)
    footer_wrap.add_child(footer_label)

    _build_modal()
    _build_confirm_modal()
    _render_active_tab()


func _build_tab_strip() -> void:
    for c in tab_strip_row.get_children():
        c.queue_free()
    for i in TAB_LABELS.size():
        var lbl := Label.new()
        var prefix := "[%d] " % (i + 1)
        lbl.text = prefix + TAB_LABELS[i]
        if i == active_tab:
            lbl.add_theme_color_override("font_color", PALETTE.brass_hi)
        else:
            lbl.add_theme_color_override("font_color", PALETTE.dim)
        lbl.add_theme_font_size_override("font_size", 16)
        tab_strip_row.add_child(lbl)


func _render_active_tab() -> void:
    # Wipe content area and render the active tab's body.
    for c in content_area.get_children():
        c.queue_free()
    if active_tab == 0:
        _render_repos_view()
    else:
        var placeholder_label := Label.new()
        placeholder_label.text = TAB_PLACEHOLDERS.get(active_tab, "Coming soon")
        placeholder_label.add_theme_color_override("font_color", PALETTE.dim)
        placeholder_label.add_theme_font_size_override("font_size", 20)
        placeholder_label.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
        placeholder_label.vertical_alignment = VERTICAL_ALIGNMENT_CENTER
        placeholder_label.size_flags_vertical = Control.SIZE_EXPAND_FILL
        var center := CenterContainer.new()
        center.size_flags_vertical = Control.SIZE_EXPAND_FILL
        center.add_child(placeholder_label)
        content_area.add_child(center)


func _render_repos_view() -> void:
    repos_view = VBoxContainer.new()
    repos_view.add_theme_constant_override("separation", 12)
    repos_view.size_flags_vertical = Control.SIZE_EXPAND_FILL
    content_area.add_child(repos_view)

    # Error panel (persistent, hidden when empty)
    error_panel = PanelContainer.new()
    var err_sb := StyleBoxFlat.new()
    err_sb.bg_color = PALETTE.panel_lo
    err_sb.border_color = PALETTE.danger
    err_sb.set_border_width_all(1)
    err_sb.content_margin_left = 12
    err_sb.content_margin_right = 12
    err_sb.content_margin_top = 8
    err_sb.content_margin_bottom = 8
    error_panel.add_theme_stylebox_override("panel", err_sb)
    error_panel.visible = false
    repos_view.add_child(error_panel)
    error_label = Label.new()
    error_label.add_theme_color_override("font_color", PALETTE.danger)
    error_label.add_theme_font_size_override("font_size", 13)
    error_label.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
    error_panel.add_child(error_label)

    # Rows container
    repos_rows_box = VBoxContainer.new()
    repos_rows_box.add_theme_constant_override("separation", 4)
    repos_rows_box.size_flags_vertical = Control.SIZE_EXPAND_FILL
    repos_view.add_child(repos_rows_box)

    _render_repos_rows()


func _render_repos_rows() -> void:
    if repos_rows_box == null:
        return
    for c in repos_rows_box.get_children():
        c.queue_free()

    if current_repos.is_empty():
        var empty := Label.new()
        empty.text = "No repos configured. Press A to add one."
        empty.add_theme_color_override("font_color", PALETTE.dim)
        empty.add_theme_font_size_override("font_size", 16)
        empty.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
        var center := CenterContainer.new()
        center.size_flags_vertical = Control.SIZE_EXPAND_FILL
        center.add_child(empty)
        repos_rows_box.add_child(center)
        return

    if selected_repo_index >= current_repos.size():
        selected_repo_index = max(0, current_repos.size() - 1)

    for i in current_repos.size():
        var repo: Dictionary = current_repos[i]
        var row_panel := PanelContainer.new()
        var sb := StyleBoxFlat.new()
        sb.bg_color = PALETTE.panel_hi if i == selected_repo_index else PALETTE.panel_lo
        sb.border_color = PALETTE.brass if i == selected_repo_index else PALETTE.rule
        sb.set_border_width_all(0)
        sb.border_width_left = 3 if i == selected_repo_index else 0
        sb.content_margin_left = 12
        sb.content_margin_right = 12
        sb.content_margin_top = 8
        sb.content_margin_bottom = 8
        row_panel.add_theme_stylebox_override("panel", sb)
        var row_v := VBoxContainer.new()
        row_v.add_theme_constant_override("separation", 2)
        row_panel.add_child(row_v)
        var slug_lbl := Label.new()
        slug_lbl.text = String(repo.get("slug", "(no slug)"))
        slug_lbl.add_theme_color_override("font_color", PALETTE.cream)
        slug_lbl.add_theme_font_size_override("font_size", 17)
        row_v.add_child(slug_lbl)
        var path_lbl := Label.new()
        path_lbl.text = String(repo.get("path", "(no path)"))
        path_lbl.add_theme_color_override("font_color", PALETTE.dim)
        path_lbl.add_theme_font_size_override("font_size", 13)
        row_v.add_child(path_lbl)
        repos_rows_box.add_child(row_panel)


# ── Input ────────────────────────────────────────────────────────────────────

func _input(event: InputEvent) -> void:
    if not (event is InputEventKey):
        return
    var k: InputEventKey = event
    if not k.pressed:
        return

    if _modal_visible():
        if k.keycode == KEY_ESCAPE:
            _hide_modal()
            get_viewport().set_input_as_handled()
        elif k.keycode == KEY_ENTER or k.keycode == KEY_KP_ENTER:
            _submit_modal()
            get_viewport().set_input_as_handled()
        return

    if _confirm_visible():
        if k.keycode == KEY_ESCAPE or k.keycode == KEY_N:
            _hide_confirm()
            get_viewport().set_input_as_handled()
        elif k.keycode == KEY_Y or k.keycode == KEY_ENTER or k.keycode == KEY_KP_ENTER:
            _confirm_remove()
            get_viewport().set_input_as_handled()
        return

    match k.keycode:
        KEY_ESCAPE:
            get_tree().change_scene_to_file("res://scenes/Main.tscn")
            get_viewport().set_input_as_handled()
        KEY_1:
            active_tab = 0
            _build_tab_strip()
            _render_active_tab()
            get_viewport().set_input_as_handled()
        KEY_2:
            active_tab = 1
            _build_tab_strip()
            _render_active_tab()
            get_viewport().set_input_as_handled()
        KEY_3:
            active_tab = 2
            _build_tab_strip()
            _render_active_tab()
            get_viewport().set_input_as_handled()
        KEY_4:
            active_tab = 3
            _build_tab_strip()
            _render_active_tab()
            get_viewport().set_input_as_handled()
        KEY_UP:
            if active_tab == 0 and not current_repos.is_empty():
                selected_repo_index = max(0, selected_repo_index - 1)
                _render_repos_rows()
                get_viewport().set_input_as_handled()
        KEY_DOWN:
            if active_tab == 0 and not current_repos.is_empty():
                selected_repo_index = min(current_repos.size() - 1, selected_repo_index + 1)
                _render_repos_rows()
                get_viewport().set_input_as_handled()
        KEY_A:
            if active_tab == 0:
                _show_add_modal()
                get_viewport().set_input_as_handled()
        KEY_E:
            if active_tab == 0 and not current_repos.is_empty():
                _show_edit_modal()
                get_viewport().set_input_as_handled()
        KEY_D, KEY_DELETE:
            if active_tab == 0 and not current_repos.is_empty():
                _show_remove_confirm()
                get_viewport().set_input_as_handled()


# ── CLI shell-outs ───────────────────────────────────────────────────────────

# Synchronous OS.execute. Returns {exit_code: int, stdout: String, stderr: String}.
# v2.1 Phase 24 does NOT need threading — write commands return in <100ms.
func _invoke_cli(args: PackedStringArray) -> Dictionary:
    if copland_bin == "":
        return {"exit_code": -1, "stdout": "", "stderr": "copland binary not found"}
    var output: Array = []
    var exit_code: int = OS.execute(copland_bin, args, output, true)
    var stdout := ""
    if not output.is_empty():
        stdout = String(output[0])
    return {"exit_code": exit_code, "stdout": stdout, "stderr": stdout if exit_code != 0 else ""}


func _refresh_snapshot() -> void:
    var result := _invoke_cli(PackedStringArray(["config:show", "--json"]))
    if result.exit_code != 0:
        _show_error("config:show failed: " + String(result.stderr).strip_edges())
        return
    _hide_error()
    var raw := String(result.stdout)
    var json := JSON.new()
    if json.parse(raw) != OK:
        _show_error("config:show returned invalid JSON")
        return
    var snapshot = json.data
    if typeof(snapshot) != TYPE_DICTIONARY:
        _show_error("config:show returned unexpected payload shape")
        return
    var repos = snapshot.get("repos", [])
    current_repos = []
    if typeof(repos) == TYPE_ARRAY:
        for entry in repos:
            if typeof(entry) == TYPE_DICTIONARY:
                current_repos.append({
                    "slug": String(entry.get("slug", "")),
                    "path": String(entry.get("path", "")),
                })
    if active_tab == 0:
        _render_repos_rows()


func _show_error(message: String) -> void:
    if error_panel == null or error_label == null:
        return
    error_label.text = message
    error_panel.visible = true


func _hide_error() -> void:
    if error_panel != null:
        error_panel.visible = false


func _show_startup_error(message: String) -> void:
    if startup_banner == null:
        startup_banner = Label.new()
        startup_banner.add_theme_color_override("font_color", PALETTE.danger)
        startup_banner.add_theme_font_size_override("font_size", 16)
        startup_banner.autowrap_mode = TextServer.AUTOWRAP_WORD_SMART
        var wrap := MarginContainer.new()
        wrap.add_theme_constant_override("margin_left", 36)
        wrap.add_theme_constant_override("margin_right", 36)
        wrap.add_theme_constant_override("margin_top", 24)
        wrap.add_theme_constant_override("margin_bottom", 24)
        wrap.add_child(startup_banner)
        add_child(wrap)
    startup_banner.text = message


# ── Add / Edit modal ─────────────────────────────────────────────────────────

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
    sb.content_margin_top = 24
    sb.content_margin_bottom = 24
    panel.add_theme_stylebox_override("panel", sb)
    center.add_child(panel)

    var inner := VBoxContainer.new()
    inner.add_theme_constant_override("separation", 12)
    panel.add_child(inner)

    modal_title_label = Label.new()
    modal_title_label.add_theme_color_override("font_color", PALETTE.brass)
    modal_title_label.add_theme_font_size_override("font_size", 18)
    inner.add_child(modal_title_label)

    var slug_caption := Label.new()
    slug_caption.text = "slug (owner/repo):"
    slug_caption.add_theme_color_override("font_color", PALETTE.dim)
    slug_caption.add_theme_font_size_override("font_size", 12)
    inner.add_child(slug_caption)

    modal_slug_input = LineEdit.new()
    modal_slug_input.placeholder_text = "owner/repo"
    inner.add_child(modal_slug_input)

    var path_caption := Label.new()
    path_caption.text = "path (absolute):"
    path_caption.add_theme_color_override("font_color", PALETTE.dim)
    path_caption.add_theme_font_size_override("font_size", 12)
    inner.add_child(path_caption)

    modal_path_input = LineEdit.new()
    modal_path_input.placeholder_text = "/absolute/path/to/checkout"
    inner.add_child(modal_path_input)

    var hint := Label.new()
    hint.text = "ENTER to submit  ·  ESC to cancel"
    hint.add_theme_color_override("font_color", PALETTE.faint)
    hint.add_theme_font_size_override("font_size", 11)
    inner.add_child(hint)


func _show_add_modal() -> void:
    modal_mode = "add"
    modal_edit_slug = ""
    modal_title_label.text = "ADD REPO"
    modal_slug_input.text = ""
    modal_slug_input.editable = true
    modal_path_input.text = ""
    modal_layer.visible = true
    modal_slug_input.grab_focus()


func _show_edit_modal() -> void:
    if current_repos.is_empty():
        return
    var repo: Dictionary = current_repos[selected_repo_index]
    modal_mode = "edit"
    modal_edit_slug = String(repo.get("slug", ""))
    modal_title_label.text = "EDIT REPO: %s" % modal_edit_slug
    modal_slug_input.text = modal_edit_slug
    modal_slug_input.editable = false  # slug is immutable (D-04)
    modal_path_input.text = String(repo.get("path", ""))
    modal_layer.visible = true
    modal_path_input.grab_focus()


func _hide_modal() -> void:
    if modal_layer != null:
        modal_layer.visible = false


func _modal_visible() -> bool:
    return modal_layer != null and modal_layer.visible


func _submit_modal() -> void:
    if modal_mode == "add":
        var slug := modal_slug_input.text.strip_edges()
        var path := modal_path_input.text.strip_edges()
        _hide_modal()
        var result := _invoke_cli(PackedStringArray(["config:repos:add", "--slug", slug, "--path", path]))
        if result.exit_code != 0:
            _show_error(String(result.stderr).strip_edges())
        _refresh_snapshot()
    elif modal_mode == "edit":
        var path2 := modal_path_input.text.strip_edges()
        var slug2 := modal_edit_slug
        _hide_modal()
        var result2 := _invoke_cli(PackedStringArray(["config:repos:edit", "--slug", slug2, "--path", path2]))
        if result2.exit_code != 0:
            _show_error(String(result2.stderr).strip_edges())
        _refresh_snapshot()


# ── Remove confirmation modal ────────────────────────────────────────────────

func _build_confirm_modal() -> void:
    confirm_layer = Control.new()
    confirm_layer.set_anchors_preset(Control.PRESET_FULL_RECT)
    confirm_layer.visible = false
    confirm_layer.mouse_filter = Control.MOUSE_FILTER_STOP
    add_child(confirm_layer)

    var backdrop := ColorRect.new()
    backdrop.color = Color(0, 0, 0, 0.6)
    backdrop.set_anchors_preset(Control.PRESET_FULL_RECT)
    confirm_layer.add_child(backdrop)

    var center := CenterContainer.new()
    center.set_anchors_preset(Control.PRESET_FULL_RECT)
    confirm_layer.add_child(center)

    var panel := PanelContainer.new()
    panel.custom_minimum_size = Vector2(540, 0)
    var sb := StyleBoxFlat.new()
    sb.bg_color = PALETTE.panel
    sb.border_color = PALETTE.danger
    sb.set_border_width_all(2)
    sb.content_margin_left = 32
    sb.content_margin_right = 32
    sb.content_margin_top = 24
    sb.content_margin_bottom = 24
    panel.add_theme_stylebox_override("panel", sb)
    center.add_child(panel)

    var inner := VBoxContainer.new()
    inner.add_theme_constant_override("separation", 10)
    panel.add_child(inner)

    confirm_label = Label.new()
    confirm_label.add_theme_color_override("font_color", PALETTE.cream)
    confirm_label.add_theme_font_size_override("font_size", 16)
    inner.add_child(confirm_label)

    var hint := Label.new()
    hint.text = "Y to confirm  ·  N or ESC to cancel"
    hint.add_theme_color_override("font_color", PALETTE.dim)
    hint.add_theme_font_size_override("font_size", 12)
    inner.add_child(hint)


func _show_remove_confirm() -> void:
    if current_repos.is_empty():
        return
    var repo: Dictionary = current_repos[selected_repo_index]
    confirm_target_slug = String(repo.get("slug", ""))
    confirm_label.text = "Remove %s ?" % confirm_target_slug
    confirm_layer.visible = true


func _hide_confirm() -> void:
    if confirm_layer != null:
        confirm_layer.visible = false
    confirm_target_slug = ""


func _confirm_visible() -> bool:
    return confirm_layer != null and confirm_layer.visible


func _confirm_remove() -> void:
    var slug := confirm_target_slug
    _hide_confirm()
    if slug == "":
        return
    var result := _invoke_cli(PackedStringArray(["config:repos:remove", "--slug", slug]))
    if result.exit_code != 0:
        _show_error(String(result.stderr).strip_edges())
    _refresh_snapshot()

extends RefCounted
class_name TaskLoader

# Read-only task loader for the Copland visual console.
#
# Source of truth lives in the CLI's task store at:
#   ~/.copland/tasks/<repo-slug-safe>/<task_id>/{task.md,status.md,runs/...}
#
# task.md frontmatter:
#   id, title, repo_path, repo_slug, created_at
# status.md frontmatter:
#   state, updated_at
# runs/ is a sibling directory of task.md; each run is a subdir named with
# the run id, optionally containing its own status.md.
#
# Boot path:
#   load_real_or_sample()  → tries disk first, falls back to sample data
#   load_runs(path)        → run history for a given task directory

const STATES := [
    "new",
    "selected",
    "planning",
    "planned",
    "executing",
    "verifying",
    "pr_open",
    "blocked",
]

static func sample_tasks() -> Array:
    return [
        _sample("T001", "Wire footer status bar to live token usage",
            "binarygary/copland", "planning", "#42",
            "copland/T001-footer-tokens",
            ["internal/tui/footer.go", "internal/usage/tracker.go"],
            "Footer currently shows static dashes for token cost. Plumb the live ModelUsage aggregator into the footer renderer so the operator sees real-time spend during a run.",
            "2026-05-14 21:08"),
        _sample("T002", "Verifier should reject diffs over 250 lines",
            "binarygary/copland", "executing", "#47",
            "copland/T002-line-cap",
            ["internal/verify/diff.go", "internal/verify/diff_test.go"],
            "Planner enforces the 250-line cap at plan time but the verifier doesn't double-check post-execution. Add a hard guard with a clear failure message.",
            "2026-05-15 06:04"),
        _sample("T003", "Selector prompt: prefer issues with reproduction steps",
            "binarygary/copland", "new", "#51", "",
            ["internal/claude/selector_prompt.yml"],
            "Selector currently treats all well-scoped issues equally. Reweight scoring so issues with explicit repro steps rank higher.",
            "2026-05-15 18:22"),
        _sample("T004", "Add .copland.yml schema validation on load",
            "binarygary/copland", "pr_open", "#39",
            "copland/T004-config-schema",
            ["internal/config/repo.go", "internal/config/repo_test.go", "schema/copland.schema.json"],
            "Bad repo config currently fails deep in the planner with cryptic errors. Validate against a JSON schema at load and surface a clear message.",
            "2026-05-13 09:41"),
        _sample("T005", "Investigate worktree leak after failed PR push",
            "binarygary/copland", "blocked", "#54", "",
            [],
            "When `gh pr create` fails after commit, the worktree is left behind. Blocked pending decision on whether to auto-clean or surface for manual review.",
            "2026-05-15 22:15"),
        _sample("T006", "Render policy violations with offending path highlighted",
            "binarygary/copland", "planned", "#48", "copland/T006-policy-render",
            ["internal/tui/policy_view.go"],
            "Policy violation errors print as a single line. Break them into the rule, the offending path, and the suggested remediation.",
            "2026-05-14 14:30"),
    ]


static func _sample(id: String, title: String, repo: String, state: String,
        issue: String, branch: String, files: Array, summary: String,
        created: String) -> Dictionary:
    return {
        "id": id,
        "title": title,
        "repo": repo,
        "repo_path": "",
        "state": state,
        "issue": issue,
        "branch": branch,
        "files_to_change": files,
        "summary": summary,
        "created": created,
        "updated": created,
        "runs_count": 0,
        "task_dir": "",
        "is_real": false,
    }


# Scan ~/.copland/tasks/ for real tasks. Falls back to sample data when no
# real tasks AND no registered repos exist.
#
# `extra_repo_paths` is the console's repo registry (filesystem paths). Any
# path in that list whose tasks/<slug>/ dir doesn't yet exist still surfaces
# as a "stub repo" so the user sees it in the sidebar even before any tasks
# have been created against it.
static func load_real_or_sample(extra_repo_paths: Array = []) -> Array:
    var home := OS.get_environment("HOME")
    if home == "":
        return sample_tasks()

    var root := home + "/.copland/tasks"
    var dir := DirAccess.open(root)

    var tasks: Array = []
    if dir != null:
        dir.list_dir_begin()
        var repo := dir.get_next()
        while repo != "":
            if dir.current_is_dir() and not repo.begins_with("."):
                var repo_dir := DirAccess.open(root + "/" + repo)
                if repo_dir != null:
                    repo_dir.list_dir_begin()
                    var task_id := repo_dir.get_next()
                    while task_id != "":
                        if repo_dir.current_is_dir() and not task_id.begins_with("."):
                            var task_path := root + "/" + repo + "/" + task_id
                            var t := _read_task(task_path)
                            if not t.is_empty():
                                tasks.append(t)
                        task_id = repo_dir.get_next()
            repo = dir.get_next()

    # Stub entries for registered repos that don't yet have tasks
    var existing_repos: Dictionary = {}
    for t in tasks:
        existing_repos[String(t.get("repo", ""))] = true
    for path in extra_repo_paths:
        var slug := _slug_from_path(String(path))
        if slug == "" or existing_repos.has(slug):
            continue
        tasks.append(_repo_stub(slug, String(path)))
        existing_repos[slug] = true

    if tasks.is_empty():
        return sample_tasks()

    tasks.sort_custom(func(a, b): return String(a.id) < String(b.id))
    return tasks


# Derive a slug from a filesystem path. "/Users/x/projects/some/repo" → "some/repo"
# by using the last two path segments. Single-segment paths use just the basename.
static func _slug_from_path(path: String) -> String:
    if path == "":
        return ""
    var parts := path.trim_suffix("/").split("/")
    if parts.size() >= 2:
        return "%s/%s" % [parts[parts.size() - 2], parts[parts.size() - 1]]
    return path.get_file()


# Synthesizes a placeholder "task" entry whose only purpose is to make the
# repo visible in the sidebar (the renderer iterates `tasks` to build the
# repo list). Filtered out of the manifest by its `is_stub` flag.
static func _repo_stub(slug: String, repo_path: String) -> Dictionary:
    return {
        "id": "__stub__:" + slug,
        "title": "",
        "repo": slug,
        "repo_path": repo_path,
        "state": "",
        "issue": "",
        "branch": "",
        "files_to_change": [],
        "summary": "",
        "created": "",
        "updated": "",
        "runs_count": 0,
        "task_dir": "",
        "is_real": false,
        "is_stub": true,
    }


static func _read_task(path: String) -> Dictionary:
    var task_file := path + "/task.md"
    var status_file := path + "/status.md"
    if not FileAccess.file_exists(task_file):
        return {}

    var fm := _read_frontmatter(task_file)
    var status_fm := _read_frontmatter(status_file)

    var id := String(fm.get("id", path.get_file()))
    var title := String(fm.get("title", id))
    var repo := String(fm.get("repo_slug", ""))
    var repo_path := String(fm.get("repo_path", ""))
    var created := String(fm.get("created_at", ""))
    var state := String(status_fm.get("state", "new"))
    var updated := String(status_fm.get("updated_at", created))

    return {
        "id": id,
        "title": title,
        "repo": repo,
        "repo_path": repo_path,
        "state": state,
        "issue": "",
        "branch": "",
        "files_to_change": [],
        "summary": "",
        "created": created,
        "updated": updated,
        "runs_count": _count_runs(path + "/runs"),
        "task_dir": path,
        "is_real": true,
    }


# Parse YAML-ish frontmatter at the top of a markdown file. Handles:
#   ---
#   key: value
#   key: 'value'
#   ---
# Only top-level scalar pairs — no nesting needed for task.md/status.md.
static func _read_frontmatter(file_path: String) -> Dictionary:
    var out: Dictionary = {}
    if not FileAccess.file_exists(file_path):
        return out
    var f := FileAccess.open(file_path, FileAccess.READ)
    if f == null:
        return out
    var text := f.get_as_text()
    var lines := text.split("\n")
    var in_frontmatter := false
    var seen_open := false
    for raw in lines:
        var line: String = raw.strip_edges(false, true)
        var stripped: String = line.strip_edges()
        if stripped == "---":
            if not seen_open:
                seen_open = true
                in_frontmatter = true
                continue
            else:
                break
        if not in_frontmatter:
            # status.md has no leading "---"; treat all leading scalar lines
            # as frontmatter until a blank line.
            if stripped == "":
                if seen_open:
                    break
                continue
            seen_open = true
            in_frontmatter = true
        if stripped == "":
            break
        var colon := stripped.find(":")
        if colon <= 0:
            continue
        var key := stripped.substr(0, colon).strip_edges()
        var val := stripped.substr(colon + 1).strip_edges()
        val = val.trim_prefix("'").trim_suffix("'")
        val = val.trim_prefix("\"").trim_suffix("\"")
        # Unescape the 4 sequences the PHP writer escapes (\\, \", \n, \r).
        # SOH (0x01) is the sentinel for an escaped backslash so the final
        # backslash-restore step doesn't double-decode the other sequences.
        var soh := String.chr(1)
        val = val.replace("\\\\", soh)
        val = val.replace("\\\"", "\"")
        val = val.replace("\\n", "\n")
        val = val.replace("\\r", "\r")
        val = val.replace(soh, "\\")
        out[key] = val
    return out


static func _count_runs(runs_dir: String) -> int:
    var d := DirAccess.open(runs_dir)
    if d == null:
        return 0
    var count := 0
    d.list_dir_begin()
    var entry := d.get_next()
    while entry != "":
        if d.current_is_dir() and not entry.begins_with("."):
            count += 1
        entry = d.get_next()
    return count


# Returns a list of {id, updated, state, path} dicts for runs under a task,
# newest first by directory mtime.
static func load_runs(task_dir: String) -> Array:
    var runs_dir := task_dir + "/runs"
    var d := DirAccess.open(runs_dir)
    if d == null:
        return []
    var rows: Array = []
    d.list_dir_begin()
    var entry := d.get_next()
    while entry != "":
        if d.current_is_dir() and not entry.begins_with("."):
            var run_path := runs_dir + "/" + entry
            var status := _read_frontmatter(run_path + "/status.md")
            var mtime := FileAccess.get_modified_time(run_path)
            rows.append({
                "id": entry,
                "state": String(status.get("state", "—")),
                "updated": String(status.get("updated_at", "")),
                "path": run_path,
                "mtime": mtime,
            })
        entry = d.get_next()
    rows.sort_custom(func(a, b): return int(a.mtime) > int(b.mtime))
    return rows

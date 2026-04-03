# Overnight Setup

This guide covers the operational path for running Copland across one or more repos overnight on macOS.

## 1. Configure Global Settings

Create or update `~/.copland.yml`:

```yaml
claude_api_key: "sk-ant-..."

models:
  selector: claude-haiku-4-5
  planner: claude-sonnet-4-6
  executor: claude-sonnet-4-6

defaults:
  max_files_changed: 3
  max_lines_changed: 250
  base_branch: main

api:
  retry:
    max_attempts: 3
    base_delay_seconds: 1

repos:
  - slug: your-org/repo-one
    path: /absolute/path/to/repo-one
  - slug: your-org/repo-two
    path: /absolute/path/to/repo-two
```

Recommendations:

- Use explicit `slug` + `path` entries for every repo.
- Keep the paths pointed at clean working checkouts of the repos you want Copland to scan.

## 2. Configure Each Repository

Each target repo needs a `.copland.yml` at its root.

Example:

```yaml
base_branch: main
max_executor_rounds: 12
read_file_max_lines: 300

issue_labels:
  required: [agent-ready]
  blocked: [agent-skip, blocked]

allowed_commands:
  - composer
  - npm
  - pest

blocked_paths:
  - database/migrations

risky_keywords:
  - migration
  - auth
  - billing
  - deploy
  - infrastructure

repo_summary: ""
conventions: ""
```

Review this file before overnight runs. It defines what the executor can read, write, and run.

## 3. Prepare the GitHub Queue

Copland looks for issues carrying the repo’s required labels, which default to `agent-ready`.

Suggested flow:

1. Create or refine a small, concrete issue.
2. Add the `agent-ready` label.
3. Avoid labeling work that requires migrations, auth changes, billing changes, or broad architectural edits unless the repo policy is intentionally set up for that risk.

You can preview the queue from a repo checkout:

```bash
php ./copland issues
```

You can preview the next proposed contract without executing it:

```bash
php ./copland plan
```

## 4. Test a Manual Run First

Before scheduling anything, run Copland manually from the project checkout:

```bash
php ./copland run
```

If `repos:` is configured in `~/.copland.yml`, that single command iterates each configured repo sequentially.

## 5. Install the macOS LaunchAgent

From the Copland project checkout:

```bash
php ./copland setup
```

Default schedule:

- Hour: `2`
- Minute: `0`

Custom example:

```bash
php ./copland setup --hour=1 --minute=30
```

The command installs or refreshes:

- `~/Library/LaunchAgents/com.binarygary.copland.plist`
- `~/.copland/logs/launchd/stdout.log`
- `~/.copland/logs/launchd/stderr.log`

It also reloads the LaunchAgent with `launchctl`.

## 6. Verify the LaunchAgent

After `php ./copland setup`, run:

```bash
launchctl start com.binarygary.copland
```

Successful verification means:

- `launchctl start` returns without errors
- Copland still resolves `~/.copland.yml`
- The run log updates under `~/.copland/logs/runs.jsonl`

If you need to unload the job:

```bash
launchctl unload ~/Library/LaunchAgents/com.binarygary.copland.plist
```

If you want to remove it completely:

```bash
launchctl unload ~/Library/LaunchAgents/com.binarygary.copland.plist
rm ~/Library/LaunchAgents/com.binarygary.copland.plist
```

Optional launchd log cleanup:

```bash
rm -rf ~/.copland/logs/launchd
```

## 7. Morning Review

Copland’s primary local audit trail is:

```bash
tail -n 5 ~/.copland/logs/runs.jsonl
```

Each line is a JSON object. Useful fields:

- `repo`
- `issue.number`
- `issue.title`
- `status`
- `partial`
- `failure_reason`
- `pr.url`
- `decision_path`
- `usage`

If you want to inspect the most recent record in a more structured way:

```bash
tail -n 1 ~/.copland/logs/runs.jsonl
```

Then review:

- The generated draft PR, if `status` is `succeeded`
- The failure reason and decision path, if `status` is `failed` or `crashed`
- The LaunchAgent stdout/stderr logs if scheduling itself appears broken

## 8. Practical Operating Notes

- Copland processes one issue per run.
- Multi-repo scheduling comes from repeated repo iteration inside one `copland run`, not parallel execution.
- The current stable morning-review path is `runs.jsonl`; the `status` command is present but not implemented.
- Draft PRs are intended for human review before merge.

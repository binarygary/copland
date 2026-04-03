# Copland

Copland is a local PHP CLI that works through labeled GitHub issues overnight. It selects one safe issue, plans the change with Claude, executes it in an isolated git worktree, verifies the result, and opens a draft PR for review.

It is designed for a single developer running against a small set of repositories from their own machine.

## What It Does

- Runs one issue per invocation.
- Selects only issues carrying the required label set, usually `agent-ready`.
- Applies repo-specific safety policy from a local `.copland.yml`.
- Opens draft PRs instead of merging automatically.
- Writes a local run log to `~/.copland/logs/runs.jsonl`.
- Can iterate multiple repos from one `copland run` invocation.
- Can install a macOS LaunchAgent for nightly automation with `copland setup`.

## Prerequisites

- PHP 8.2+
- Composer
- Git
- GitHub CLI authenticated for the target account: `gh auth login`
- Anthropic API key

## Installation

```bash
composer install
```

Copland uses the local project entrypoint:

```bash
php ./copland
```

## Global Configuration

On first run, Copland creates `~/.copland.yml` if it does not already exist.

Example:

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

Notes:

- `repos:` is optional. If present, `copland run` with no repo argument processes each configured repo sequentially.
- A string-only repo entry is allowed only when it matches the current checkout slug. The explicit `slug` + `path` form is safer.

## Repo Configuration

Each managed repository uses a repo-local `.copland.yml` for policy.

Example:

```yaml
base_branch: main
max_executor_rounds: 12
read_file_max_lines: 300

issue_labels:
  required: [agent-ready]
  blocked: [agent-skip, blocked]

allowed_commands:
  - php artisan
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

This file controls what the executor is allowed to do inside that repository.

## Commands

### `php ./copland issues {repo?}`

Fetches issues that match the repo’s required labels, applies the prefilter, and shows accepted vs rejected candidates.

### `php ./copland plan {repo?}`

Runs selector + planner only, prints the proposed contract, validates it, and writes the latest plan artifact under `~/.copland/runs/...`.

### `php ./copland run {repo?}`

Runs the full overnight flow:

1. Fetch issues
2. Prefilter
3. Select one issue
4. Plan the change
5. Validate the plan
6. Create a worktree
7. Execute the change
8. Verify the result
9. Open a draft PR or comment failure
10. Append a run record to `~/.copland/logs/runs.jsonl`

If no repo argument is provided:

- Copland uses `repos:` from `~/.copland.yml` when configured.
- Otherwise it runs against the current checkout.

### `php ./copland setup --hour=2 --minute=0`

Installs or refreshes a per-user macOS LaunchAgent under `~/Library/LaunchAgents/` so Copland can run nightly without manual cron setup.

It writes:

- A LaunchAgent plist
- `~/.copland/logs/launchd/stdout.log`
- `~/.copland/logs/launchd/stderr.log`

After installation, the command prints the manual verification command:

```bash
launchctl start com.binarygary.copland
```

### `php ./copland status`

`status` exists as a command name, but it is not implemented yet. The stable morning-review path today is `~/.copland/logs/runs.jsonl`.

## Workflow

1. Configure `~/.copland.yml`.
2. Add `.copland.yml` to each target repo.
3. Label candidate GitHub issues with `agent-ready`.
4. Run `php ./copland issues` if you want to inspect the current queue.
5. Run `php ./copland plan` to preview the contract for the next issue.
6. Run `php ./copland run` manually, or install nightly automation with `php ./copland setup`.
7. Review `~/.copland/logs/runs.jsonl` and any draft PRs the next morning.

## Safety Model

- One issue per run
- Draft PRs only
- Repo-local blocked paths
- Repo-local allowed command list
- File read truncation
- File and line-change budget limits
- Retry/backoff for transient Anthropic failures

## Morning Review

Copland’s primary local audit trail is:

```bash
tail -n 5 ~/.copland/logs/runs.jsonl
```

Each line is a JSON record containing repo, issue, status, timestamps, decision path, and usage data.

For the full nightly automation flow, see [docs/overnight-setup.md](/Users/binarygary/projects/binarygary/copland/docs/overnight-setup.md).

## Scope Notes

- Copland is a local-machine workflow, not a hosted service.
- It is optimized for personal use across a handful of repos.
- It does not auto-merge PRs.
- It does not resume partially completed runs.

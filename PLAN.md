# Copland: Overnight Agent Orchestrator

## What it is

A standalone PHP CLI tool that runs nightly against registered GitHub repos, picks one safe issue, plans it with Claude, executes it with Claude, and opens a draft PR — all unattended.

It is a **control plane**, not a coding agent.

- **Copland CLI** = scheduler, orchestrator, policy engine, tool execution sandbox
- **Claude API (selector)** = picks the right issue
- **Claude API (planner)** = produces the implementation contract
- **Claude API (executor)** = implements the plan via tool use loop
- **GitHub** = task source, PR target, and audit log (issue comments = history)

---

## Tech stack

- Laravel Zero (PHP CLI framework)
- PHP 8.4+
- `gh` CLI for GitHub auth (piggybacks on `gh auth token`)
- Claude API (claude-sonnet-4-6) for selector, planner, and executor
- Anthropic PHP SDK for API calls
- Symfony Process for running shell commands on behalf of Claude
- No database — GitHub is the source of truth

---

## Invocation

```
copland run owner/repo       # full nightly flow
copland issues owner/repo    # fetch and show candidate issues (dry run)
copland plan owner/repo      # select + plan without executing
copland status               # last run per registered repo
```

---

## Auth

GitHub auth is delegated to the `gh` CLI.

At runtime, Copland calls `gh auth token` to get the current token.
No credential storage in Copland itself.

Claude API key lives in `~/.copland/config.yml`.

---

## Configuration

### Global config — `~/.copland/config.yml`

Machine-level settings.

```yaml
claude_api_key: sk-...

defaults:
  max_files_changed: 3
  max_lines_changed: 250
  base_branch: main

repos:
  - owner/ncdrivinglog
  - owner/other-repo
```

### Repo config — `.copland.yml` in each repo root

Repo-specific settings, version-controlled with the repo.

```yaml
base_branch: main
worktree_base: ~/agent-worktrees

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
  - config/auth.php
  - .env

risky_keywords:
  - migration
  - auth
  - billing
  - deploy
  - infrastructure

repo_summary: |
  Brief description of what this repo does and its conventions.

conventions: |
  Coding style notes, patterns to follow, things to avoid.
```

---

## Claude executor (tool use loop)

Claude implements the plan by calling tools. Copland runs the loop, validates every call, and executes approved calls as real shell/filesystem operations inside the worktree.

### Tools available to Claude
- `read_file(path)` — read a file in the worktree
- `write_file(path, content)` — write or overwrite a file
- `run_command(command)` — run a shell command in the worktree
- `list_directory(path)` — list files in a directory

### Copland validates every tool call before executing
- `write_file`: reject if path is in `blocked_paths`
- `run_command`: reject if command is not in `allowed_commands`
- Any violation aborts the run immediately — no PR opened

### What Claude receives
- System prompt (from `resources/prompts/executor.md`)
- The implementation contract JSON as the first user message

### What Claude must not decide
- which task to work on
- what the policy is
- whether to open a PR

### Model
- `claude-sonnet-4-6` for all three roles (selector, planner, executor)

---

## Core workflow

### Full run (`copland run owner/repo`)

1. Read `.copland.yml` from repo root
2. Fetch open issues with `agent-ready` label via GitHub API
3. Prefilter: reject obviously unsafe issues
4. Claude selects one issue (or skips all)
5. Claude produces an implementation contract
6. Validate contract against policy (deterministic, no model involved)
7. Create isolated git worktree from `origin/main`
8. Run Claude executor tool-use loop inside the worktree
9. Copland validates and executes every tool call in real-time
10. Verify final diff: file count, line count, blocked paths, tests
11. If verification passes: commit, push, open draft PR
13. Comment on GitHub issue with PR link and summary
14. Clean up worktree
15. Print run summary to stdout

---

## GitHub issue intake

### Candidate query

Fetch open issues that:
- have label `agent-ready`
- do not have label `agent-skip` or `blocked`
- do not already have an open linked PR

### Prefilter rules

Skip issues if:
- missing usable body
- title/body contains risky keywords (from `.copland.yml`)
- already linked to an open draft PR
- too vague (no actionable description)

---

## Claude selector

Input: list of prefiltered candidate issues + project profile
Output: `select` (with issue number) or `skip_all`

Prompt file: `resources/prompts/selector.md`
Runtime values injected by the app.

---

## Claude planner

Input: selected issue + repo summary + conventions + allowed commands + blocked paths
Output: strict implementation contract JSON

Contract contains:
- branch name
- files to read first
- files to change
- steps
- commands to run
- tests to add/update
- success criteria
- guardrails
- PR title and body
- max files / lines

Prompt file: `resources/prompts/planner.md`

---

## Verification layer

Deterministic checks enforced by Copland, not the model:

- changed files ≤ max allowed
- changed lines ≤ max allowed
- no blocked paths touched
- only allowed commands used
- branch exists and is clean after commit
- tests passed (or failure explicitly recorded)

If any check fails: do not open PR. Comment failure summary on issue.

---

## Failure handling

No fallback path. One executor (Claude API tool-use loop).

If execution fails:
- tool call violated policy → abort immediately, no PR
- Claude produced no changes → mark run failed
- verification fails → mark run failed, no PR

In all failure cases: comment a concise summary on the issue.

---

## GitHub publishing

On success:
- open draft PR with title/body from plan
- comment on issue: PR link, branch, tests run, any caveats
- optionally label issue `agent-in-review`

On failure:
- comment on issue with concise failure summary
- optionally label issue `agent-failed`

---

## Audit trail

GitHub is the source of truth. No local database.

- Issue comments = why selected/rejected, outcome
- PR description = plan + execution notes + tests run + fallback notes

---

## Git workspace management

Use git worktrees for isolation.

Per run:
- create worktree at `~/agent-worktrees/{repo-slug}/{run-uuid}`
- branch from `origin/main`
- run Claude executor loop inside worktree
- clean up after (success or failure)

---

## Policies (v1 hard rules)

- one task per run
- GitHub issues only
- requires `agent-ready` label
- draft PR only — no auto-merge
- max 3 files changed
- max 250 lines changed
- no migrations
- no auth/billing/infra changes
- no blocked paths
- Claude API executor only (tool-use loop)
- no fallback executor

---

## Prompt files

Stored in `resources/prompts/` — editable and version-controlled.

- `resources/prompts/selector.md`
- `resources/prompts/planner.md`
- `resources/prompts/executor.md`

App injects runtime values (issue list, repo context, contract JSON, etc.) into templates.

---

## Services

### `GitHubService`
- `getCandidateIssues(string $repo): Collection`
- `hasOpenLinkedPr(string $repo, int $issueNumber): bool`
- `commentOnIssue(string $repo, int $issueNumber, string $body): void`
- `createDraftPr(string $repo, string $branch, string $title, string $body): array`
- `addLabel(string $repo, int $issueNumber, string $label): void`

Auth via `gh auth token` at runtime.

### `ClaudeSelectorService`
- `selectTask(array $profile, Collection $issues): SelectionResult`

### `ClaudePlannerService`
- `planTask(array $profile, array $issue): PlanResult`

### `WorkspaceService`
- `create(string $repoPath, string $branch, string $runUuid): string`
- `cleanup(string $workspacePath): void`

### `GitService`
- `fetch(string $repoPath): void`
- `createBranch(string $workspacePath, string $branch): void`
- `changedFiles(string $workspacePath): array`
- `changedLineCount(string $workspacePath): int`
- `commit(string $workspacePath, string $message): void`
- `push(string $workspacePath, string $branch): void`

### `CodexExecutorService`
- `executeLocal(string $workspacePath, array $plan): ExecutionResult`
- `executeCloud(string $workspacePath, array $plan): ExecutionResult`

Both use the same `codex` CLI — different backend flags.

### `VerificationService`
- `verify(array $profile, string $workspacePath, array $plan, ExecutionResult $result): VerificationResult`

### `RunOrchestratorService`
- `run(string $repo): RunResult`

Coordinates the full workflow. Records state in GitHub (comments) not locally.

---

## Commands

### `copland run {repo}`
Full nightly flow. Outputs structured log to stdout.

### `copland issues {repo}`
Fetch and display candidate issues. No execution. Good for testing intake.

### `copland plan {repo}`
Run selector + planner. Display contract. No git, no Codex.

### `copland status`
Show last known run result per registered repo (read from GitHub — last agent comment on issues/PRs).

---

## Implementation phases

### Phase 1: Foundation
1. Scaffold Laravel Zero app
2. Wire `gh auth token` for GitHub auth
3. Read `.copland.yml` from repo
4. Implement `GitHubService` — fetch issues, prefilter
5. `copland issues owner/repo` command works

### Phase 2: Planning
6. Implement `ClaudeSelectorService`
7. Implement `ClaudePlannerService`
8. Write selector + planner prompts
9. `copland plan owner/repo` command works — outputs contract JSON

### Phase 3: Execution
10. Implement `WorkspaceService` + `GitService`
11. Implement `CodexExecutorService` (local/Ollama)
12. Implement `VerificationService`
13. `copland run owner/repo` — local path only

### Phase 4: Publishing
14. Commit + push after verification
15. Open draft PR via GitHub API
16. Comment back on issue
17. Full end-to-end test against `ncdrivinglog`

### Phase 5: Fallback
18. Implement cloud Codex fallback path
19. Fallback trigger logic in orchestrator

---

## First milestones

1. `copland issues owner/repo` shows filtered candidate issues
2. `copland plan owner/repo` outputs a Claude-generated contract
3. `copland run owner/repo` creates a worktree and runs local Codex
4. First draft PR opened from the app
5. First successful unattended nightly run

---

## What not to build yet

- multiple tasks per run
- auto-merge
- Slack/email notifications
- web UI or dashboard
- NativePHP / menu bar
- multi-agent swarms
- budget routing
- non-GitHub issue sources

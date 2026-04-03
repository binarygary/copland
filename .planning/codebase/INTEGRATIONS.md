# External Integrations

**Analysis Date:** 2026-04-02

## APIs & External Services

**Anthropic Claude API:**
- Selection: Uses Claude to analyze GitHub issues and select one to work on
  - SDK: `anthropic-ai/sdk` (^0.8.0)
  - Service: `App\Services\ClaudeSelectorService`
  - Model: `claude-haiku-4-5` (configured in `~/.copland.yml`)
  - Max tokens: 1024

- Planning: Uses Claude to plan implementation steps
  - Service: `App\Services\ClaudePlannerService`
  - Model: `claude-sonnet-4-6` (configured in `~/.copland.yml`)
  - Max tokens: 2048
  - Uses tool_use capabilities for structured responses

- Execution: Uses Claude with tool calling for implementation
  - Service: `App\Services\ClaudeExecutorService`
  - Model: `claude-sonnet-4-6` (configured in `~/.copland.yml`)
  - Max tokens: 4096
  - Supports tool_use: read_file, write_file, replace_in_file, run_command, list_directory

**GitHub API:**
- Service: `App\Services\GitHubService`
- Base URI: `https://api.github.com`
- Authentication: Bearer token from `gh auth token` CLI
- Endpoints used:
  - `GET /repos/{repo}/issues` - Fetch issues with labels
  - `GET /repos/{repo}/issues/{issueNumber}/timeline` - Check for linked PRs
  - `POST /repos/{repo}/issues/{issueNumber}/comments` - Post comments on issues
  - `POST /repos/{repo}/issues/{issueNumber}/labels` - Add labels
  - `DELETE /repos/{repo}/issues/{issueNumber}/labels/{label}` - Remove labels
  - `POST /repos/{repo}/pulls` - Create draft PRs

## Version Control

**Git Operations:**
- Service: `App\Services\GitService`
- Client: Symfony Process executing git commands
- Operations:
  - Fetch from origin
  - Switch branches and create new ones
  - Detect uncommitted changes
  - Commit changes
  - Push branches to origin
  - Diff tracking for file changes and line counts

**GitHub CLI:**
- Uses `gh auth token` to retrieve authentication token
- Used by GitHubService to authenticate with GitHub API
- Required for local authentication state

## Workspace Shell Commands

**Supported Commands** (configured per repository in `.copland.yml`):
- `php artisan` - PHP Laravel artisan commands
- `composer` - PHP dependency management
- `npm` - Node package manager
- `pest` - PHP testing framework

Commands are executed by ClaudeExecutorService via `run_command` tool with 120-second timeout.

## Environment Configuration

**Required env vars:**
- None required as environment variables - uses `~/.copland.yml` global config file
- Anthropic API key stored in: `~/.copland.yml` under `claude_api_key`

**Authentication:**
- Anthropic: API key in `claude_api_key` config field
- GitHub: Token retrieved via `gh auth token` (GitHub CLI auth state)

**Secrets location:**
- User home directory: `~/.copland.yml` (global configuration, not committed)
- Per-repo config: `.copland.yml` at project root (should be git-ignored for sensitive values)

## Repository Configuration

**Per-Repository Settings** (`.copland.yml` at project root):
- `base_branch` - Target branch for PRs
- `max_executor_rounds` - Maximum iteration rounds
- `issue_labels.required` - Labels that must be present
- `issue_labels.blocked` - Labels that prevent processing
- `allowed_commands` - Whitelist of shell commands
- `blocked_paths` - File/directory paths that cannot be modified
- `risky_keywords` - Keywords triggering caution
- `repo_summary` - Repository description for Claude context
- `conventions` - Code style and patterns guide

## API Response Handling

**Anthropic API Responses:**
- All three Claude services parse JSON responses from model
- Responses wrapped in markdown code fences (removed by extractJson)
- Token usage tracked: `usage.inputTokens` and `usage.outputTokens`
- Cost estimation via `AnthropicCostEstimator::forModel()`

**GitHub API Responses:**
- All requests include:
  - Authorization: Bearer token from `gh auth token`
  - Accept: application/vnd.github+json (with special headers for some endpoints)
- Errors wrapped in RuntimeException with status codes
- JSON decoded and returned as arrays

## Data Flow for Issue Processing

1. **Selection Phase:**
   - GitHub API fetches open issues with required labels
   - ClaudeSelectorService analyzes issues with Claude
   - Returns selected issue number and reason

2. **Planning Phase:**
   - Repository configuration loaded
   - Issue details passed to ClaudePlannerService
   - Claude generates plan (files to change, steps, commands, etc.)
   - Plan stored with token usage metrics

3. **Execution Phase:**
   - Git prepares execution branch
   - ClaudeExecutorService runs multi-round loop with Claude
   - Claude uses tools to: read files, write changes, run tests
   - Each tool call validated against policy (blocked_paths, allowed_commands)
   - Results fed back to Claude for next iteration
   - Loop continues until completion or max_executor_rounds

4. **Completion Phase:**
   - Changed files detected via git diff
   - Draft PR created via GitHub API
   - Labels updated on original issue
   - Progress reported with token usage metrics

---

*Integration audit: 2026-04-02*

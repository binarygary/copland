# Technology Stack

**Analysis Date:** 2026-04-02

## Languages

**Primary:**
- PHP 8.2+ - Console application with CLI commands

## Runtime

**Environment:**
- PHP 8.2+ (required)

**Package Manager:**
- Composer - Dependency management
- Lockfile: Present (`composer.lock`)

## Frameworks

**Core:**
- Laravel Zero 12.0.2 - Micro-framework for console applications
- Laravel Framework (Illuminate) - Core components for routing, process execution, console features

**HTTP/Networking:**
- Guzzle HTTP 7.x - HTTP client for external API requests
- Symfony HTTP/PSR-7 - HTTP message standards

**CLI/Console:**
- Symfony Console - Command building and execution
- Laravel Prompts - Interactive terminal UI
- Nunomaduro Termwind - Terminal styling and formatting
- Nunomaduro Collision - Error reporting and formatting

**YAML/Configuration:**
- Symfony YAML - Parse and handle `.yml` configuration files

## Key Dependencies

**Critical:**
- `anthropic-ai/sdk` (^0.8.0) - Anthropic Claude API client for planning, selection, and execution
- `guzzlehttp/guzzle` - HTTP requests to GitHub API and other services
- `symfony/process` - Execute shell commands in the workspace

**Infrastructure:**
- `laravel-zero/framework` - Foundation for CLI application structure
- `symfony/yaml` - Configuration file parsing (`.copland.yml`)
- `illuminate/filesystem` - File system operations
- `illuminate/console` - Command routing and handling
- `symfony/process` - Process execution for git, composer, npm, pest commands

**Utilities:**
- `nesbot/carbon` - Date/time handling
- `ramsey/uuid` - UUID generation
- `illuminate/collections` - Collection utilities
- `filp/whoops` - Error reporting during development

## Configuration

**Environment:**
- `.copland.yml` - Global user configuration file (stored in `~/.copland.yml` or `~/.copland/config.yml`)
  - `claude_api_key` - Anthropic API key (required)
  - `models.selector` - Model for issue selection (default: claude-haiku-4-5)
  - `models.planner` - Model for planning (default: claude-sonnet-4-6)
  - `models.executor` - Model for execution (default: claude-sonnet-4-6)
  - `defaults.max_files_changed` - Default file limit per plan
  - `defaults.max_lines_changed` - Default lines limit per plan
  - `defaults.base_branch` - Default base branch (default: main)

- `.copland.yml` (repo-level) - Repository-specific configuration at project root
  - `base_branch` - Base branch for PRs
  - `max_executor_rounds` - Max iteration rounds for executor
  - `issue_labels.required` - Labels issue must have
  - `issue_labels.blocked` - Labels that block processing
  - `allowed_commands` - Shell commands executor can run
  - `blocked_paths` - File paths executor cannot access
  - `risky_keywords` - Keywords that trigger extra caution
  - `repo_summary` - Description of repository
  - `conventions` - Coding conventions guidance

**Build:**
- `box.json` - Build configuration for creating standalone PHAR executable
  - Compresses PHP and JSON
  - Creates distributable executable

## Platform Requirements

**Development:**
- PHP 8.2+
- Composer
- Git (for repository operations)
- GitHub CLI (`gh`) for authentication

**Production:**
- PHP 8.2+ runtime
- Anthropic API key configured in `~/.copland.yml`
- GitHub CLI authentication (`gh auth token`)
- Access to repository and target codebase

## External API Integrations

**Anthropic Claude API:**
- Base: `https://api.anthropic.com/v1/`
- Models used: `claude-haiku-4-5`, `claude-sonnet-4-6`
- Three service classes: ClaudeSelectorService, ClaudePlannerService, ClaudeExecutorService

**GitHub API:**
- Base: `https://api.github.com`
- Authentication: Bearer token from `gh auth token`
- Uses GuzzleHttp client wrapped in GitHubService

---

*Stack analysis: 2026-04-02*

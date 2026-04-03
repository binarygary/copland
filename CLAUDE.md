<!-- GSD:project-start source:PROJECT.md -->
## Project

**Copland**

Copland is a PHP CLI tool that automatically resolves GitHub issues overnight using Claude AI. It runs on a cron schedule, selects safe and well-defined issues from registered repos, plans and executes implementations in isolated git worktrees, and opens draft PRs for review. Built for personal use across a handful of repos.

**Core Value:** A reliable overnight agent that opens merge-ready PRs without intervention.

### Constraints

- **Tech stack**: PHP 8.2+ / Laravel Zero — established, not changing
- **Auth**: Must use `gh` CLI for GitHub auth — no credential storage
- **Safety**: All executor tool calls must be policy-validated before execution
- **Scope**: Max 3 files / 250 lines changed per issue — enforced by planner + verifier
<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- PHP 8.2+ - Console application with CLI commands
## Runtime
- PHP 8.2+ (required)
- Composer - Dependency management
- Lockfile: Present (`composer.lock`)
## Frameworks
- Laravel Zero 12.0.2 - Micro-framework for console applications
- Laravel Framework (Illuminate) - Core components for routing, process execution, console features
- Guzzle HTTP 7.x - HTTP client for external API requests
- Symfony HTTP/PSR-7 - HTTP message standards
- Symfony Console - Command building and execution
- Laravel Prompts - Interactive terminal UI
- Nunomaduro Termwind - Terminal styling and formatting
- Nunomaduro Collision - Error reporting and formatting
- Symfony YAML - Parse and handle `.yml` configuration files
## Key Dependencies
- `anthropic-ai/sdk` (^0.8.0) - Anthropic Claude API client for planning, selection, and execution
- `guzzlehttp/guzzle` - HTTP requests to GitHub API and other services
- `symfony/process` - Execute shell commands in the workspace
- `laravel-zero/framework` - Foundation for CLI application structure
- `symfony/yaml` - Configuration file parsing (`.copland.yml`)
- `illuminate/filesystem` - File system operations
- `illuminate/console` - Command routing and handling
- `symfony/process` - Process execution for git, composer, npm, pest commands
- `nesbot/carbon` - Date/time handling
- `ramsey/uuid` - UUID generation
- `illuminate/collections` - Collection utilities
- `filp/whoops` - Error reporting during development
## Configuration
- `.copland.yml` - Global user configuration file (stored in `~/.copland.yml` or `~/.copland/config.yml`)
- `.copland.yml` (repo-level) - Repository-specific configuration at project root
- `box.json` - Build configuration for creating standalone PHAR executable
## Platform Requirements
- PHP 8.2+
- Composer
- Git (for repository operations)
- GitHub CLI (`gh`) for authentication
- PHP 8.2+ runtime
- Anthropic API key configured in `~/.copland.yml`
- GitHub CLI authentication (`gh auth token`)
- Access to repository and target codebase
## External API Integrations
- Base: `https://api.anthropic.com/v1/`
- Models used: `claude-haiku-4-5`, `claude-sonnet-4-6`
- Three service classes: ClaudeSelectorService, ClaudePlannerService, ClaudeExecutorService
- Base: `https://api.github.com`
- Authentication: Bearer token from `gh auth token`
- Uses GuzzleHttp client wrapped in GitHubService
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

## Naming Patterns
- PascalCase for class files: `GitService.php`, `GlobalConfig.php`, `ExecutorPolicy.php`
- PascalCase for data classes: `SelectionResult.php`, `ModelUsage.php`
- PascalCase for commands: `PlanCommand.php`, `RunCommand.php`
- Test files follow class name with `Test` suffix: `GitServiceTest.php`, `ExecutorPolicyTest.php`
- camelCase for public methods: `prepareExecutionBranch()`, `hasUncommittedChanges()`, `assertToolPathAllowed()`
- camelCase for private methods: `branchExists()`, `normalizePath()`, `ensureExists()`
- Snake_case for command-line methods: `handle()` is entry point (Laravel convention)
- camelCase for local variables: `$repoPath`, `$errorMessage`, `$selectedIssue`
- camelCase for private properties: `$data`, `$path`, `$runner`, `$blockedPaths`
- PascalCase for class properties in data classes (readonly): `public readonly string $decision`
- Explicit nullable types: `?int`, `?string`, `?ModelUsage`
- Array types: `array` for generic arrays
- Union types when appropriate: `string|int`
## Code Style
- Tool: EditorConfig + Laravel Pint
- Indentation: 4 spaces
- Line endings: LF
- Final newline: required
- Trailing whitespace: trimmed
- YAML indentation: 2 spaces (override in `.editorconfig`)
- Tool: Laravel Pint
- Configuration: Default Laravel Pint rules with project dependency `"laravel/pint": "^1.25.1"`
- Run with: `./vendor/bin/pint`
## Import Organization
- PSR-4 autoload namespace `App\` maps to `/app` directory
- Tests namespace `Tests\` maps to `/tests` directory
## Error Handling
- Throw `RuntimeException` for operational failures: `throw new RuntimeException('Working tree is dirty...')`
- Throw custom `PolicyViolationException` for policy/security violations: `throw new PolicyViolationException("Tool '{$tool}' cannot access blocked path...")`
- Check exit codes explicitly: `if ($result['exitCode'] !== 0) { throw new RuntimeException(...) }`
- All thrown exceptions include descriptive context
## Logging
- Use `$this->line()` in commands for standard output
- Use `$this->error()` for error messages
- Use `$this->detail()` wrapper in progress reporter for indented details
- No logging framework dependency; direct output to stdout/stderr
## Comments
- Class-level documentation for public classes and services
- Document non-obvious algorithm choices or workarounds
- Document security/policy implications
- Explain WHY, not WHAT (code shows what it does)
- Not consistently used for simple getter methods
- Constructor property promotion used instead: `public function __construct(private $runner = null) {}`
- Type hints provide sufficient documentation in most cases
## Function Design
- Use constructor injection for dependencies: `public function __construct(private array $blockedPaths = [])`
- Pass primitive types and arrays directly
- Use null-coalescing for optional config: `$this->data['key'] ?? 'default'`
- Explicit return types: `void`, `string`, `array`, `int`, `bool`
- Return early pattern to reduce nesting: `if (...) { return; }`
## Module Design
- Classes define single responsibility: `GitService` handles git operations, `GlobalConfig` handles config
- Public methods are intentionally exposed; private methods are implementation details
- Data classes use readonly properties: `public readonly string $decision`
- Not used; direct imports preferred
## Service Layer Pattern
- Services accept dependencies via constructor
- Testable via constructor injection of callable mocks (see `GitService` with `$runner` parameter)
- Return data objects from `App\Data\` namespace
- Throw descriptive exceptions for failures
## Config Classes Pattern
- Load and cache configuration in constructor
- Public getter methods return typed values with defaults
- Ensure files exist before reading (create default if missing)
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## Pattern Overview
- Multi-stage Claude AI coordination (selector, planner, executor)
- Policy-enforced constraint system for file/command access
- Event-driven tool execution with agentic loop for executor
- Configuration-driven behavior (global config + repo-specific rules)
- State tracking and error detection to prevent agent thrashing
## Layers
- Purpose: User-facing CLI entry points that coordinate service orchestration
- Location: `app/Commands/`
- Contains: PlanCommand, RunCommand, IssuesCommand, StatusCommand
- Depends on: Config, Services, Support classes
- Used by: CLI interface (copland binary)
- Purpose: Coordinates the multi-phase workflow and manages overall execution state
- Location: `app/Services/RunOrchestratorService.php`
- Contains: Complete 8-step workflow logic (fetch → select → plan → validate → execute → verify → commit → PR)
- Depends on: All downstream services, GitHub API, git operations
- Used by: RunCommand, contains overall state machine
- Purpose: Interfaces with Anthropic API for specific decision-making tasks
- Location: `app/Services/ClaudeSelectorService.php`, `ClaudePlannerService.php`, `ClaudeExecutorService.php`
- Contains: LLM prompt template rendering, JSON response parsing, token usage tracking
- Depends on: GlobalConfig (for API keys and model names), Anthropic Client
- Used by: RunOrchestratorService, Commands
- Purpose: Enforces constraints on agent operations to prevent harm
- Location: `app/Support/ExecutorPolicy.php`, `ExecutorRunState.php`
- Contains: Path blocking rules, command allowlisting, file mutation tracking, thrashing detection
- Depends on: PlanResult, repoProfile configuration
- Used by: ClaudeExecutorService (for all tool dispatch decisions)
- Purpose: Handles GitHub API and git operations
- Location: `app/Services/GitHubService.php`, `GitService.php`
- Contains: Issue fetching, PR creation, branch/commit management, GH CLI auth
- Depends on: GuzzleHttp client, Symfony Process (for git commands)
- Used by: RunOrchestratorService, prefilter, verification
- Purpose: Loads and provides access to global and repo-specific policies
- Location: `app/Config/GlobalConfig.php`, `RepoConfig.php`
- Contains: YAML parsing, defaults, model selection, constraint policies
- Depends on: Symfony YAML parser
- Used by: All service classes, commands
- Purpose: Immutable data objects representing stage outputs
- Location: `app/Data/`
- Contains: PlanResult, ExecutionResult, RunResult, SelectionResult, PrefilterResult, VerificationResult, ModelUsage
- Depends on: Nothing (leaf classes)
- Used by: All services for passing structured data between layers
- Purpose: Cross-cutting helpers for logging, formatting, parsing
- Location: `app/Support/`
- Contains: Cost estimation, prompt serialization, progress formatting, artifact storage, file mutations
- Depends on: Data classes, configuration
- Used by: Services throughout the system
## Data Flow
- ExecutorRunState maintains in-memory state during execution: pending file reads, tool counts, error history
- RunProgressSnapshot captures intermediate usage metrics during long-running executor phase
- RunOrchestratorService.log accumulates detailed step-by-step log entries
- No persistent state between runs (all ephemeral)
## Key Abstractions
- Purpose: Immutable specification for what executor should do
- Location: `app/Data/PlanResult.php`
- Properties: branchName, filesToChange, filesToRead, steps, commandsToRun, testsToUpdate, successCriteria, guardrails, prTitle, prBody, maxFilesChanged, maxLinesChanged
- Pattern: Read-only data transfer object passed to executor and validator
- Purpose: Runtime guard rails for tool execution
- Location: `app/Support/ExecutorPolicy.php`
- Enforces: path normalization, blocked path checking, command allowlisting
- Throws: PolicyViolationException on violations
- Used by: ClaudeExecutorService on every tool dispatch
- Purpose: Tracks execution progress for thrashing detection
- Location: `app/Support/ExecutorRunState.php`
- Monitors: pending planned reads, write attempts, command execution, directory list calls, malformed calls
- Detects: loops (repeated malformed writes), stalling (no progress), exploration spam
- Used by: ClaudeExecutorService during agentic loop
- ClaudeSelectorService: Picks which issue to work on from candidates
- ClaudePlannerService: Creates work plan contract for executor
- ClaudeExecutorService: Executes multi-round agentic loop with tools
- All three: Load YAML templates, call Anthropic API, parse JSON, track token usage
## Entry Points
- Location: `app/Commands/RunCommand.php`
- Triggers: CLI invocation with optional repo argument
- Responsibilities: Load config, instantiate orchestrator, run complete pipeline, display usage stats and result
- Location: `app/Commands/PlanCommand.php`
- Triggers: CLI invocation to preview plan without executing
- Responsibilities: Fetch issues, run selector and planner, validate plan, save artifact, display plan details
- Location: `app/Commands/IssuesCommand.php`
- Triggers: CLI invocation to inspect candidate issues
- Responsibilities: Fetch issues, prefilter, display accepted/rejected with reasons
- Location: `app/Commands/StatusCommand.php`
- Triggers: CLI invocation
- Responsibilities: Display basic status info
## Error Handling
- PolicyViolationException: Raised by ExecutorPolicy when tool constraints violated
- RuntimeException: Raised by config/parsing failures
- GuzzleException: Raised by GitHub API calls
- Throwable in ClaudeExecutorService: Catches all tool errors
## Cross-Cutting Concerns
- RunOrchestratorService.log[] accumulates all 8-step progress entries
- ClaudeExecutorService.toolCallLog[] records each tool call with input/outcome/error flag
- No persistent logging framework (output to stdout via progressCallback)
- PlanValidatorService: Checks planner output against repo policies
- VerificationService: Checks actual git changes against planner's estimates
- ExecutorPolicy: Checks every tool call against workspace rules
- GitHub: Via `gh cli` (GitHubService.token() runs `gh auth token`)
- Anthropic: Via CLAUDE_API_KEY in GlobalConfig YAML
- Both stored in home directory config files
- AnthropicCostEstimator: Computes input/output cost from token counts
- ModelUsage data objects: Attached to each stage result (selector, planner, executor)
- RunResult aggregates all three for final cost display
<!-- GSD:architecture-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd:quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd:debug` for investigation and bug fixing
- `/gsd:execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd:profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->

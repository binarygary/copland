# Codebase Structure

**Analysis Date:** 2026-04-02

## Directory Layout

```
copland/
├── app/                       # Application code (PSR-4 autoload root)
│   ├── Commands/              # CLI command classes
│   ├── Config/                # Configuration loaders (global + repo)
│   ├── Data/                  # Immutable data transfer objects
│   ├── Exceptions/            # Custom exceptions
│   ├── Providers/             # Service providers (Laravel)
│   ├── Services/              # Business logic services
│   └── Support/               # Utilities and helpers
├── bootstrap/                 # Application bootstrap (Laravel)
├── config/                    # Laravel configuration files
├── resources/
│   └── prompts/               # Claude prompt templates (YAML/markdown)
├── tests/
│   ├── Feature/               # End-to-end feature tests
│   └── Unit/                  # Unit tests
├── vendor/                    # Composer dependencies
├── .planning/                 # GSD planning artifacts (orchestrator writes here)
├── .claude/                   # Claude workspace directory (plans/artifacts)
├── composer.json              # PHP dependency manifest
├── composer.lock              # Locked dependency versions
├── phpunit.xml.dist           # PHPUnit configuration
├── .copland.yml               # Global Copland configuration (user's home directory)
├── application                # Laravel Zero entry script
└── copland                    # CLI binary wrapper
```

## Directory Purposes

**`app/`**
- Purpose: All application PHP code, organized by layer
- Contains: Commands (CLI), Config (YAML loaders), Data (DTOs), Services (business logic), Support (utilities), Exceptions
- Key files: RunCommand, RunOrchestratorService, GlobalConfig, RepoConfig

**`app/Commands/`**
- Purpose: CLI command implementations (Laravel Zero)
- Contains: PlanCommand, RunCommand, IssuesCommand, StatusCommand
- Key files:
  - `RunCommand.php`: Main `copland run` entry point, orchestrates full workflow
  - `PlanCommand.php`: Preview plan without execution
  - `IssuesCommand.php`: List candidate issues for review
  - `StatusCommand.php`: Status display

**`app/Config/`**
- Purpose: YAML configuration loading and defaults
- Contains: GlobalConfig, RepoConfig
- Key files:
  - `GlobalConfig.php`: Loads `~/.copland.yml`, provides API keys + model selection
  - `RepoConfig.php`: Loads `.copland.yml` from repo root, provides policies (blocked paths, allowed commands, required labels)

**`app/Data/`**
- Purpose: Immutable data transfer objects for inter-service communication
- Contains: Result types for each pipeline stage
- Key files:
  - `PlanResult.php`: Planner output contract (files to change, steps, commands)
  - `ExecutionResult.php`: Executor outcome (success, tool log, duration, usage)
  - `RunResult.php`: Final orchestration result (status, PR URL, selected issue, log, all usage)
  - `SelectionResult.php`: Selector output (which issue selected, reason)
  - `PrefilterResult.php`: Prefilter output (accepted/rejected issues)
  - `VerificationResult.php`: Verification outcome (pass/fail, failures list)
  - `ModelUsage.php`: Token counts and cost estimation

**`app/Services/`**
- Purpose: Core business logic services
- Contains: Claude integrations, GitHub/git operations, validation, orchestration
- Key files:
  - `RunOrchestratorService.php`: Main 8-step workflow coordinator
  - `ClaudeSelectorService.php`: Calls Claude to pick an issue
  - `ClaudePlannerService.php`: Calls Claude to create work plan
  - `ClaudeExecutorService.php`: Multi-round agentic loop with tool execution
  - `GitHubService.php`: GitHub API client (issues, PRs, comments)
  - `GitService.php`: Git operations (branch, commit, push, diff)
  - `IssuePrefilterService.php`: Filters issues by keywords/labels
  - `PlanValidatorService.php`: Validates plan against repo policies
  - `VerificationService.php`: Validates actual changes against plan
  - `WorkspaceService.php`: Manages workspace (branch) creation/cleanup
  - `CurrentRepoGuardService.php`: Resolves target repository from CLI args

**`app/Support/`**
- Purpose: Cross-cutting utilities (not layer-specific)
- Contains: Helpers for formatting, parsing, estimation
- Key files:
  - `ExecutorPolicy.php`: Policy enforcement (path/command validation)
  - `ExecutorRunState.php`: Execution state tracking (pending reads, write/command/directory counts, thrashing detection)
  - `ExecutorProgressFormatter.php`: Formats progress messages for console output
  - `AnthropicCostEstimator.php`: Calculates token cost from usage data
  - `AnthropicMessageSerializer.php`: Converts Claude response to message format
  - `PlanArtifactStore.php`: Saves plan contracts to `.claude/` directory
  - `FileMutationHelper.php`: String replacement utilities for code changes
  - `IssueFileHintExtractor.php`: Extracts file hints from issue body
  - `PlanFieldNormalizer.php`: Normalizes list fields from JSON
  - `ProgressReporter.php`: Formats progress step display
  - `RunProgressSnapshot.php`: Captures intermediate metrics during executor

**`app/Exceptions/`**
- Purpose: Custom exception types
- Contains: PolicyViolationException
- Key files:
  - `PolicyViolationException.php`: Raised when tool call violates ExecutorPolicy

**`resources/prompts/`**
- Purpose: Claude prompt templates
- Contains: YAML/markdown templates with {{variable}} placeholders
- Key files:
  - `selector.md`: Prompt for issue selection (repo summary + issues)
  - `planner.md`: Prompt for work planning (issue + repo context + conventions)
  - `executor.md`: System prompt for execution mode (contract format explanation)

**`tests/Unit/`**
- Purpose: Unit tests for individual components
- Contains: Tests for services, support classes, configs
- Key files: ~15 test files covering ExecutorPolicy, FileMutationHelper, PlanValidatorService, git operations, cost estimation, etc.

**`tests/Feature/`**
- Purpose: Integration tests for full workflows
- Contains: Tests for command execution, GitHub API integration, Claude service mocking
- Key files: ClaudeServicesTest, GitHubServiceTest, InspireCommandTest

**`bootstrap/`**
- Purpose: Laravel application bootstrap (generated)
- Contains: cache configuration, service initialization
- Not typically edited

**`.claude/`**
- Purpose: Working directory for Claude-generated artifacts
- Contains: Plan JSON files saved by PlanArtifactStore
- Pattern: `.claude/{repo-name}/{issue-number}-{timestamp}.json`
- Not committed to git

**`.planning/`**
- Purpose: GSD (GitHub Semantic Deploy) planning documents
- Contains: codebase analysis documents (ARCHITECTURE.md, STRUCTURE.md, etc.)
- Generated by orchestrator analysis commands
- Not committed to git (typically)

## Key File Locations

**Entry Points:**
- `app/Commands/RunCommand.php`: Main `copland run` entry (orchestrates full workflow)
- `app/Commands/PlanCommand.php`: `copland plan` command (preview plan)
- `app/Commands/IssuesCommand.php`: `copland issues` command (list candidate issues)
- `copland` binary: CLI wrapper script (Laravel Zero)

**Configuration:**
- `app/Config/GlobalConfig.php`: Loads `~/.copland.yml` (API key, model selection)
- `app/Config/RepoConfig.php`: Loads `.copland.yml` from repo root (policies)
- `resources/prompts/*.md`: Claude prompt templates

**Core Logic:**
- `app/Services/RunOrchestratorService.php`: 8-step workflow orchestration
- `app/Services/ClaudeExecutorService.php`: Agentic execution loop (5 tools)
- `app/Services/ClaudePlannerService.php`: Plan generation via Claude
- `app/Services/ClaudeSelectorService.php`: Issue selection via Claude
- `app/Support/ExecutorPolicy.php`: Constraint enforcement
- `app/Support/ExecutorRunState.php`: Progress tracking + thrashing detection

**Testing:**
- `tests/Unit/ExecutorPolicyTest.php`: Path/command validation tests
- `tests/Unit/ExecutorRunStateTest.php`: Thrashing detection tests
- `tests/Feature/ClaudeServicesTest.php`: End-to-end service tests

## Naming Conventions

**Files:**
- Classes use PascalCase: `ExecutorPolicy.php`, `RunOrchestratorService.php`
- Test files append `Test`: `ExecutorPolicyTest.php`
- Prompt templates use kebab-case with `.md` extension: `executor.md`, `selector.md`

**Directories:**
- Namespaces match directory structure (PSR-4): `app/Services/` → `App\Services\`
- Plural for collections: `Commands/`, `Services/`, `Data/`, `Support/`

**Classes:**
- Service classes end with `Service`: `GitHubService`, `GitService`, `ClaudeExecutorService`
- Result/data classes end with `Result`: `PlanResult`, `ExecutionResult`, `RunResult`
- Validation classes named for their role: `ExecutorPolicy`, `ExecutorRunState`
- Exceptions named with `Exception`: `PolicyViolationException`

**Methods:**
- Commands: `handle()` (Laravel Zero convention)
- Services: Verb-noun pattern: `selectTask()`, `planTask()`, `executeWithPolicy()`, `validate()`
- Getters: `get*()` or just property name: `claudeApiKey()`, `blockedPaths()`
- Assertions: `assert*()` or `is*()`: `assertToolPathAllowed()`, `isBlockedPath()`

## Where to Add New Code

**New CLI Command:**
- Create class in `app/Commands/{CommandName}Command.php`
- Extend `LaravelZero\Framework\Commands\Command`
- Define $signature and $description properties
- Implement `handle()` method
- Register in `app/Providers/AppServiceProvider.php` if needed (usually auto-discovered)

**New Service (Business Logic):**
- Create class in `app/Services/{ServiceName}Service.php`
- Dependency inject required services/config via constructor
- Keep methods focused on single responsibility
- Return Data objects from `app/Data/` for complex results
- Add unit tests in `tests/Unit/{ServiceName}Test.php`

**New Data Transfer Object:**
- Create immutable readonly class in `app/Data/{ResultName}.php`
- Use constructor promotion for properties
- No behavior beyond property storage
- Example: `public function __construct(public readonly string $status, ...)`

**New Support/Utility:**
- Create static class in `app/Support/{UtilityName}.php` or instance class if needed
- Keep focused on cross-cutting concern
- Avoid dependencies on services when possible
- Examples: `ExecutorPolicy`, `AnthropicCostEstimator`

**New Prompt Template:**
- Create `.md` file in `resources/prompts/{name}.md`
- Use Markdown format with `{{variable}}` placeholders
- Placeholders replaced by service via `str_replace()`
- Include instructions for JSON response format
- Reference from service via `file_get_contents(base_path('resources/prompts/{name}.md'))`

**Tests:**
- Unit tests (single component): `tests/Unit/{ComponentName}Test.php`
- Feature tests (integration): `tests/Feature/{FeatureName}Test.php`
- Use Pest framework (defined in `tests/Pest.php`)
- Co-locate with source structure (mirror `app/` directories)

## Special Directories

**`.claude/`**
- Purpose: Claude-generated artifacts and plans
- Generated: Yes (by PlanArtifactStore at runtime)
- Committed: No (add to `.gitignore`)
- Contains: JSON files with plan contracts, one per issue attempt

**`.planning/`**
- Purpose: GSD planning documents and analysis
- Generated: Yes (by analysis commands from GSD orchestrator)
- Committed: No (orchestrator manages lifecycle)
- Contains: ARCHITECTURE.md, STRUCTURE.md, CONVENTIONS.md, TESTING.md, STACK.md, INTEGRATIONS.md, CONCERNS.md

**`vendor/`**
- Purpose: Composer dependencies
- Generated: Yes (`composer install`/`composer update`)
- Committed: No (lockfile only, `composer.lock` is committed)
- Contains: All third-party packages

**`config/`**
- Purpose: Laravel framework configuration (mostly empty/minimal)
- Generated: No (manually created as needed)
- Committed: Yes
- Standard Laravel directory (see Laravel Zero docs)

**`bootstrap/`**
- Purpose: Framework bootstrap code
- Generated: Partially (auto-generated cache)
- Committed: Yes (source files)
- Not typically edited directly

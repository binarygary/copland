# Architecture

**Analysis Date:** 2026-04-02

## Pattern Overview

**Overall:** Agent-Driven Task Execution Pipeline

Copland is a CLI application built on Laravel Zero that orchestrates Claude AI through a multi-stage pipeline to solve GitHub issues. The system divides work into distinct phases (selection → planning → execution → verification) with safety guards at each stage.

**Key Characteristics:**
- Multi-stage Claude AI coordination (selector, planner, executor)
- Policy-enforced constraint system for file/command access
- Event-driven tool execution with agentic loop for executor
- Configuration-driven behavior (global config + repo-specific rules)
- State tracking and error detection to prevent agent thrashing

## Layers

**Command Layer:**
- Purpose: User-facing CLI entry points that coordinate service orchestration
- Location: `app/Commands/`
- Contains: PlanCommand, RunCommand, IssuesCommand, StatusCommand
- Depends on: Config, Services, Support classes
- Used by: CLI interface (copland binary)

**Orchestration Layer:**
- Purpose: Coordinates the multi-phase workflow and manages overall execution state
- Location: `app/Services/RunOrchestratorService.php`
- Contains: Complete 8-step workflow logic (fetch → select → plan → validate → execute → verify → commit → PR)
- Depends on: All downstream services, GitHub API, git operations
- Used by: RunCommand, contains overall state machine

**Claude Integration Layer:**
- Purpose: Interfaces with Anthropic API for specific decision-making tasks
- Location: `app/Services/ClaudeSelectorService.php`, `ClaudePlannerService.php`, `ClaudeExecutorService.php`
- Contains: LLM prompt template rendering, JSON response parsing, token usage tracking
- Depends on: GlobalConfig (for API keys and model names), Anthropic Client
- Used by: RunOrchestratorService, Commands

**Policy & Safety Layer:**
- Purpose: Enforces constraints on agent operations to prevent harm
- Location: `app/Support/ExecutorPolicy.php`, `ExecutorRunState.php`
- Contains: Path blocking rules, command allowlisting, file mutation tracking, thrashing detection
- Depends on: PlanResult, repoProfile configuration
- Used by: ClaudeExecutorService (for all tool dispatch decisions)

**External Integration Layer:**
- Purpose: Handles GitHub API and git operations
- Location: `app/Services/GitHubService.php`, `GitService.php`
- Contains: Issue fetching, PR creation, branch/commit management, GH CLI auth
- Depends on: GuzzleHttp client, Symfony Process (for git commands)
- Used by: RunOrchestratorService, prefilter, verification

**Configuration Layer:**
- Purpose: Loads and provides access to global and repo-specific policies
- Location: `app/Config/GlobalConfig.php`, `RepoConfig.php`
- Contains: YAML parsing, defaults, model selection, constraint policies
- Depends on: Symfony YAML parser
- Used by: All service classes, commands

**Data Transfer Layer:**
- Purpose: Immutable data objects representing stage outputs
- Location: `app/Data/`
- Contains: PlanResult, ExecutionResult, RunResult, SelectionResult, PrefilterResult, VerificationResult, ModelUsage
- Depends on: Nothing (leaf classes)
- Used by: All services for passing structured data between layers

**Support/Utilities Layer:**
- Purpose: Cross-cutting helpers for logging, formatting, parsing
- Location: `app/Support/`
- Contains: Cost estimation, prompt serialization, progress formatting, artifact storage, file mutations
- Depends on: Data classes, configuration
- Used by: Services throughout the system

## Data Flow

**Complete Run Workflow:**

1. User invokes `copland run [repo]`
2. RunCommand loads GlobalConfig + RepoConfig, builds repoProfile dictionary
3. RunOrchestratorService.run() orchestrates 8 steps:

   **Step 1: Fetch & Prefilter Issues**
   - GitHubService.getIssues() → fetches with required labels
   - IssuePrefilterService.filter() → rejects by keywords/labels/blocked labels
   - Returns PrefilterResult (accepted[], rejected[])

   **Step 2: Claude Selector**
   - ClaudeSelectorService.selectTask(repoProfile, issues)
   - Loads `resources/prompts/selector.md` template
   - Calls Anthropic API → receives JSON decision + selectedIssueNumber
   - Returns SelectionResult with usage tracking

   **Step 3: Claude Planner**
   - ClaudePlannerService.planTask(repoProfile, selectedIssue)
   - Loads `resources/prompts/planner.md`, injects issue + repo context
   - Calls Anthropic API → receives JSON plan contract
   - Returns PlanResult with: branchName, filesToChange, steps, commands, guardrails, etc.

   **Step 4: Plan Validation**
   - PlanValidatorService.validate(plan, repoProfile)
   - Checks constraints: files within limits, commands allowed, no blocked paths
   - PlanArtifactStore saves validated plan to `.claude/` directory

   **Step 5: Workspace Setup**
   - WorkspaceService.create() → GitService.prepareExecutionBranch()
   - Creates/checks out feature branch from base branch
   - Returns workspace path for execution

   **Step 6: Claude Executor (Agentic Loop)**
   - ClaudeExecutorService.executeWithPolicy(workspace, plan, policy)
   - Loop until `stopReason === 'end_turn'` or policy violation:
     - Build tools schema (read_file, write_file, replace_in_file, run_command, list_directory)
     - Call Anthropic API with contract + history
     - For each tool_use block:
       - Dispatch via ExecutorPolicy.assertToolPathAllowed() / assertCommandAllowed()
       - Execute: read/write files, run commands, list directories
       - Collect results, append to message history
     - ExecutorRunState tracks: pending reads, write count, command count, directory explorations
     - Abort on thrashing: malformed writes (2+), no progress (5+ rounds), directory spam (6+ calls)
   - Returns ExecutionResult: success flag, tool log, token counts

   **Step 7: Verification**
   - VerificationService.verify(repoProfile, workspace, plan, executionResult)
   - Validates actual changes: file count, line count, blocked paths
   - Returns VerificationResult (passed/failures)

   **Step 8: Commit, Push, Create PR**
   - GitService.commit() → git add/commit with agent message
   - GitService.push() → push feature branch
   - GitHubService.createDraftPr() → opens draft PR
   - GitHubService.commentOnIssue() → posts success comment
   - Returns RunResult with prUrl, status, all usage data

**State Management:**
- ExecutorRunState maintains in-memory state during execution: pending file reads, tool counts, error history
- RunProgressSnapshot captures intermediate usage metrics during long-running executor phase
- RunOrchestratorService.log accumulates detailed step-by-step log entries
- No persistent state between runs (all ephemeral)

## Key Abstractions

**PlanResult Contract:**
- Purpose: Immutable specification for what executor should do
- Location: `app/Data/PlanResult.php`
- Properties: branchName, filesToChange, filesToRead, steps, commandsToRun, testsToUpdate, successCriteria, guardrails, prTitle, prBody, maxFilesChanged, maxLinesChanged
- Pattern: Read-only data transfer object passed to executor and validator

**ExecutorPolicy:**
- Purpose: Runtime guard rails for tool execution
- Location: `app/Support/ExecutorPolicy.php`
- Enforces: path normalization, blocked path checking, command allowlisting
- Throws: PolicyViolationException on violations
- Used by: ClaudeExecutorService on every tool dispatch

**ExecutorRunState:**
- Purpose: Tracks execution progress for thrashing detection
- Location: `app/Support/ExecutorRunState.php`
- Monitors: pending planned reads, write attempts, command execution, directory list calls, malformed calls
- Detects: loops (repeated malformed writes), stalling (no progress), exploration spam
- Used by: ClaudeExecutorService during agentic loop

**Claude Service Trio:**
- ClaudeSelectorService: Picks which issue to work on from candidates
- ClaudePlannerService: Creates work plan contract for executor
- ClaudeExecutorService: Executes multi-round agentic loop with tools
- All three: Load YAML templates, call Anthropic API, parse JSON, track token usage

## Entry Points

**`copland run` Command:**
- Location: `app/Commands/RunCommand.php`
- Triggers: CLI invocation with optional repo argument
- Responsibilities: Load config, instantiate orchestrator, run complete pipeline, display usage stats and result

**`copland plan` Command:**
- Location: `app/Commands/PlanCommand.php`
- Triggers: CLI invocation to preview plan without executing
- Responsibilities: Fetch issues, run selector and planner, validate plan, save artifact, display plan details

**`copland issues` Command:**
- Location: `app/Commands/IssuesCommand.php`
- Triggers: CLI invocation to inspect candidate issues
- Responsibilities: Fetch issues, prefilter, display accepted/rejected with reasons

**`copland status` Command:**
- Location: `app/Commands/StatusCommand.php`
- Triggers: CLI invocation
- Responsibilities: Display basic status info

## Error Handling

**Strategy:** Exception-driven, caught at command/orchestrator level

**Patterns:**

- PolicyViolationException: Raised by ExecutorPolicy when tool constraints violated
  - Caught in ClaudeExecutorService.dispatchTool() → treated as tool error → returned to Claude
  - Blocks unsafe operations (absolute paths, escaping workspace, blocked paths, unlisted commands)

- RuntimeException: Raised by config/parsing failures
  - Propagates up to command → displays error and exits
  - Examples: missing HOME, invalid JSON from Claude, git command failures

- GuzzleException: Raised by GitHub API calls
  - Caught at service level (GitHubService.requestJson())
  - Converted to RuntimeException with context

- Throwable in ClaudeExecutorService: Catches all tool errors
  - Logs error to toolCallLog (first 200 chars)
  - Marks as isError: true
  - Returns formatted error message to Claude in next round
  - Executor learns and corrects course

## Cross-Cutting Concerns

**Logging:**
- RunOrchestratorService.log[] accumulates all 8-step progress entries
- ClaudeExecutorService.toolCallLog[] records each tool call with input/outcome/error flag
- No persistent logging framework (output to stdout via progressCallback)

**Validation:**
- PlanValidatorService: Checks planner output against repo policies
- VerificationService: Checks actual git changes against planner's estimates
- ExecutorPolicy: Checks every tool call against workspace rules

**Authentication:**
- GitHub: Via `gh cli` (GitHubService.token() runs `gh auth token`)
- Anthropic: Via CLAUDE_API_KEY in GlobalConfig YAML
- Both stored in home directory config files

**Token Usage Tracking:**
- AnthropicCostEstimator: Computes input/output cost from token counts
- ModelUsage data objects: Attached to each stage result (selector, planner, executor)
- RunResult aggregates all three for final cost display

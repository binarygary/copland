# Phase 6: Multi-Repo Runner - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Enable Copland to process multiple repositories in a single `run` command invocation.

Currently, `RunCommand` handles exactly one repository (either passed as an argument or detected from the current working directory). This phase adds a `repos` list to the global configuration (`~/.copland.yml`) and updates the `run` command to iterate through this list when no specific repo is provided.

Crucially, each repository run must be isolated so that a failure in one (e.g., API error, no issues found) does not prevent the next from starting.

</domain>

<decisions>
## Implementation Decisions

### Configuration Schema
- **D-01:** Add a `repos` key to the root of `~/.copland.yml`. It will be a list of repo slugs (owner/repo) or objects including paths.
- **D-02:** Initially support a simple list of slugs:
  ```yaml
  repos:
    - owner/repo1
    - owner/repo2
  ```
- **D-03:** `GlobalConfig::repos()` already exists and returns an array; it just needs to be populated and used.

### Execution Logic
- **D-04:** If `copland run` is called without an argument, it checks `GlobalConfig::repos()`.
- **D-05:** If `repos` is not empty, it iterates through each repo slug.
- **D-06:** For each repo, it must resolve the local path. This implies that for multi-repo runs, we either:
    - Expect all repos to be subdirectories of a common "projects" folder (not ideal).
    - Add a path mapping to the config (better).
    - Search for the repo in a configured base directory (complex).
- **Decision:** For Wave 1, we will allow the config to specify objects with paths, or fallback to assuming the repo is already checked out at a path relative to the config or in a known structure. 
- **Refined Decision (D-07):** Update `GlobalConfig::repos()` to support both strings and objects. Objects can include a `path`.
  ```yaml
  repos:
    - slug: owner/repo1
      path: /Users/user/projects/repo1
    - owner/repo2 # Assumes current directory if slug matches
  ```
- **D-08:** `RunCommand` loop will wrap the core orchestration logic. It will catch `Throwable` per-repo to ensure continuity.

### Path Resolution
- **D-09:** `CurrentRepoGuardService::assertMatches()` is currently too strict for multi-repo runs (it forces you to be *in* the directory). 
- **D-10:** We need a way to run the orchestrator against a path *without* necessarily being `cd`'d into it, OR the `RunCommand` should `chdir()` into the repo path before starting the run.
- **Decision:** `RunCommand` will `chdir()` into the resolved path for each repo in the loop.

### Success/Failure Reporting
- **D-11:** The console output should clearly separate repo runs with headers and summarize the total outcome at the end.
- **D-12:** Run logs (Phase 3) already include a `repo` field, so sequential runs will naturally produce distinct log entries.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 6 success criteria: iterate `repos` list, sequential execution, error isolation, distinct log entries.

### Existing Code (direct edit targets)
- `app/Config/GlobalConfig.php` — Update default config template and `repos()` helper.
- `app/Commands/RunCommand.php` — Refactor `handle()` to support looping.
- `app/Services/CurrentRepoGuardService.php` — Adjust or bypass strict checks for multi-repo mode.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `RunOrchestratorService` is already repo-agnostic in its `run()` method; it just needs the right `repo` string and `repoProfile`.
- `RepoConfig` takes a path in its constructor, making it easy to load config for a different directory.

### Established Patterns
- `RunCommand` uses a `ProgressReporter` for high-level steps.
- `RunOrchestratorService` produces a `RunResult` which can be collected and summarized.

</code_context>

<specifics>
## Specific Ideas

- Refactor `RunCommand::handle()` to move the single-run logic into a private `runRepo()` method.
- Use `chdir()` inside `runRepo()` to ensure the `git` and `gh` commands run in the correct context.
- Update `GlobalConfig::ensureExists()` to include a commented-out example of the `repos` list.

</specifics>

<deferred>
## Deferred Ideas

- Parallel execution (Phase 6 is explicitly sequential per roadmap).
- Automatic "discovery" of repos on disk (stick to explicit config for now).
</deferred>

---

*Phase: 06-multi-repo-runner*
*Context gathered: 2026-04-03*

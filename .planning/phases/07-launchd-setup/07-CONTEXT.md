# Phase 7: Launchd Setup - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Add a `copland setup` command that installs a working macOS `launchd` job so Copland can run nightly without manual cron configuration.

This phase covers the CLI command, plist generation, explicit environment setup for `HOME`, and the minimal install/load flow needed to register the job successfully with `launchctl`. It does not add Linux scheduling, multi-user deployment, notifications, or a general scheduler abstraction.

</domain>

<decisions>
## Implementation Decisions

### Command Surface
- **D-01:** Add a new top-level `setup` command under `app/Commands`, following the existing Laravel Zero command pattern used by `run`, `issues`, and `status`.
- **D-02:** `copland setup` is an installer command, not a background daemon or scheduler loop. It should create/update the plist and then load or reload it with `launchctl`.
- **D-03:** Keep the initial command UX simple: install for the current user only, with a single nightly schedule and no attempt to manage system-wide LaunchDaemons.

### Plist Location and Identity
- **D-04:** Write the plist to `~/Library/LaunchAgents/` because this is a per-user automation tool and the roadmap explicitly targets a local-machine workflow.
- **D-05:** The plist label should be stable and Copland-specific rather than derived from the repository path at runtime. Use a single-user job identity so one nightly job covers all configured repos.
- **D-06:** The plist should execute the local `copland` binary from the current project checkout rather than requiring a globally installed executable during this phase.

### Runtime Environment
- **D-07:** Set `HOME` explicitly inside the plist environment so `GlobalConfig` and other HOME-based paths resolve correctly even when `launchd` provides a minimal shell environment.
- **D-08:** Run the job from the Copland project root (or explicitly set the working directory) so the local `copland` binary and Composer autoloading work consistently.
- **D-09:** The launched command should execute `copland run` with no repo argument so it uses the Phase 6 multi-repo flow.

### Scheduling Defaults
- **D-10:** Use `StartCalendarInterval` in the plist, matching the roadmap requirement and avoiding ad hoc sleep loops.
- **D-11:** Default to one nightly run at a configurable time rather than multiple daily runs in Phase 7. The broader backlog-clearing strategy can evolve later once launchd installation is proven.
- **D-12:** Time configuration should live in the setup command surface or generated plist inputs, not in per-repo config.

### Install / Reload Behavior
- **D-13:** `copland setup` should be idempotent: rerunning it updates the plist contents and reloads the job instead of failing when the plist already exists.
- **D-14:** Prefer an unload-if-present / load sequence with `launchctl` so the command can safely refresh an existing job definition.
- **D-15:** The command should print the installed plist path, label, and next-step verification guidance so the morning automation path is inspectable.

### Logging and Output
- **D-16:** Capture stdout/stderr from the launchd job into explicit files under the user's home directory so failures are debuggable without Console.app.
- **D-17:** Keep the run log source of truth as `~/.copland/logs/runs.jsonl`; launchd log files are only for scheduler/bootstrap diagnostics.

### the agent's Discretion
- Exact plist label string and log file names, as long as they are stable, Copland-specific, and user-scoped.
- Whether the setup command accepts flags like `--hour` / `--minute` or a single time string, as long as the resulting plist clearly maps to one nightly `StartCalendarInterval`.
- Whether the command shells out to `launchctl bootstrap`, `load`, or the most compatible current equivalent, as long as it successfully registers the job on modern macOS and can refresh existing installs.

</decisions>

<canonical_refs>
## Canonical References

### Requirements
- `.planning/ROADMAP.md` — Phase 7 success criteria: install plist, explicit HOME, nightly `StartCalendarInterval`, successful `launchctl` registration.
- `.planning/REQUIREMENTS.md` — `SCHED-03` is the governing requirement for this phase.

### Existing Code (direct edit targets)
- `app/Commands/` — new `SetupCommand` will be auto-discovered here.
- `app/Support/HomeDirectory.php` — existing HOME resolution behavior the plist must support explicitly.
- `app/Commands/RunCommand.php` — current zero-argument run path now executes all configured repos and is the command the plist should invoke.
- `app/Config/GlobalConfig.php` — existing user-scoped config location under `~/.copland.yml` must remain compatible with launchd execution.

### Existing Patterns
- `config/commands.php` — commands are auto-discovered from `app/Commands`; no manual registration path is needed for a new command class.
- `copland` — local project entrypoint the plist can call directly from the repo checkout.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `HomeDirectory::resolve()` already encapsulates the fallback logic that Phase 7 must preserve by setting `HOME` explicitly in launchd.
- `RunCommand` already handles the multi-repo overnight flow needed for the scheduled job.
- Existing command classes use lightweight inline service construction and `ProgressReporter`, which is the likely pattern for `SetupCommand`.

### Established Patterns
- Commands are top-level CLI entrypoints with concise signatures and direct console messaging.
- Global configuration is user-scoped under the home directory, which aligns with a user LaunchAgent rather than a system daemon.
- Copland’s overnight behavior remains “one invocation, one pass”; scheduling should trigger that existing entrypoint rather than invent a new orchestration layer.

### Integration Points
- The new command will need filesystem writes for `~/Library/LaunchAgents` and shell execution of `launchctl`.
- The generated plist must reference both the Copland checkout path and the explicit HOME directory.
- Phase 6’s repo iteration means the setup command does not need repo-selection logic of its own.

</code_context>

<specifics>
## Specific Ideas

- [auto] Use a per-user LaunchAgent, not a system LaunchDaemon.
- [auto] Generate one stable plist for the whole Copland installation, not one plist per repo.
- [auto] Point launchd at the local `copland` script in this checkout and run `copland run` with no repo argument.
- [auto] Include explicit stdout/stderr log file paths under `~/.copland/logs/launchd/` or an equivalent user-scoped diagnostic directory.
- [auto] Keep the initial schedule to one nightly run; planner can choose the exact default time surface.

</specifics>

<deferred>
## Deferred Ideas

- Multiple runs per night or backlog-clearing cadence tuning beyond one nightly job.
- Linux `cron` / `systemd` support.
- System-wide installation under `/Library/LaunchDaemons`.
- UI or guided onboarding beyond the CLI command output.

</deferred>

---

*Phase: 07-launchd-setup*
*Context gathered: 2026-04-03*

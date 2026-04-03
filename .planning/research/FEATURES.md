# Feature Landscape

**Domain:** Autonomous overnight GitHub issue resolver CLI
**Researched:** 2026-04-02
**Confidence:** HIGH (grounded in codebase analysis + domain knowledge; WebSearch unavailable — findings from
training knowledge, marked where applicable)

---

## Context: What Already Exists

Copland already implements the hard parts: issue selection, planning, git worktree isolation, policy enforcement,
draft PR creation, per-repo config, SIGINT cost reporting, and token usage tracking. This milestone is about
making it reliable enough to leave running overnight and trustworthy enough to review each morning.

The current logging model is plain text strings accumulated in `RunOrchestratorService.log[]` and printed via
`progressCallback`. The current cost display is per-stage token counts plus estimated USD printed after the run.
There is no persistent log file. There is no multi-repo runner. There is no cron setup.

---

## Table Stakes

Features an overnight agent must have to be trustworthy. Missing these means you can't tell if it worked,
what it spent, or whether it ran at all.

| Feature | Why Expected | Complexity | Current State |
|---------|--------------|------------|---------------|
| Persistent run log to file | Cron output is lost if not redirected. Morning review requires a file. | Low | Not present — logs go to stdout only |
| Run start/end timestamps in log | Essential for answering "when did this run?" | Trivial | Not present |
| Outcome summary at top of log | Morning review should not require reading 80 lines to find pass/fail | Low | Not present |
| Cost-per-run in log | Baseline for detecting cost regressions | Low | Printed to stdout, not persisted |
| Failure reason in log | Distinguish transient API failure vs policy violation vs bad plan | Trivial | `failureReason` exists in RunResult, not in log file |
| Multi-repo sequential execution | Single cron entry that runs all configured repos | Medium | Not present — single repo per invocation |
| macOS launchd plist (not cron) | cron is deprecated on macOS; `launchctl` is the correct mechanism | Low | Not present |
| HOME env var resolution for cron | `$_SERVER['HOME']` is unset under launchd — breaks GlobalConfig and PlanArtifactStore | Low | Known bug in CONCERNS.md, not fixed |
| API retry on transient errors | Overnight runs must not die on a 429 or 5xx | Low | Known issue in CONCERNS.md, not fixed |

---

## Differentiators

Features that make Copland excellent rather than just functional. These add value without being blockers.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Per-issue cost breakdown in log | Enables answering "was this issue worth automating?" over time | Low | Data exists in ModelUsage objects; just needs formatting into log |
| Cache savings surfaced in cost display | Shows actual vs would-have-paid, rewards prompt caching investment | Low | Needs cache token tracking once caching is wired |
| Run index / nightly summary line | One-line digest per repo: "2 runs: 1 PR opened, 1 skipped — $0.08" | Low | Useful when cron runs multiple times per night |
| Log rotation (keep last N runs) | Prevents unbounded disk use from nightly files | Trivial | Implement alongside log file creation |
| Stale worktree cleanup in log | Surfaces orphaned worktrees from previous failures | Low | CONCERNS.md flags this as a scaling issue |
| Executor round count in summary | Signals whether the agent worked efficiently or struggled | Trivial | `toolCallCount` already in ExecutionResult |
| Per-repo time-in-executor in log | Surfaces slow repos that need prompt caching or model downgrades | Trivial | `durationSeconds` already in ExecutionResult |

---

## Anti-Features

Things to deliberately NOT build in this milestone. Each has a concrete reason.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| Web dashboard or log viewer | Adds infrastructure complexity to a CLI-only personal tool. PROJECT.md explicitly calls it out of scope. | `tail -f` the log file, or `cat ~/.copland/runs/nightly.log` |
| Parallel multi-repo execution | Tempting but wrong: shared git state and API rate limits make parallel runs risky. Sequential is predictable. | Implement sequential multi-repo with a `repos:` list in global config |
| Slack / webhook notifications | Notification fatigue and added dependencies. The morning log review workflow is the right model for overnight runs. | Persist a clean log file; review on wake |
| Issue re-queuing on failure | An agent that silently retries failed issues creates duplicate PR risk and obscures real problems. | Log the failure, leave the issue labeled, let the human decide |
| Log shipping / remote storage | Complexity without benefit for a personal tool. | Local file is sufficient |
| Structured JSON log for machine consumption | Over-engineering for a single user. Human-readable structured text is the right balance. | Use consistent key: value line format, not JSON |
| Interactive cron setup wizard | A one-time copy-paste of a plist is faster than building a wizard that handles edge cases. | Document the launchd plist in README; provide `copland cron:install` as a stretch goal only |
| Run resumption / checkpointing | Complex, adds serialization surface area, not needed at current scale (most runs are 30-60s). PROJECT.md documents this as a scaling path, not a current requirement. | Fix retry/backoff instead; that covers 90% of failure cases |

---

## Feature: Structured Run Log

### What Good Overnight Agent Logs Look Like

The mental model is an asynchronous "report for morning review." You wake up, run `cat ~/.copland/logs/nightly.log`
(or check a per-run file), and in 30 seconds you know: what ran, whether it worked, what it cost, why anything failed.

**Confidence:** HIGH — derived from first principles and the existing codebase structure.

### Required Fields in a Run Log Entry

A well-formed run log record for Copland should contain:

```
[2026-04-02 02:14:07] RUN START  repo=owner/repo
[2026-04-02 02:14:07] STEP 1/8   Fetching issues (12 found, 3 accepted after prefilter)
[2026-04-02 02:14:08] STEP 2/8   Selector: selected issue #42 "Add dark mode toggle"
[2026-04-02 02:14:09] STEP 3/8   Planner: accepted (3 files, 87 lines)
[2026-04-02 02:14:09] STEP 4/8   Plan validated OK
[2026-04-02 02:14:10] STEP 5/8   Branch feature/dark-mode-toggle created
[2026-04-02 02:14:10] STEP 6/8   Executor: 9 rounds, 23 tool calls, 61s
[2026-04-02 02:14:12] STEP 7/8   Verification: passed (3 files, 84 lines)
[2026-04-02 02:14:14] STEP 8/8   PR #117 opened: https://github.com/owner/repo/pull/117
[2026-04-02 02:14:14] COST       selector=$0.0001 planner=$0.0021 executor=$0.0187 total=$0.0209
[2026-04-02 02:14:14] RUN END    status=succeeded duration=67s
```

For a failure:
```
[2026-04-02 03:02:11] RUN START  repo=owner/repo
[2026-04-02 03:02:11] STEP 1/8   Fetching issues (12 found, 3 accepted after prefilter)
[2026-04-02 03:02:12] STEP 2/8   Selector: selected issue #55 "Refactor auth middleware"
[2026-04-02 03:02:13] STEP 3/8   Planner: accepted
[2026-04-02 03:02:13] STEP 4/8   Plan validation FAILED: blocked path src/Auth accessed
[2026-04-02 03:02:13] COST       selector=$0.0001 planner=$0.0019 total=$0.0020
[2026-04-02 03:02:13] RUN END    status=failed reason="blocked path src/Auth accessed" duration=2s
```

### Log Format Recommendations

**Format:** Human-readable structured text. One line per event. Bracketed ISO timestamp prefix. ALL CAPS event
type token. Key=value pairs for machine-parseable fields.

**Not JSON.** JSON logs require a parser to read. This is a personal tool reviewed by a human. The key=value
format is grep-friendly (`grep "status=failed" ~/.copland/logs/nightly.log`) without being verbose.

**Not unstructured prose.** The current log format ("      Selector decision: skip_all — no suitable issues")
is not grep-friendly, has inconsistent indentation, and mixes step context with outcome.

**File layout:**

```
~/.copland/logs/
  nightly.log         # appended to on every run (all repos, all runs)
  runs/
    owner__repo/
      2026-04-02T02-14-07.log   # per-run file for deep inspection
```

Append to `nightly.log` for morning review. Write a per-run file for debugging. Rotate nightly.log to keep
last 30 days (or last N lines, ~100 lines/run x 10 runs = 1000 lines — easily manageable).

### Critical Fields

| Field | Where | Why |
|-------|-------|-----|
| Timestamp (ISO, local TZ) | Every line | Correlate with other system events |
| Event type token | Every line | `grep RUN_END nightly.log` |
| Repo identifier | RUN START/END | Multi-repo disambiguates entries |
| Issue number + title | STEP 2 | First thing you want to know in morning review |
| Stage decision outcomes | Each step | "why did it stop at step 4?" |
| Executor rounds + tool calls | STEP 6 | Signals thrashing vs clean run |
| Per-stage cost | COST line | Baseline for cost regression detection |
| Total cost | COST line | The number you actually care about |
| Status + failure reason | RUN END | The headline |
| Duration | RUN END | Watch for slow runs |

### What NOT to Log

- Full tool call inputs/outputs (that's the `.claude/` artifact, not the run log)
- Raw API request/response bodies
- Internal PHP stack traces (log message + exception type only)
- Every executor round's intermediate state (log the summary, not each round)

---

## Feature: Cost-Per-Run Surfacing

### Current State

Copland already calculates cost correctly. `AnthropicCostEstimator` computes per-model USD estimates.
`ModelUsage` tracks input/output tokens per stage. `RunCommand.renderUsage()` prints them to stdout.

What's missing:

1. Cost is not persisted — it's lost when the terminal closes or cron swallows stdout
2. Cost is not in the run log — it's only in the final stdout block
3. No total-across-runs accounting — no way to see "I spent $0.87 this week"
4. Cache savings are not visible — once prompt caching is added, the cost display should show it

### How to Surface Cost Usefully

**In CLI output (interactive runs):** The current format is already good. Keep it:
```
Usage:
  - Selector:  1,204 input,  143 output, $0.0001 est.
  - Planner:   8,912 input,  621 output, $0.0021 est.
  - Executor: 47,381 input, 2,847 output, $0.0187 est.
  - Total:    57,497 input, 3,611 output, $0.0209 est.
  - Executor elapsed: 61s
```

One improvement: add a "cached tokens" line once prompt caching is active. Anthropic's API returns
`cache_read_input_tokens` and `cache_creation_input_tokens` separately. Surface them:
```
  - Cache savings: 38,200 tokens read from cache (~$0.0115 saved)
```

**Confidence:** MEDIUM — Anthropic API does return cache token breakdown as of mid-2024; verify field names
against current SDK version before implementing.

**In overnight log (cron runs):** A single COST line at run end, as shown above. The format
`selector=$X planner=$X executor=$X total=$X` is scannable in `nightly.log`.

**Weekly/monthly totals:** Out of scope for this milestone. The log file is sufficient to derive totals manually.
Don't build accounting infrastructure for a personal tool.

### Anti-pattern: Cost Alarmism

Do not add cost warnings like "This run cost $0.08 — consider reducing scope." The user decided the scope.
Surface the number, let the human interpret it. Warnings create noise and don't respect user judgment.

---

## Feature: Multi-Repo Sequential Runs

### Design

A `repos:` list in `~/.copland.yml`:

```yaml
repos:
  - owner/repo-a
  - owner/repo-b
  - owner/repo-c
```

`copland run` with no argument iterates this list in order, running one issue per repo per invocation.

**Sequential, not parallel.** Each run modifies git state in the target repo's worktree. Parallel runs
targeting the same base branch would require coordination. Sequential is simpler and sufficient — cron
handles parallelism over time, not within a single invocation.

**Fail-and-continue.** If repo-a fails (API error, plan validation failure), log the failure and continue
to repo-b. Do not abort the entire run. This is the most important behavioral requirement for overnight use.
**Confidence:** HIGH — this is standard practice for batch CLI tools.

**Per-repo log entries.** The `nightly.log` already needs the repo identifier on each line (see above).
Multi-repo makes this mandatory.

---

## Feature: macOS Cron Setup

### cron vs launchd

**Use launchd, not cron.** On macOS, `cron` works but is not the native mechanism. launchd is what Apple
uses for all scheduled tasks, handles sleep/wake correctly, and persists across reboots. More importantly:

- cron on macOS does not set `HOME` — this breaks `GlobalConfig` and `PlanArtifactStore` which use `$_SERVER['HOME']`
- launchd plist can set `EnvironmentVariables` explicitly, including `HOME` and `PATH`
- launchd `StandardOutPath` / `StandardErrorPath` redirects output to a file automatically — no `>> logfile 2>&1` hacks

**Confidence:** HIGH — macOS launchd behavior is well-established. The HOME env var issue is documented in
CONCERNS.md as a known bug triggered by "cron/systemd environments where HOME is not inherited."

### Standard launchd Plist for PHP CLI on macOS

Location: `~/Library/LaunchAgents/com.user.copland.plist`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.user.copland</string>

    <key>ProgramArguments</key>
    <array>
        <string>/usr/local/bin/php</string>
        <string>/usr/local/bin/copland</string>
        <string>run</string>
    </array>

    <key>StartCalendarInterval</key>
    <array>
        <dict>
            <key>Hour</key>  <integer>2</integer>
            <key>Minute</key> <integer>0</integer>
        </dict>
        <dict>
            <key>Hour</key>  <integer>4</integer>
            <key>Minute</key> <integer>0</integer>
        </dict>
    </array>

    <key>EnvironmentVariables</key>
    <dict>
        <key>HOME</key>
        <string>/Users/USERNAME</string>
        <key>PATH</key>
        <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>
    </dict>

    <key>StandardOutPath</key>
    <string>/Users/USERNAME/.copland/logs/launchd.log</string>

    <key>StandardErrorPath</key>
    <string>/Users/USERNAME/.copland/logs/launchd-error.log</string>

    <key>RunAtLoad</key>
    <false/>
</dict>
</plist>
```

Key decisions:
- `StartCalendarInterval` as array = multiple run times (2am and 4am to clear backlog)
- `EnvironmentVariables.HOME` = fixes the known HOME bug without code change
- `PATH` includes Homebrew prefix (`/opt/homebrew/bin`) for Apple Silicon Macs where `gh` and `php` live
- `StandardOutPath` = captured log for debugging launchd behavior separately from Copland's own `nightly.log`
- `RunAtLoad: false` = don't run on `launchctl load`, only on schedule

**Load command:**
```bash
launchctl load ~/Library/LaunchAgents/com.user.copland.plist
```

**Unload:**
```bash
launchctl unload ~/Library/LaunchAgents/com.user.copland.plist
```

**Manual trigger for testing:**
```bash
launchctl start com.user.copland
```

### PATH Pitfall

The `gh` CLI and PHP may not be in the system PATH under launchd. `EnvironmentVariables.PATH` must include:

- `/opt/homebrew/bin` — Apple Silicon (M1/M2/M3) Homebrew
- `/usr/local/bin` — Intel Mac Homebrew + manually installed binaries
- `/usr/bin:/bin` — system binaries

If `gh` is missing from PATH, `GitHubService.token()` silently returns empty string or throws. This is a
silent failure mode. Copland should validate that `gh` is accessible on startup.

**Confidence:** HIGH — this is standard macOS launchd behavior, well-documented.

### cron as Fallback

If launchd is too unfamiliar, a cron entry with explicit PATH and HOME is a workable fallback:

```cron
0 2,4 * * * HOME=/Users/USERNAME PATH=/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin /usr/local/bin/php /usr/local/bin/copland run >> /Users/USERNAME/.copland/logs/nightly.log 2>&1
```

But this requires: manual log redirection, HOME set inline, and does not handle macOS sleep/wake correctly.
launchd is the right answer.

---

## Feature Dependencies

```
Multi-repo run → Persistent run log (without log file, multi-repo runs are invisible)
Persistent run log → Timestamp format decision (must decide before writing log code)
Cost-per-run → Persistent run log (cost data must be in the log, not just stdout)
launchd setup → HOME env fix in GlobalConfig (otherwise launchd runs fail at config load)
Prompt caching → Cache savings in cost display (cache field only exists after caching is wired)
```

---

## MVP Recommendation for This Milestone

Prioritize in this order:

1. **HOME env var fix** — Prerequisite for cron/launchd to work at all. One-line fix.
2. **Persistent run log** — Table stakes. Define the format, write to `~/.copland/logs/nightly.log` in
   `RunCommand`. This is the primary deliverable for morning reviewability.
3. **Cost line in run log** — Trivially added once the log file exists. Data is already computed.
4. **Multi-repo sequential runner** — The `repos:` list in global config + fail-and-continue loop.
5. **launchd plist** — Document in README. Optionally add `copland cron:install` command.

Defer from this milestone:
- Cache savings display (depends on prompt caching milestone)
- Log rotation (add once log file has existed for a week and you can see actual size)
- `copland cron:install` command (the plist is simple enough to copy-paste)

---

## Sources

- Codebase: `/Users/garykovar/projects/codeable/copland/` (HIGH confidence — direct inspection)
- macOS launchd documentation: https://developer.apple.com/library/archive/documentation/MacOSX/Conceptual/BPSystemStartup/Chapters/ScheduledJobs.html (MEDIUM — training knowledge, verify plist key names against current macOS)
- CONCERNS.md: Documents HOME env var bug and its trigger conditions (HIGH — codebase fact)
- Anthropic cache token API fields: `cache_read_input_tokens`, `cache_creation_input_tokens` in usage response (MEDIUM — training knowledge as of mid-2024, verify in SDK changelog before implementing)

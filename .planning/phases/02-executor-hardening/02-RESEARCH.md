# Phase 2: Executor Hardening - Research

**Researched:** 2026-04-03
**Domain:** Executor safety and plan-contract hardening in a Laravel Zero CLI
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- `read_file` should enforce a line-count cap, not a byte cap, with a default of `300` lines.
- The cap should live in repo-level `.copland.yml`, alongside other executor safety controls.
- Truncated reads must append a clear notice that tells Claude content was cut and more lines exist.
- Write protection must move from free-text `guardrails` parsing to a structured `blocked_write_paths` array in the plan contract.
- Repo-level `blocked_paths` remains the baseline read/list protection; `blocked_write_paths` is an additional plan-scoped write restriction.
- `blocked_write_paths` must be carried end-to-end through planner output, validation, artifact persistence, and executor enforcement.

### the agent's Discretion
- Exact accessor names for repo read-limit config and plan blocked-write paths.
- Exact truncation footer wording and whether it includes omitted-line counts, total-line counts, or both.
- Whether `blocked_write_paths` defaults to an empty array at all contract boundaries.

### Deferred Ideas (OUT OF SCOPE)
- Read-next/chunked browsing behavior.
- Cost-aware/token-aware truncation heuristics.
- Larger executor-focused test suites beyond the current hardening work.

</user_constraints>

<research_summary>
## Summary

Phase 2 is an internal safety-hardening phase, so the “standard approach” is to extend the existing repo-policy and plan-contract seams instead of adding new subsystems. The current codebase already has the right anchors: `RepoConfig` owns repo-scoped executor settings, `PlanResult` is the structured planner/executor contract, `PlanValidatorService` is the normalization gate, and `ExecutorPolicy` owns path enforcement. The safest implementation is to deepen those seams rather than bolt on side channels.

The read-cap requirement is best implemented directly inside `ClaudeExecutorService::readFile()` because that is the only place file contents become model-visible text. A line cap with an appended footer is deterministic, cheap, and satisfies the roadmap requirement that Claude explicitly sees truncation. The cap should be passed via `repoProfile` from `RunCommand`, then folded into the executor’s policy object so all runtime safety knobs are held in one place.

The structured write-protection requirement should be solved by promoting `blocked_write_paths` to a first-class plan field all the way through planner prompt, planner parsing, validation, artifact storage, and executor enforcement. The current free-text guardrail matching is explicitly called out as fragile in `.planning/codebase/CONCERNS.md`; the replacement should make `guardrails` advisory only. Planning should still validate against repo-level blocked paths, but executor-time writes must additionally reject any path listed in the plan’s `blocked_write_paths`.

**Primary recommendation:** Implement Phase 2 by extending existing seams, not inventing new abstractions: `RepoConfig` for read limits, `PlanResult` for `blocked_write_paths`, and `ExecutorPolicy` + `ClaudeExecutorService` for runtime enforcement.
</research_summary>

<standard_stack>
## Standard Stack

No new libraries are required for this phase.

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP 8.2 | existing | Native string/array/file handling for truncation and path checks | Already the app runtime; no dependency needed for line-based truncation |
| Laravel Zero / Illuminate | existing | CLI application structure | Existing command/service/config patterns already solve the orchestration side |
| Symfony YAML | existing | Repo config parsing | `RepoConfig` already uses it for executor controls |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Pest | existing | Guard regression checks around config/policy/artifact updates | Extend existing unit tests when touching config or support classes |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Native line splitting | Dedicated streaming/chunking helper | Overkill for a fixed 300-line cap in a CLI tool |
| Extending existing contract objects | Ad hoc arrays or guardrail annotations | Faster short-term, but repeats the fragility this phase is meant to remove |

**Installation:**
```bash
# No new packages required
```
</standard_stack>

<architecture_patterns>
## Architecture Patterns

### Pattern 1: Repo-scoped executor policy settings
**What:** Runtime safety controls live in repo `.copland.yml` and are exposed through typed `RepoConfig` accessors.
**When to use:** Any executor behavior that should vary by repository, such as command allowlists, blocked paths, round limits, or read caps.
**Example:**
```php
$repoProfile = [
    'blocked_paths' => $repoConfig->blockedPaths(),
    'max_executor_rounds' => $repoConfig->maxExecutorRounds(),
];
```

### Pattern 2: Structured plan contract propagated through `PlanResult`
**What:** Planner JSON is parsed once into `PlanResult`, then reused by validation, artifact storage, and execution.
**When to use:** Any field that execution must trust mechanically, such as `files_to_change`, `commands_to_run`, or new `blocked_write_paths`.
**Example:**
```php
return new PlanResult(
    filesToChange: PlanFieldNormalizer::list($json['files_to_change'] ?? []),
    guardrails: PlanFieldNormalizer::list($json['guardrails'] ?? []),
);
```

### Pattern 3: Centralized policy enforcement with `PolicyViolationException`
**What:** Executor runtime checks are normalized into policy helpers that throw one exception type.
**When to use:** Any path or command rule the executor must enforce consistently.
**Example:**
```php
if (! in_array($normalized, $allowedFilesToChange, true)) {
    throw new PolicyViolationException("Write to '{$normalized}' not listed in files_to_change");
}
```

### Anti-Patterns to Avoid
- **Adding a second executor contract channel:** Do not keep write restrictions half in `guardrails` and half in structured fields; execution should use one authoritative structured source.
- **Configuring read caps globally:** This would break the repo-level policy pattern already established by `RepoConfig`.
- **Silent truncation:** If Claude cannot see truncation, the cost fix undermines correctness because the model cannot tell it has partial context.
</architecture_patterns>

<dont_hand_roll>
## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Config propagation | New config registry or env abstraction | Extend `RepoConfig` and `repoProfile` | Existing code already uses this path for executor-specific controls |
| Plan metadata transport | Sidecar metadata files | Extend `PlanResult` + artifact storage | One contract object is easier to validate and execute correctly |
| Policy enforcement | Inline string heuristics in executor methods | Centralized checks in `ExecutorPolicy` | Avoids repeating normalization and exception formatting logic |

**Key insight:** The codebase already has the correct abstraction boundaries for this phase; the safest work is to deepen them, not bypass them.
</dont_hand_roll>

<common_pitfalls>
## Common Pitfalls

### Pitfall 1: Updating enforcement but not the planner contract
**What goes wrong:** Executor expects `blocked_write_paths`, but planner output, validator, or artifact storage still drops the field.
**Why it happens:** Contract changes span multiple files and the current planner schema is tightly coupled to `PlanResult`.
**How to avoid:** Treat planner prompt, planner parsing, `PlanResult`, validation, artifact persistence, and executor enforcement as one closed loop.
**Warning signs:** `blocked_write_paths` appears in one layer but not in stored artifacts or executor contract JSON.

### Pitfall 2: Truncation that hides the fact of truncation
**What goes wrong:** Large files are cut, but Claude receives no explicit signal that more content exists.
**Why it happens:** Engineers optimize token usage first and forget model awareness.
**How to avoid:** Append a deterministic footer with the configured limit and omitted-line context.
**Warning signs:** `read_file` returns shortened content with no footer or metadata.

### Pitfall 3: Read-limit config added but never passed into executor runtime
**What goes wrong:** `.copland.yml` accepts a new value, but executor continues using an implicit default because `repoProfile` or `ExecutorPolicy` never receives it.
**Why it happens:** `RepoConfig` and executor runtime are connected through `RunCommand`, not direct injection.
**How to avoid:** Update `RunCommand` and the executor policy constructor in the same wave.
**Warning signs:** Config accessors and tests exist, but executor code still hard-codes the limit.
</common_pitfalls>

<code_examples>
## Code Examples

### Existing repo-scoped executor settings
```php
$repoProfile = [
    'allowed_commands' => $repoConfig->allowedCommands(),
    'blocked_paths' => $repoConfig->blockedPaths(),
    'max_executor_rounds' => $repoConfig->maxExecutorRounds(),
];
```

### Existing fragile guardrail enforcement to replace
```php
foreach ($plan->guardrails as $guardrail) {
    if (str_contains(strtolower($guardrail), 'block') && str_contains($guardrail, $normalizedPath)) {
        throw new PolicyViolationException("Write to '{$normalizedPath}' blocked by guardrail: {$guardrail}");
    }
}
```

### Existing unbounded file read to harden
```php
private function readFile(string $workspacePath, string $path, ExecutorPolicy $policy): string
{
    $normalizedPath = $policy->assertToolPathAllowed($path, 'read_file');
    $fullPath = $workspacePath.'/'.ltrim($normalizedPath, '/');

    return file_get_contents($fullPath);
}
```
</code_examples>

<sota_updates>
## State of the Art (2024-2025)

For this phase, the relevant “current approach” is not a new external library but a software-design preference: safety-critical execution rules should be machine-readable and centrally enforced. Free-text parsing is considered fragile because it creates false positives/negatives and cannot be validated statically.

**New tools/patterns to consider:**
- Structured plan metadata for executor constraints rather than natural-language hints.
- Deterministic truncation footers so models can reason about partial file context safely.

**Deprecated/outdated:**
- Free-text enforcement rules for machine-critical write blocking.
</sota_updates>

<open_questions>
## Open Questions

1. **Should read truncation report omitted lines, total lines, or both?**
   - What we know: the footer must tell Claude the file was cut and that more content exists.
   - What's unclear: whether the implementation should compute full line count for richer messaging.
   - Recommendation: include both if cheap during the same split operation; otherwise at minimum include the configured limit and that additional lines were omitted.

2. **Should `PlanFieldNormalizer` gain a dedicated array-of-paths helper?**
   - What we know: current `list()` works for string arrays and simple keyed arrays.
   - What's unclear: whether `blocked_write_paths` needs stronger normalization semantics than other list fields.
   - Recommendation: start with `list()` unless implementation pain or checker feedback shows a dedicated helper is needed.
</open_questions>

<sources>
## Sources

### Primary (HIGH confidence)
- `.planning/phases/02-executor-hardening/02-CONTEXT.md` — locked Phase 2 decisions
- `.planning/ROADMAP.md` — Phase 2 goal and success criteria
- `.planning/REQUIREMENTS.md` — `RELY-02` and `RELY-03`
- `.planning/codebase/CONCERNS.md` — existing fragility analysis for read limits and free-text guardrails
- `app/Services/ClaudeExecutorService.php` — current read/write execution behavior
- `app/Support/ExecutorPolicy.php` — current path and command policy implementation
- `app/Config/RepoConfig.php` — repo-level executor config pattern
- `app/Data/PlanResult.php` — current planner/executor contract shape
- `app/Services/PlanValidatorService.php` — current plan validation surface
- `app/Support/PlanArtifactStore.php` — persisted plan contract artifact shape
- `resources/prompts/planner.md` — planner JSON schema

### Secondary (MEDIUM confidence)
- `.planning/codebase/CONVENTIONS.md` — style and error-handling conventions
- `.planning/codebase/STRUCTURE.md` — service/support/config boundaries
</sources>

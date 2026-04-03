# Phase 1: API Retry/Backoff - Context

**Gathered:** 2026-04-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Introduce `AnthropicApiClient` — a wrapper class that adds exponential backoff retry logic around `$client->messages->create()` calls — and fix HOME directory resolution so the tool works reliably in cron/launchd environments.

All three Claude services get retry coverage. The HOME fix is a prerequisite for Phase 3 log path resolution and is handled here.

New capabilities (multi-repo scheduling, cost tracking, prompt caching) are out of scope for this phase.

</domain>

<decisions>
## Implementation Decisions

### Retry Scope
- **D-01:** All three Claude services get retry coverage: `ClaudeExecutorService`, `ClaudePlannerService`, `ClaudeSelectorService`
- **D-02:** Uniform behavior across services — no per-service retry count differentiation

### Wrapper Design
- **D-03:** `AnthropicApiClient` is injected via constructor into each of the three services, replacing `new Client()` internal instantiation
- **D-04:** Each service signature changes from `__construct(private GlobalConfig $config)` to accept `AnthropicApiClient` as a dependency
- **D-05:** Retry logic (backoff, attempt counting, error classification) lives entirely inside `AnthropicApiClient` — services call it identically to how they currently call `$this->client->messages->create()`

### Retry Behavior
- **D-06:** 429 and 5xx responses are retried; 4xx non-429 responses are not retried and fail immediately
- **D-07:** Exponential backoff: base delay doubles per attempt (e.g., 1s → 2s → 4s)
- **D-08:** Max attempts and base delay configurable in `~/.copland.yml` (see Config Structure)

### Config Structure
- **D-09:** Retry config lives under `api.retry` in `~/.copland.yml`:
  ```yaml
  api:
    retry:
      max_attempts: 3
      base_delay_seconds: 1
  ```
- **D-10:** `GlobalConfig` exposes typed accessors: `retryMaxAttempts(): int` and `retryBaseDelaySeconds(): int` with defaults (3 and 1)

### HOME Directory Fix
- **D-11:** Create `App\Support\HomeDirectory` static helper with a single `resolve(): string` method
- **D-12:** Resolution chain: `$_SERVER['HOME'] ?? getenv('HOME') ?? posix_getpwuid(posix_geteuid())['dir']`
- **D-13:** Both `GlobalConfig` (line 24) and `PlanArtifactStore` (line 88) replace `$_SERVER['HOME']` with `HomeDirectory::resolve()`
- **D-14:** Before using `posix_getpwuid`, check that the posix extension is available (php -m | grep posix); if absent, the chain falls back gracefully to the prior two methods
- **D-15:** Phase 3 will add a third consumer of `HomeDirectory::resolve()` for log path resolution — the helper is intentionally shared

### Claude's Discretion
- Exact class structure of `AnthropicApiClient` (method signatures, whether it implements an interface) — planner decides
- Whether `AnthropicApiClient` wraps the full SDK `Client` or just the `messages->create()` method — planner decides based on what Phase 8 tests will need to mock

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements
- `.planning/REQUIREMENTS.md` — RELY-01 defines acceptance criteria for retry behavior (429/5xx retry, 3 attempts, 4xx no-retry, configurable)

### Existing Code (direct edit targets)
- `app/Services/ClaudeExecutorService.php` — line 91: direct `$this->client->messages->create()` call in agentic loop
- `app/Services/ClaudePlannerService.php` — line 53: direct `$this->client->messages->create()` single call
- `app/Services/ClaudeSelectorService.php` — line 42: direct `$this->client->messages->create()` single call
- `app/Config/GlobalConfig.php` — line 24: `$_SERVER['HOME']` usage; also needs `retryMaxAttempts()` and `retryBaseDelaySeconds()` accessors
- `app/Support/PlanArtifactStore.php` — line 88: `$_SERVER['HOME']` usage

### Codebase Analysis
- `.planning/codebase/CONCERNS.md` — Section "Tech Debt #2" (no retry) and "Known Bugs #2" (HOME resolution) have exact line references and fix approaches

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `App\Config\GlobalConfig` — already provides typed config accessors; pattern to follow for new `retryMaxAttempts()` / `retryBaseDelaySeconds()` methods
- `App\Exceptions\PolicyViolationException` — existing custom exception pattern; `AnthropicApiClient` may throw `RuntimeException` on exhausted retries (follow same convention)

### Established Patterns
- Constructor injection used throughout services — `AnthropicApiClient` injection follows existing pattern exactly
- `$_SERVER['HOME'] ?? null` with exception — both affected files use this identical pattern, both need replacing
- All three Claude services instantiate `new Client(apiKey: ...)` in constructor — uniform change target

### Integration Points
- `AnthropicApiClient` slots between the three service constructors and the SDK `Client` — no other layers affected
- `HomeDirectory::resolve()` slots into `GlobalConfig.__construct()` and `PlanArtifactStore` path resolution — two call sites in Phase 1, one more in Phase 3
- `GlobalConfig` is already injected into all three Claude services — the `retryConfig` can be passed through from there into `AnthropicApiClient`

</code_context>

<specifics>
## Specific Ideas

- The `AnthropicApiClient` wrapper name is specified in the ROADMAP — use that exact class name
- Phase 8 will write Pest tests specifically for `AnthropicApiClient` retry logic — design the class to be testable with a mock HTTP layer (injectable Guzzle client or similar)
- STATE.md note: confirm posix extension is enabled before shipping (`php -m | grep posix`)

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 01-api-retry-backoff*
*Context gathered: 2026-04-03*

# Phase 1: API Retry/Backoff - Research

**Research Date:** 2026-04-03
**Status:** Complete

## SDK Exception Handling

### Anthropic SDK Exception Hierarchy

The `anthropic-ai/sdk` (^0.8.0) is built on top of Guzzle 7.x and uses it for HTTP transport. When HTTP errors occur, the SDK either:
1. **Throws Guzzle exceptions directly** — wrapped by the SDK's underlying HTTP client
2. **Wraps them in SDK-specific exceptions** (version dependent)

**For Phase 1, we must assume:**
- The SDK uses Guzzle's exception handling (verified in codebase: `GuzzleHttp\Exception\GuzzleException`)
- Errors surface as either GuzzleException or an SDK-specific exception subclass
- Status codes are accessed via `$e->getResponse()->getStatusCode()` (same as Guzzle pattern)

**Exception Detection Strategy:**
```php
// Pseudo-code pattern (from GitHubService.php, lines 131-139)
try {
    $response = $this->client->messages->create(...);
} catch (Exception $e) {
    // Check if response exists and has status code
    $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
        ? $e->getResponse()->getStatusCode()
        : null;
    
    // Classify: 429 (retry), 5xx (retry), 4xx non-429 (no retry), network (retry)
    if ($status === 429 || ($status >= 500 && $status < 600)) {
        // RETRY
    } elseif ($status >= 400 && $status < 500) {
        // NO RETRY - fail immediately
    } else {
        // NETWORK ERROR (null status, timeout, etc) - RETRY
    }
}
```

### Known Exception Behavior in Codebase

**GitHubService.php (lines 129-140)** demonstrates the exact pattern to follow:
- Catches `GuzzleException` (umbrella for all Guzzle errors)
- Extracts status code from `$e->getResponse()->getStatusCode()` if response exists
- Falls back to `$e->getMessage()` if no response (network error)
- Wraps in `RuntimeException` with descriptive message

**This same pattern must wrap all three Claude service `messages->create()` calls.**

---

## Current API Call Pattern

### Three Service Constructors (Need Refactoring)

**ClaudeExecutorService.php (lines 26-31):**
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(
        apiKey: $this->config->claudeApiKey(),
    );
    $this->model = $this->config->executorModel();
}
```

**ClaudePlannerService.php (lines 20-25):**
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(
        apiKey: $this->config->claudeApiKey(),
    );
    $this->model = $this->config->plannerModel();
}
```

**ClaudeSelectorService.php (lines 18-23):**
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(
        apiKey: $this->config->claudeApiKey(),
    );
    $this->model = $this->config->selectorModel();
}
```

### Change Required

**Before (current):**
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(...);
}
```

**After (Phase 1):**
```php
public function __construct(
    private GlobalConfig $config,
    private AnthropicApiClient $apiClient  // NEW INJECTION
)
{
    // Do NOT instantiate new Client here
    // Use $apiClient->messages() instead of $this->client->messages
}
```

### API Call Locations (Direct Callsites)

| Service | Line | Pattern | Frequency |
|---------|------|---------|-----------|
| `ClaudeExecutorService.php` | 91 | `$this->client->messages->create(...)` | Every round (up to 12 calls per run) |
| `ClaudePlannerService.php` | 53 | `$this->client->messages->create(...)` | Once per run |
| `ClaudeSelectorService.php` | 43 | `$this->client->messages->create(...)` | Once per run |

**Change Pattern:**
- Replace `$this->client->messages->create(...)` with `$this->apiClient->messages(...)`
- The `AnthropicApiClient` wrapper handles retry/backoff internally
- Services do NOT catch exceptions (let them propagate to `RunOrchestratorService`)

---

## Config Pattern

### Existing GlobalConfig Accessors

**File:** `app/Config/GlobalConfig.php`

**Current Accessor Pattern (lines 74-107):**
```php
public function claudeApiKey(): string
{
    return $this->data['claude_api_key'] ?? '';
}

public function defaultMaxFiles(): int
{
    return $this->data['defaults']['max_files_changed'] ?? 3;
}

public function selectorModel(): string
{
    return $this->data['models']['selector'] ?? 'claude-haiku-4-5';
}
```

**Default YAML (lines 55-67):**
```yaml
claude_api_key: ""

models:
  selector: claude-haiku-4-5
  planner: claude-sonnet-4-6
  executor: claude-sonnet-4-6

defaults:
  max_files_changed: 3
  max_lines_changed: 250
  base_branch: main
```

### Two New Accessors Needed

Add to `GlobalConfig.php`:
```php
public function retryMaxAttempts(): int
{
    return $this->data['api']['retry']['max_attempts'] ?? 3;
}

public function retryBaseDelaySeconds(): int
{
    return $this->data['api']['retry']['base_delay_seconds'] ?? 1;
}
```

**Updated default YAML to add:**
```yaml
api:
  retry:
    max_attempts: 3
    base_delay_seconds: 1
```

**Design Pattern Used:**
- Nested key access with `??` null coalesce
- Type hints (`int`)
- Sensible defaults (3 attempts, 1s base delay)
- No validation (config loading is external concern)

---

## HOME Directory Resolution

### Current Usage (Two Locations)

**GlobalConfig.php (lines 21-27):**
```php
private function resolvePath(): string
{
    $home = $_SERVER['HOME'] ?? null;

    if (! is_string($home) || $home === '') {
        throw new RuntimeException('HOME is not set.');
    }

    $preferred = $home.'/.copland.yml';
    // ...
}
```

**PlanArtifactStore.php (lines 86-95):**
```php
private function homeDirectory(): string
{
    $home = $_SERVER['HOME'] ?? null;

    if (! is_string($home) || $home === '') {
        throw new RuntimeException('HOME is not set.');
    }

    return rtrim($home, '/');
}
```

### Problem

In cron/launchd environments, `$_SERVER['HOME']` is often unset, causing runs to fail immediately. This is documented in CONCERNS.md as **Known Bugs #2**.

### Solution: Create HomeDirectory Helper

**New File:** `app/Support/HomeDirectory.php`

```php
<?php

namespace App\Support;

use RuntimeException;

class HomeDirectory
{
    /**
     * Resolve the home directory using a fallback chain.
     *
     * 1. $_SERVER['HOME'] (shell env set in terminal)
     * 2. getenv('HOME') (PHP-level env)
     * 3. posix_getpwuid(posix_geteuid())['dir'] (filesystem user record)
     *
     * Throws if all methods fail.
     */
    public static function resolve(): string
    {
        // Method 1: $_SERVER['HOME']
        $home = $_SERVER['HOME'] ?? null;
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        // Method 2: getenv('HOME')
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        // Method 3: posix_getpwuid fallback
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pwinfo = posix_getpwuid(posix_geteuid());
            if (is_array($pwinfo) && isset($pwinfo['dir'])) {
                return rtrim($pwinfo['dir'], '/');
            }
        }

        // All methods exhausted
        throw new RuntimeException(
            'Could not resolve HOME directory. Set $HOME or ensure posix extension is available.'
        );
    }
}
```

### Integration Points (Phase 1)

**GlobalConfig.php:** Replace line 23:
```php
// BEFORE:
$home = $_SERVER['HOME'] ?? null;

// AFTER:
$home = HomeDirectory::resolve();
// (remove the null check since resolve() throws on failure)
```

**PlanArtifactStore.php:** Replace lines 86-95:
```php
// BEFORE:
private function homeDirectory(): string
{
    $home = $_SERVER['HOME'] ?? null;
    if (! is_string($home) || $home === '') {
        throw new RuntimeException('HOME is not set.');
    }
    return rtrim($home, '/');
}

// AFTER:
private function homeDirectory(): string
{
    return HomeDirectory::resolve();
}
```

### Posix Extension Check

Before shipping (Phase 1 final), verify posix is available:
```bash
php -m | grep posix
```

If not installed, Phase 1 will still work (fallback to env methods), but document this:
> If posix extension is unavailable, ensure `HOME` is set in your shell environment or systemd/launchd config.

---

## Files to Change

### Core Implementation Files

| File | Lines | Change | Priority |
|------|-------|--------|----------|
| `app/Support/HomeDirectory.php` | NEW | Create helper class with `resolve()` method | P0 |
| `app/Config/GlobalConfig.php` | 16-27, 55-67, 113-114 | Replace `$_SERVER['HOME']` with `HomeDirectory::resolve()`, add retry config accessors, add to default YAML | P0 |
| `app/Support/PlanArtifactStore.php` | 86-95 | Replace `homeDirectory()` private method to use `HomeDirectory::resolve()` | P0 |
| `app/Support/AnthropicApiClient.php` | NEW | Create retry wrapper class with `messages()` method | P0 |
| `app/Services/ClaudeExecutorService.php` | 5, 26-31, 91 | Add `AnthropicApiClient` import, change constructor to inject it, replace `$this->client->messages->create()` with `$this->apiClient->messages(...)` | P0 |
| `app/Services/ClaudePlannerService.php` | 5, 20-25, 53 | Add `AnthropicApiClient` import, change constructor to inject it, replace `$this->client->messages->create()` with `$this->apiClient->messages(...)` | P0 |
| `app/Services/ClaudeSelectorService.php` | 5, 18-23, 43 | Add `AnthropicApiClient` import, change constructor to inject it, replace `$this->client->messages->create()` with `$this->apiClient->messages(...)` | P0 |

### Test Files Affected

| File | Change | Impact |
|------|--------|--------|
| `tests/Feature/ClaudeServicesTest.php` | Must inject `AnthropicApiClient` into service constructors | Required for existing test to pass |
| `tests/Unit/GlobalConfigTest.php` | Add test for `retryMaxAttempts()` and `retryBaseDelaySeconds()` accessors | New test coverage |

### No Changes Required

- `app/Config/RepoConfig.php` — repo-level config (retry policy is global-only)
- `app/Services/RunOrchestratorService.php` — orchestrator doesn't instantiate services directly; depends on service provider or test injection
- `app/Services/GitHubService.php` — separate error handling layer, no Anthropic API integration

---

## Existing Test Infrastructure

### Test Framework

**Framework:** Pest 3.8.4+ (PHPUnit-compatible)
**Structure:** Tests in `/tests/` with `/tests/Unit/` and `/tests/Feature/` subdirectories
**Pattern:** Functions instead of classes (Pest style)

Example (from `tests/Unit/ExecutorPolicyTest.php`):
```php
it('blocks git metadata access', function () {
    $policy = new ExecutorPolicy;
    expect(fn () => $policy->assertToolPathAllowed('.git/HEAD', 'read_file'))
        ->toThrow(PolicyViolationException::class);
});
```

### Mock/Stub Pattern

**Mock HTTP Client Pattern** (from `tests/Feature/GitHubServiceTest.php`):
```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$mock = new MockHandler([
    new Response(200, [], json_encode([...]), JSON_THROW_ON_ERROR),
]);
$handlerStack = HandlerStack::create($mock);
$client = new Client(['handler' => $handlerStack]);
$service = new GitHubService($client, fn (): string => 'test-token');
```

**This pattern can be reused for `AnthropicApiClient` tests in Phase 8.**

### Constructor Injection Pattern

**Current Pattern** (all three Claude services):
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(...);
}
```

**Test Setup** (from `tests/Feature/ClaudeServicesTest.php`):
```php
$config = new GlobalConfig;
$selector = new ClaudeSelectorService($config);
```

**Phase 1 Update Required:**
- Services now accept `AnthropicApiClient` as a second constructor parameter
- Tests must inject a mock/real `AnthropicApiClient` instance

### Available Test Fixtures

- `tests/TestCase.php` — base test class
- `tests/Pest.php` — Pest configuration
- No global fixtures for config or services yet (each test creates its own)

### What Phase 8 Will Need

**Phase 8 (AnthropicApiClient Retry Tests) will be able to:**
1. Use `MockHandler` + `HandlerStack` to simulate API responses (429, 5xx, 4xx, 2xx)
2. Verify retry count and backoff timing using assertions
3. Inject mock `Client` into `AnthropicApiClient` for testability
4. Assert the correct exceptions are thrown for non-retryable errors

**Phase 1 must ensure:**
- `AnthropicApiClient` accepts an injected `Client` (for mocking)
- Constructor signatures of three services support dependency injection
- Retry logic is encapsulated within `AnthropicApiClient`, not in services

---

## Recommended Implementation Approach

### 1. AnthropicApiClient Class Design

**Location:** `app/Support/AnthropicApiClient.php`

**Responsibilities:**
- Wrap Anthropic SDK's `Client` instance
- Implement exponential backoff retry logic
- Classify HTTP errors (429/5xx = retry, 4xx = fail, network = retry)
- Throw `RuntimeException` with descriptive message on exhausted retries

**Method Signature:**
```php
namespace App\Support;

use Anthropic\Client;
use Anthropic\Messages\MessageParam;
use RuntimeException;

class AnthropicApiClient
{
    private int $maxAttempts;
    private int $baseDelaySeconds;

    public function __construct(
        private Client $client,
        int $maxAttempts = 3,
        int $baseDelaySeconds = 1,
    ) {
        $this->maxAttempts = $maxAttempts;
        $this->baseDelaySeconds = $baseDelaySeconds;
    }

    /**
     * Create a message with automatic retry on transient errors.
     *
     * @param string $model
     * @param int $maxTokens
     * @param string|array $system
     * @param array $tools
     * @param array<MessageParam> $messages
     * @return object SDK Message response
     * @throws RuntimeException on non-retryable error or exhausted retries
     */
    public function messages(
        string $model,
        int $maxTokens,
        string|array $system,
        array $tools,
        array $messages,
    ): object {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                return $this->client->messages->create(
                    model: $model,
                    maxTokens: $maxTokens,
                    system: $system,
                    tools: $tools,
                    messages: $messages,
                );
            } catch (Exception $e) {
                $statusCode = $this->extractStatusCode($e);

                // Classify error
                if ($this->isRetryable($statusCode)) {
                    $lastException = $e;

                    if ($attempt < $this->maxAttempts) {
                        $delay = $this->backoffDelaySeconds($attempt);
                        sleep($delay);
                        continue;
                    }
                } else {
                    // Non-retryable: fail immediately
                    throw new RuntimeException(
                        "Anthropic API error: {$statusCode} {$e->getMessage()}",
                        previous: $e,
                    );
                }
            }
        }

        // Exhausted retries
        throw new RuntimeException(
            "Anthropic API error after {$this->maxAttempts} attempts: {$lastException?->getMessage()}",
            previous: $lastException,
        );
    }

    private function extractStatusCode(?Throwable $e): int|string
    {
        if ($e === null) {
            return 'unknown';
        }

        if (
            method_exists($e, 'getResponse') &&
            $e->getResponse() !== null &&
            method_exists($e->getResponse(), 'getStatusCode')
        ) {
            return $e->getResponse()->getStatusCode();
        }

        return 'network_error';
    }

    private function isRetryable(int|string $status): bool
    {
        return $status === 429 ||
               (is_int($status) && $status >= 500 && $status < 600) ||
               $status === 'network_error';
    }

    private function backoffDelaySeconds(int $attempt): int
    {
        return $this->baseDelaySeconds * (2 ** ($attempt - 1));
    }
}
```

### 2. Service Constructor Updates

**Dependency Injection Pattern:**
```php
public function __construct(
    private GlobalConfig $config,
    private AnthropicApiClient $apiClient,
) {
    $this->model = $this->config->executorModel();
}
```

**Where to instantiate AnthropicApiClient:**
- **Option A (Recommended):** Service Provider or container in `app/Providers/AppServiceProvider.php`
- **Option B:** Inline in constructor (if service provider doesn't exist)

**For tests:** Inject mock `AnthropicApiClient` or mock `Client`

### 3. Configuration Flow

```
GlobalConfig loads ~/.copland.yml
    ↓
retryMaxAttempts() → data['api']['retry']['max_attempts'] (default 3)
retryBaseDelaySeconds() → data['api']['retry']['base_delay_seconds'] (default 1)
    ↓
AnthropicApiClient($client, $maxAttempts, $baseDelaySeconds)
    ↓
Injected into each of three Claude services
```

### 4. Error Classification Matrix

| Status | Retryable | Behavior |
|--------|-----------|----------|
| 429 (Rate limit) | Yes | Backoff and retry |
| 500, 502, 503, 504 | Yes | Backoff and retry |
| 400, 401, 403 | No | Fail immediately with error message |
| Network timeout / connection error | Yes | Backoff and retry |
| Unknown (exception, no status) | No | Fail immediately |

---

## Pitfalls / Risks

### 1. SDK Exception Type Unknown (Mitigated)

**Risk:** Anthropic SDK may throw a custom exception type, not GuzzleException.
**Mitigation:** Catch generic `Exception` or `Throwable`, extract status via reflection (`$e->getResponse()`), handle both SDK and Guzzle exceptions.
**Verification:** Phase 1 implementation must test with actual SDK installed (`composer install`).

### 2. sleep() vs usleep() Blocking

**Risk:** Using `sleep()` in loop blocks the entire process; tests will be slow.
**Mitigation:** 
- Bare `sleep()` is fine for production (acceptable delay)
- In tests (Phase 8), mock time or use injectable clock interface if performance becomes issue
- Avoid in tight CLI loops but Phase 1 is only CLI use

### 3. Backoff Calculation Overflow

**Risk:** Exponential backoff with large max_attempts could exceed int limits or wait >1 hour.
**Mitigation:** Max 3 attempts means delays are 1s, 2s, 4s (total 7s max). Safe.

### 4. HOME Resolution Fallback Incomplete

**Risk:** posix extension not installed, all three methods fail, code throws with unclear message.
**Mitigation:** Verify extension availability before shipping. Document fallback priority in HomeDirectory docstring. Error message is explicit about requirements.

### 5. Test Injection Complexity

**Risk:** Three services now have two constructor parameters; tests must inject both.
**Mitigation:** Update `tests/Feature/ClaudeServicesTest.php` to pass `AnthropicApiClient` instance. Can use a mock or real instance.

### 6. Retry Exhaustion Loses Context

**Risk:** After 3 failed attempts, final RuntimeException doesn't show which attempt failed.
**Mitigation:** Log exception message from last attempt in `$lastException`. Include attempt count in error: `"after 3 attempts: {message}"`.

### 7. Config Defaults May Not Match Reality

**Risk:** User sets `max_attempts: 1` and executor fails on first transient error.
**Mitigation:** Document sensible defaults (3 attempts) in `.copland.yml` template and README. Warn in error messages if retry is exhausted.

### 8. Service Provider May Not Exist

**Risk:** Laravel Zero app structure may not have service provider wired up for binding dependencies.
**Mitigation:** Check `app/Providers/AppServiceProvider.php` during implementation. If no DI container, services instantiate `AnthropicApiClient` directly in constructor. Testability handled via constructor injection of mock.

### 9. Guzzle Promise-Based Async Calls

**Risk:** If SDK uses async/promises, `sleep()` blocks incorrectly.
**Mitigation:** Verify SDK uses synchronous `messages->create()` (appears to based on code review; always returns object, not Promise). Phase 8 tests will confirm.

### 10. Retry May Mask Real Bugs

**Risk:** 4xx errors that are actually bugs (malformed request) are retried forever.
**Mitigation:** Strict classification: 429 + 5xx only. 4xx fails fast. No retry on client errors. Separation is clean.

---

## RESEARCH COMPLETE

### Summary of Key Findings

1. **SDK Integration:** Anthropic SDK built on Guzzle; exception handling pattern is established in codebase (GitHubService)
2. **Three Services:** All identical constructor pattern; each instantiates Client; need to inject AnthropicApiClient instead
3. **Retry Logic:** Simple exponential backoff (1s, 2s, 4s); classify 429/5xx as retryable; 4xx non-429 as not retryable
4. **Config Pattern:** GlobalConfig uses null-coalesce with defaults; add two new accessors for retry config under `api.retry` key
5. **HOME Resolution:** Two places use `$_SERVER['HOME']`; create shared HomeDirectory helper with fallback chain
6. **Testing:** Pest framework with MockHandler pattern for HTTP; can be reused for AnthropicApiClient tests in Phase 8
7. **File Count:** 6 files change (3 services + 2 config/support + 1 new), 2 test files update
8. **Risk Level:** Low; follows established patterns in codebase; retry logic is encapsulated; straightforward error classification

### Ready to Plan

This research provides sufficient detail for the planner to:
- Understand the exact exception handling needed
- See the three service constructor signatures that will change
- Understand the config pattern and default values
- Know which files to modify and where
- Understand test infrastructure available
- Identify risks and mitigation strategies

---

*Research completed: 2026-04-03*
*Next: Plan Phase 1 (gsd:plan-phase)*

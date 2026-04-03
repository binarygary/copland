---
phase: 1
wave: 4
depends_on: [wave 3]
files_modified:
  - app/Commands/RunCommand.php
  - app/Commands/PlanCommand.php
  - tests/Feature/ClaudeServicesTest.php
  - tests/Unit/GlobalConfigTest.php
requirements: [RELY-01]
autonomous: true
---

# Plan: Wave 4 — Command Wiring and Test Updates

## Objective

Wire `AnthropicApiClient` construction into `RunCommand` and `PlanCommand`, where the three Claude services are instantiated. Update `tests/Feature/ClaudeServicesTest.php` to pass `AnthropicApiClient` to the service constructors. Add coverage for `retryMaxAttempts()` and `retryBaseDelaySeconds()` to `tests/Unit/GlobalConfigTest.php`. After this wave, all tests pass and Phase 1 is complete.

## must_haves

- `RunCommand::handle()` constructs one `AnthropicApiClient` instance and passes it to all three service constructors
- `PlanCommand::handle()` constructs one `AnthropicApiClient` instance and passes it to both service constructors
- `AnthropicApiClient` is constructed using `GlobalConfig` retry accessors and the Anthropic SDK `Client`
- `tests/Feature/ClaudeServicesTest.php` passes `AnthropicApiClient` when constructing services
- `tests/Unit/GlobalConfigTest.php` asserts `retryMaxAttempts()` returns 3 and `retryBaseDelaySeconds()` returns 1 by default
- All tests pass: `./vendor/bin/pest` exits 0 with no failures

## Tasks

<task id="1.4.1">
<title>Update app/Commands/RunCommand.php — wire AnthropicApiClient</title>
<read_first>
- app/Commands/RunCommand.php (full file — read before editing)
- app/Support/AnthropicApiClient.php (constructor signature: Client, int maxAttempts, int baseDelaySeconds)
- app/Config/GlobalConfig.php (retryMaxAttempts() and retryBaseDelaySeconds() accessors added in wave 1)
</read_first>
<action>
Make three changes to `app/Commands/RunCommand.php`:

**Change 1 — Add imports.** Add these two use statements to the existing import block:
```php
use Anthropic\Anthropic;
use App\Support\AnthropicApiClient;
```
Note: The Anthropic SDK factory method is `Anthropic::client(apiKey: '...')` or `new \Anthropic\Client(apiKey: '...')`. Check whether the codebase uses `new Client(apiKey: ...)` (as seen in the services) or a factory. Based on current service code, use `new \Anthropic\Client(apiKey: $globalConfig->claudeApiKey())` — but use the imported class. Add `use Anthropic\Client;` instead of `Anthropic\Anthropic` if that is what the existing services used.

Actually, use this exact import to match the pattern removed from services:
```php
use Anthropic\Client;
use App\Support\AnthropicApiClient;
```

**Change 2 — Construct AnthropicApiClient.** After the line `$globalConfig = new GlobalConfig;`, add:
```php
$apiClient = new AnthropicApiClient(
    client: new Client(apiKey: $globalConfig->claudeApiKey()),
    maxAttempts: $globalConfig->retryMaxAttempts(),
    baseDelaySeconds: $globalConfig->retryBaseDelaySeconds(),
);
```

**Change 3 — Pass $apiClient to service constructors.** Update the three service instantiations inside the `$orchestrator = new RunOrchestratorService(...)` call:
- `new ClaudeSelectorService($globalConfig)` → `new ClaudeSelectorService($globalConfig, $apiClient)`
- `new ClaudePlannerService($globalConfig)` → `new ClaudePlannerService($globalConfig, $apiClient)`
- `new ClaudeExecutorService($globalConfig)` → `new ClaudeExecutorService($globalConfig, $apiClient)`
</action>
<acceptance_criteria>
- `grep -n "use Anthropic\\\\Client;" app/Commands/RunCommand.php` returns a match
- `grep -n "use App\\\\Support\\\\AnthropicApiClient;" app/Commands/RunCommand.php` returns a match
- `grep -n "new AnthropicApiClient(" app/Commands/RunCommand.php` returns exactly 1 match
- `grep -n "retryMaxAttempts()" app/Commands/RunCommand.php` returns a match
- `grep -n "retryBaseDelaySeconds()" app/Commands/RunCommand.php` returns a match
- `grep -n "new ClaudeSelectorService(\\\$globalConfig, \\\$apiClient)" app/Commands/RunCommand.php` returns a match
- `grep -n "new ClaudePlannerService(\\\$globalConfig, \\\$apiClient)" app/Commands/RunCommand.php` returns a match
- `grep -n "new ClaudeExecutorService(\\\$globalConfig, \\\$apiClient)" app/Commands/RunCommand.php` returns a match
- `php -l app/Commands/RunCommand.php` outputs `No syntax errors detected`
</acceptance_criteria>
</task>

<task id="1.4.2">
<title>Update app/Commands/PlanCommand.php — wire AnthropicApiClient</title>
<read_first>
- app/Commands/PlanCommand.php (full file — read before editing)
- app/Support/AnthropicApiClient.php (constructor signature)
- app/Config/GlobalConfig.php (retryMaxAttempts() and retryBaseDelaySeconds() accessors)
</read_first>
<action>
Make three changes to `app/Commands/PlanCommand.php`:

**Change 1 — Add imports.** Add these two use statements to the existing import block:
```php
use Anthropic\Client;
use App\Support\AnthropicApiClient;
```

**Change 2 — Construct AnthropicApiClient.** After the line `$globalConfig = new GlobalConfig;`, add:
```php
$apiClient = new AnthropicApiClient(
    client: new Client(apiKey: $globalConfig->claudeApiKey()),
    maxAttempts: $globalConfig->retryMaxAttempts(),
    baseDelaySeconds: $globalConfig->retryBaseDelaySeconds(),
);
```

**Change 3 — Pass $apiClient to service constructors.** Update the two service instantiations:
- `$selector = new ClaudeSelectorService($globalConfig);` → `$selector = new ClaudeSelectorService($globalConfig, $apiClient);`
- `$planner = new ClaudePlannerService($globalConfig);` → `$planner = new ClaudePlannerService($globalConfig, $apiClient);`
</action>
<acceptance_criteria>
- `grep -n "use Anthropic\\\\Client;" app/Commands/PlanCommand.php` returns a match
- `grep -n "use App\\\\Support\\\\AnthropicApiClient;" app/Commands/PlanCommand.php` returns a match
- `grep -n "new AnthropicApiClient(" app/Commands/PlanCommand.php` returns exactly 1 match
- `grep -n "retryMaxAttempts()" app/Commands/PlanCommand.php` returns a match
- `grep -n "retryBaseDelaySeconds()" app/Commands/PlanCommand.php` returns a match
- `grep -n "new ClaudeSelectorService(\\\$globalConfig, \\\$apiClient)" app/Commands/PlanCommand.php` returns a match
- `grep -n "new ClaudePlannerService(\\\$globalConfig, \\\$apiClient)" app/Commands/PlanCommand.php` returns a match
- `php -l app/Commands/PlanCommand.php` outputs `No syntax errors detected`
</acceptance_criteria>
</task>

<task id="1.4.3">
<title>Update tests/Feature/ClaudeServicesTest.php — inject AnthropicApiClient</title>
<read_first>
- tests/Feature/ClaudeServicesTest.php (full file — read before editing)
- app/Support/AnthropicApiClient.php (constructor signature)
- app/Services/ClaudeExecutorService.php (updated constructor from wave 3)
- app/Services/ClaudePlannerService.php (updated constructor from wave 3)
- app/Services/ClaudeSelectorService.php (updated constructor from wave 3)
</read_first>
<action>
Update `tests/Feature/ClaudeServicesTest.php` so the existing test constructs an `AnthropicApiClient` and passes it to each service.

The test currently sets up a temp home directory with a fake `claude_api_key: test-key` config. It already has `$config = new GlobalConfig`. The service instantiations need to change from single-arg to two-arg.

Add these imports at the top of the test file (after the opening `<?php`):
```php
use Anthropic\Client;
use App\Support\AnthropicApiClient;
```

After the line `$config = new GlobalConfig;`, add:
```php
$apiClient = new AnthropicApiClient(
    client: new Client(apiKey: $config->claudeApiKey()),
    maxAttempts: $config->retryMaxAttempts(),
    baseDelaySeconds: $config->retryBaseDelaySeconds(),
);
```

Update the three service instantiations:
- `$selector = new ClaudeSelectorService($config);` → `$selector = new ClaudeSelectorService($config, $apiClient);`
- `$planner = new ClaudePlannerService($config);` → `$planner = new ClaudePlannerService($config, $apiClient);`
- `$executor = new ClaudeExecutorService($config);` → `$executor = new ClaudeExecutorService($config, $apiClient);`

The three `expect()` assertions at the end of the test remain unchanged.
</action>
<acceptance_criteria>
- `grep -n "use Anthropic\\\\Client;" tests/Feature/ClaudeServicesTest.php` returns a match
- `grep -n "use App\\\\Support\\\\AnthropicApiClient;" tests/Feature/ClaudeServicesTest.php` returns a match
- `grep -n "new AnthropicApiClient(" tests/Feature/ClaudeServicesTest.php` returns exactly 1 match
- `grep -n "new ClaudeSelectorService(\\\$config, \\\$apiClient)" tests/Feature/ClaudeServicesTest.php` returns a match
- `grep -n "new ClaudePlannerService(\\\$config, \\\$apiClient)" tests/Feature/ClaudeServicesTest.php` returns a match
- `grep -n "new ClaudeExecutorService(\\\$config, \\\$apiClient)" tests/Feature/ClaudeServicesTest.php` returns a match
- `./vendor/bin/pest tests/Feature/ClaudeServicesTest.php` passes with 0 failures
</acceptance_criteria>
</task>

<task id="1.4.4">
<title>Update tests/Unit/GlobalConfigTest.php — add retry accessor assertions</title>
<read_first>
- tests/Unit/GlobalConfigTest.php (full file — read before editing)
- app/Config/GlobalConfig.php (retryMaxAttempts() and retryBaseDelaySeconds() added in wave 1)
</read_first>
<action>
Add a new test to `tests/Unit/GlobalConfigTest.php` that verifies the two new retry config accessors return their defaults when no `api.retry` block is present in the config file.

Append this test after the existing test in the file:

```php
it('returns default retry config values when api.retry is not in config', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-global-config-retry-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $config = new GlobalConfig;

    expect($config->retryMaxAttempts())->toBe(3);
    expect($config->retryBaseDelaySeconds())->toBe(1);

    $_SERVER['HOME'] = $originalHome;
});
```
</action>
<acceptance_criteria>
- `grep -n "retryMaxAttempts" tests/Unit/GlobalConfigTest.php` returns a match
- `grep -n "retryBaseDelaySeconds" tests/Unit/GlobalConfigTest.php` returns a match
- `grep -n "toBe(3)" tests/Unit/GlobalConfigTest.php` returns a match
- `grep -n "toBe(1)" tests/Unit/GlobalConfigTest.php` returns a match
- `./vendor/bin/pest tests/Unit/GlobalConfigTest.php` passes with 0 failures
</acceptance_criteria>
</task>

## Verification

Run the complete test suite to confirm Phase 1 is fully wired and all tests pass:
```bash
./vendor/bin/pest
```

Expected output: All tests pass, 0 failures, 0 errors.

Also verify PHP linting on all modified command files:
```bash
php -l app/Commands/RunCommand.php && php -l app/Commands/PlanCommand.php
```

Both must output `No syntax errors detected`.

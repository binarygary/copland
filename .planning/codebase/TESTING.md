# Testing Patterns

**Analysis Date:** 2026-04-02

## Test Framework

**Runner:**
- Framework: Pest PHP v3.8.4 or v4.1.2 (configured in `composer.json`)
- Config: `phpunit.xml.dist` bootstraps vendor autoloader
- Assertion library: Pest's built-in `expect()` API

**Run Commands:**
```bash
./vendor/bin/pest              # Run all tests
./vendor/bin/pest --watch      # Watch mode (if supported)
./vendor/bin/pest --coverage   # Run with coverage report
```

## Test File Organization

**Location:**
- Unit tests: `tests/Unit/`
- Feature tests: `tests/Feature/`
- Both co-located with phpunit suite configuration in `phpunit.xml.dist`

**Naming:**
- Test files use class name with `Test` suffix: `GitServiceTest.php`, `ExecutorPolicyTest.php`
- Test functions use descriptive names describing the scenario

**Structure:**
```
tests/
├── Feature/
│   ├── ClaudeServicesTest.php
│   ├── GitHubServiceTest.php
│   └── InspireCommandTest.php
├── Unit/
│   ├── AnthropicCostEstimatorTest.php
│   ├── AnthropicMessageSerializerTest.php
│   ├── CurrentRepoGuardServiceTest.php
│   ├── ExecutorPolicyTest.php
│   ├── ExecutorProgressFormatterTest.php
│   ├── ExecutorRunStateTest.php
│   ├── ExampleTest.php
│   ├── FileMutationHelperTest.php
│   ├── GitServiceTest.php
│   ├── GlobalConfigTest.php
│   ├── IssueFileHintExtractorTest.php
│   ├── PlanArtifactStoreTest.php
│   ├── PlanFieldNormalizerTest.php
│   ├── ProgressReporterTest.php
│   └── RepoConfigTest.php
├── Pest.php          # Global test setup and expectations
└── TestCase.php      # Base test case class
```

## Test Structure

**Suite Organization:**
```php
<?php

it('descriptive test name', function () {
    // Arrange - setup
    $service = new GitService(function (array $command, string $cwd) use (&$calls) {
        $calls[] = $command;
        return match ($command) {
            ['git', 'fetch', 'origin'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command'),
        };
    });

    // Act
    $service->fetch('/tmp/repo');

    // Assert
    expect($calls)->toBe([['git', 'fetch', 'origin']]);
});
```

**Patterns:**
- Closure-based test structure (Pest style, not class-based)
- Each test is a closure passed to `it()` function
- File-level namespace not required for Pest tests
- `uses()` function binds test case class: `uses(Tests\TestCase::class)->in('Feature');` (in `Pest.php`)

## Mocking

**Framework:** Mockery v1.6.12 (in `require-dev`)

**Patterns:**
Constructor injection of test doubles:
```php
// From app/Services/GitService.php line 10
public function __construct(private $runner = null) {}

// In test (GitServiceTest.php):
$git = new GitService(function (array $command, string $cwd) use (&$calls): array {
    $calls[] = $command;

    return match ($command) {
        ['git', 'status', '--porcelain'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
        ['git', 'fetch', 'origin'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
        default => throw new RuntimeException('Unexpected command: ' . implode(' ', $command)),
    };
});
```

**Pattern Details:**
- Services accept optional test runner as parameter
- Test passes callable that intercepts calls and records them in `&$calls` reference variable
- Uses `match()` expression to return different responses based on command arguments
- Throws on unexpected commands to catch test logic errors

**What to Mock:**
- External processes/commands (via constructor injection)
- File system operations (create temp directories in tests)
- Environment variables (set/restore `$_SERVER['HOME']`)

**What NOT to Mock:**
- Local business logic (test actual service implementations)
- Data classes (use real data objects)
- Small helper utilities

## Fixtures and Factories

**Test Data:**
```php
// From GlobalConfigTest.php - create temporary home directory
$originalHome = $_SERVER['HOME'] ?? null;
$home = sys_get_temp_dir() . '/copland-global-config-' . uniqid();

mkdir($home, 0755, true);
$_SERVER['HOME'] = $home;

// Test code
$config = new GlobalConfig();

// Cleanup
$_SERVER['HOME'] = $originalHome;
```

**Location:**
- Test fixtures created inline within test closures
- Use `sys_get_temp_dir()` for temporary files
- Use `uniqid()` to avoid test conflicts
- Always restore original state (environment variables, file system)

**Example from `PlanArtifactStoreTest.php` (lines 6-44):**
```php
it('writes the latest plan artifact under the global copland runs directory', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir() . '/copland-plan-artifacts-' . uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $store = new PlanArtifactStore();
    $path = $store->save('Lone-Rock-Point/lrpbot', [
        'number' => 193,
        'title' => 'Fix repo toggle',
        'html_url' => 'https://github.com/Lone-Rock-Point/lrpbot/issues/193',
    ], new PlanResult(...), ['command not allowed']);

    expect($path)->toBe($home . '/.copland/runs/Lone-Rock-Point__lrpbot/last-plan.json');
    expect(file_exists($path))->toBeTrue();

    $json = json_decode((string) file_get_contents($path), true);
    expect($json['repo'])->toBe('Lone-Rock-Point/lrpbot');

    $_SERVER['HOME'] = $originalHome;
});
```

## Coverage

**Requirements:** Not enforced (no coverage target configured)

**View Coverage:**
```bash
./vendor/bin/pest --coverage
# Output in default format (browser-friendly HTML or terminal report)
```

**Note:** `phpunit.xml.dist` includes `<source>` element pointing to `./app` for coverage analysis, but no minimum coverage threshold is specified.

## Test Types

**Unit Tests:**
- Location: `tests/Unit/`
- Scope: Single class or function in isolation
- Dependencies: Mocked or injected
- Examples: `GitServiceTest.php`, `ExecutorPolicyTest.php`, `GlobalConfigTest.php`
- Pattern: Test each public method with different inputs and outcomes

**Feature Tests:**
- Location: `tests/Feature/`
- Scope: Integration of multiple services/components
- Dependencies: Real implementations (not mocked)
- Examples: `ClaudeServicesTest.php`, `GitHubServiceTest.php`
- Pattern: Test higher-level workflows and command execution

**E2E Tests:**
- Framework: Not used
- Approach: Feature tests provide integration coverage

## Common Patterns

**Async Testing:**
Not applicable (PHP is synchronous; no async/await patterns)

**Error Testing:**
```php
// From ExecutorPolicyTest.php (lines 6-14)
it('blocks git metadata access and path traversal', function () {
    $policy = new ExecutorPolicy();

    expect(fn () => $policy->assertToolPathAllowed('.git/HEAD', 'read_file'))
        ->toThrow(PolicyViolationException::class);

    expect(fn () => $policy->assertToolPathAllowed('../.env', 'read_file'))
        ->toThrow(PolicyViolationException::class);
});
```

**Exception Testing:**
- Use closure syntax: `expect(fn () => $service->method())->toThrow(ExceptionClass::class)`
- Test exception message: `expect(fn () => ...)->toThrow(ExceptionClass::class, 'message text')`

**Return Value Testing:**
```php
// From AnthropicCostEstimatorTest.php (lines 6-13)
it('estimates sonnet usage cost from input and output tokens', function () {
    $usage = AnthropicCostEstimator::forModel('claude-sonnet-4-6', 1000, 500);

    expect($usage)->toBeInstanceOf(ModelUsage::class);
    expect($usage->inputTokens)->toBe(1000);
    expect($usage->outputTokens)->toBe(500);
    expect($usage->estimatedCostUsd)->toBe(0.0105);
});
```

**Setup Pattern - Test Data:**
```php
// From GitServiceTest.php - setup mock runner that captures calls
$calls = [];
$git = new GitService(function (array $command, string $cwd) use (&$calls): array {
    $calls[] = $command;
    return match ($command) { ... };
});

// Test the service
$git->prepareExecutionBranch('/tmp/repo', 'main', 'agent/test-branch');

// Assert all expected commands were called in order
expect($calls)->toBe([
    ['git', 'status', '--porcelain'],
    ['git', 'fetch', 'origin'],
    ...
]);
```

## Custom Expectations

**Global Expectations (in `tests/Pest.php`):**
```php
expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
```

**Usage:**
- Custom expectations can be defined for project-specific assertion patterns
- Example: `expect($value)->toBeOne()`
- Currently only `toBeOne` is defined in the codebase as a placeholder

## Test Isolation

**Patterns:**
- Preserve original environment state with: `$original = $_SERVER['HOME'] ?? null;`
- Restore after test: `$_SERVER['HOME'] = $original;`
- Use unique directory names: `uniqid()` to avoid test collisions
- Clean temporary files created during tests (implicitly via temp directory)

## Configuration

**Pest Configuration:**
- `tests/Pest.php`: Global setup file that configures test suite
- Binds Feature tests to `Tests\TestCase` class
- Defines custom expectations

**PHPUnit Configuration:**
- File: `phpunit.xml.dist`
- Defines two test suites: "Feature" and "Unit"
- Bootstrap: `vendor/autoload.php`
- Colors: enabled for terminal output
- Coverage source: `./app` directory

---

*Testing analysis: 2026-04-02*

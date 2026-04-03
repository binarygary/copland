# Coding Conventions

**Analysis Date:** 2026-04-02

## Naming Patterns

**Files:**
- PascalCase for class files: `GitService.php`, `GlobalConfig.php`, `ExecutorPolicy.php`
- PascalCase for data classes: `SelectionResult.php`, `ModelUsage.php`
- PascalCase for commands: `PlanCommand.php`, `RunCommand.php`
- Test files follow class name with `Test` suffix: `GitServiceTest.php`, `ExecutorPolicyTest.php`

**Functions:**
- camelCase for public methods: `prepareExecutionBranch()`, `hasUncommittedChanges()`, `assertToolPathAllowed()`
- camelCase for private methods: `branchExists()`, `normalizePath()`, `ensureExists()`
- Snake_case for command-line methods: `handle()` is entry point (Laravel convention)

**Variables:**
- camelCase for local variables: `$repoPath`, `$errorMessage`, `$selectedIssue`
- camelCase for private properties: `$data`, `$path`, `$runner`, `$blockedPaths`
- PascalCase for class properties in data classes (readonly): `public readonly string $decision`

**Types:**
- Explicit nullable types: `?int`, `?string`, `?ModelUsage`
- Array types: `array` for generic arrays
- Union types when appropriate: `string|int`

## Code Style

**Formatting:**
- Tool: EditorConfig + Laravel Pint
- Indentation: 4 spaces
- Line endings: LF
- Final newline: required
- Trailing whitespace: trimmed
- YAML indentation: 2 spaces (override in `.editorconfig`)

**Linting:**
- Tool: Laravel Pint
- Configuration: Default Laravel Pint rules with project dependency `"laravel/pint": "^1.25.1"`
- Run with: `./vendor/bin/pint`

## Import Organization

**Order:**
1. Built-in PHP extensions: `use RuntimeException;`
2. Third-party libraries: `use Symfony\Component\Process\Process;`, `use Symfony\Component\Yaml\Yaml;`
3. Application namespaces: `use App\Services\GitService;`, `use App\Config\GlobalConfig;`

**Path Aliases:**
- PSR-4 autoload namespace `App\` maps to `/app` directory
- Tests namespace `Tests\` maps to `/tests` directory

**Example from `app/Services/GitService.php`:**
```php
<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class GitService { ... }
```

## Error Handling

**Patterns:**
- Throw `RuntimeException` for operational failures: `throw new RuntimeException('Working tree is dirty...')`
- Throw custom `PolicyViolationException` for policy/security violations: `throw new PolicyViolationException("Tool '{$tool}' cannot access blocked path...")`
- Check exit codes explicitly: `if ($result['exitCode'] !== 0) { throw new RuntimeException(...) }`
- All thrown exceptions include descriptive context

**Example from `app/Services/GitService.php` (lines 109-116):**
```php
private function run(array $command, string $cwd, string $errorMessage): void
{
    $result = $this->execute($command, $cwd);

    if ($result['exitCode'] !== 0) {
        throw new RuntimeException("{$errorMessage}: " . $result['stderr']);
    }
}
```

## Logging

**Framework:** `console` output via Laravel commands and helper functions

**Patterns:**
- Use `$this->line()` in commands for standard output
- Use `$this->error()` for error messages
- Use `$this->detail()` wrapper in progress reporter for indented details
- No logging framework dependency; direct output to stdout/stderr

**Example from `app/Commands/PlanCommand.php` (lines 28-30):**
```php
$this->line($progress->step('Resolve repository'));
$repo = (new CurrentRepoGuardService())->resolve($this->argument('repo'));
$this->line($progress->detail("Using repo {$repo}"));
```

## Comments

**When to Comment:**
- Class-level documentation for public classes and services
- Document non-obvious algorithm choices or workarounds
- Document security/policy implications
- Explain WHY, not WHAT (code shows what it does)

**JSDoc/PHPDoc:**
- Not consistently used for simple getter methods
- Constructor property promotion used instead: `public function __construct(private $runner = null) {}`
- Type hints provide sufficient documentation in most cases

**Example from `app/Config/GlobalConfig.php` (lines 20-26):**
```php
private function resolvePath(): string
{
    $home = $_SERVER['HOME'] ?? null;

    if (! is_string($home) || $home === '') {
        throw new RuntimeException('HOME is not set.');
    }
    // No comment needed - intent is clear from code
```

## Function Design

**Size:** Most functions are 5-30 lines; larger functions (50+ lines) factor out private helpers

**Parameters:**
- Use constructor injection for dependencies: `public function __construct(private array $blockedPaths = [])`
- Pass primitive types and arrays directly
- Use null-coalescing for optional config: `$this->data['key'] ?? 'default'`

**Return Values:**
- Explicit return types: `void`, `string`, `array`, `int`, `bool`
- Return early pattern to reduce nesting: `if (...) { return; }`

**Example from `app/Services/GitService.php` (lines 35-46):**
```php
public function changedFiles(string $workspacePath): array
{
    $process = new Process(['git', 'diff', '--name-only', 'HEAD'], $workspacePath);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException("git diff failed: " . $process->getErrorOutput());
    }

    $output = trim($process->getOutput());
    return $output !== '' ? explode("\n", $output) : [];
}
```

## Module Design

**Exports:**
- Classes define single responsibility: `GitService` handles git operations, `GlobalConfig` handles config
- Public methods are intentionally exposed; private methods are implementation details
- Data classes use readonly properties: `public readonly string $decision`

**Barrel Files:**
- Not used; direct imports preferred

**Example from `app/Data/SelectionResult.php`:**
```php
<?php

namespace App\Data;

class SelectionResult
{
    public function __construct(
        public readonly string $decision,
        public readonly ?int $selectedIssueNumber,
        public readonly string $reason,
        public readonly array $rejections,
        public readonly ?ModelUsage $usage = null,
    ) {}
}
```

## Service Layer Pattern

**Location:** `app/Services/` contains orchestration and external integration services

**Characteristics:**
- Services accept dependencies via constructor
- Testable via constructor injection of callable mocks (see `GitService` with `$runner` parameter)
- Return data objects from `App\Data\` namespace
- Throw descriptive exceptions for failures

**Example from `app/Services/GitService.php` (lines 8-10):**
```php
public function __construct(private $runner = null) {}

// Allows injecting test mock: new GitService(function(array $command, string $cwd) { ... })
```

## Config Classes Pattern

**Location:** `app/Config/` contains configuration loaders

**Characteristics:**
- Load and cache configuration in constructor
- Public getter methods return typed values with defaults
- Ensure files exist before reading (create default if missing)

**Example from `app/Config/GlobalConfig.php` (lines 13-18):**
```php
public function __construct()
{
    $this->path = $this->resolvePath();
    $this->ensureExists();
    $this->data = Yaml::parseFile($this->path) ?? [];
}
```

---

*Convention analysis: 2026-04-02*

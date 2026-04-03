---
phase: 1
wave: 1
depends_on: []
files_modified:
  - app/Support/HomeDirectory.php
  - app/Config/GlobalConfig.php
  - app/Support/PlanArtifactStore.php
requirements: [RELY-01]
autonomous: true
---

# Plan: Wave 1 — Foundation (HomeDirectory + GlobalConfig + PlanArtifactStore)

## Objective

Create the `HomeDirectory` static helper and use it to fix HOME resolution in both `GlobalConfig` and `PlanArtifactStore`. Also add the two new retry config accessors to `GlobalConfig` and update its default YAML template. These three changes are fully independent of each other and of the retry wrapper built in Wave 2.

## must_haves

- `HomeDirectory::resolve()` exists and implements the three-step fallback chain
- `GlobalConfig::resolvePath()` no longer references `$_SERVER['HOME']` directly
- `PlanArtifactStore::homeDirectory()` no longer references `$_SERVER['HOME']` directly
- `GlobalConfig::retryMaxAttempts()` returns `int` with default 3
- `GlobalConfig::retryBaseDelaySeconds()` returns `int` with default 1
- The default YAML written by `GlobalConfig::ensureExists()` includes the `api.retry` block
- All existing tests continue to pass after changes

## Tasks

<task id="1.1.1">
<title>Create app/Support/HomeDirectory.php</title>
<read_first>
- app/Support/PlanArtifactStore.php (pattern reference for existing homeDirectory() logic)
- app/Config/GlobalConfig.php (pattern reference for existing HOME usage)
</read_first>
<action>
Create a new file `app/Support/HomeDirectory.php` with namespace `App\Support`. The class has a single public static method `resolve(): string` that implements this exact chain:

1. Check `$_SERVER['HOME']` — if it is a non-empty string, return it rtrimmed of `/`
2. Check `getenv('HOME')` — if it returns a non-empty string, return it rtrimmed of `/`
3. Check `function_exists('posix_geteuid') && function_exists('posix_getpwuid')` — if both exist, call `posix_getpwuid(posix_geteuid())`, and if the result is an array with a non-empty `dir` key, return it rtrimmed of `/`
4. If all three methods fail, throw `new RuntimeException('Could not resolve HOME directory. Set $HOME or ensure posix extension is available.')`

Use `use RuntimeException;` at the top. The class has a docblock comment above `resolve()` that documents the three-step chain.

Full file structure:
```php
<?php

namespace App\Support;

use RuntimeException;

class HomeDirectory
{
    /**
     * Resolve the home directory using a fallback chain:
     * 1. $_SERVER['HOME']
     * 2. getenv('HOME')
     * 3. posix_getpwuid(posix_geteuid())['dir']
     *
     * @throws RuntimeException if all methods fail
     */
    public static function resolve(): string
    {
        $home = $_SERVER['HOME'] ?? null;
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pwinfo = posix_getpwuid(posix_geteuid());
            if (is_array($pwinfo) && isset($pwinfo['dir']) && $pwinfo['dir'] !== '') {
                return rtrim($pwinfo['dir'], '/');
            }
        }

        throw new RuntimeException(
            'Could not resolve HOME directory. Set $HOME or ensure posix extension is available.'
        );
    }
}
```
</action>
<acceptance_criteria>
- File exists at `app/Support/HomeDirectory.php`
- `grep -n "namespace App\\\\Support" app/Support/HomeDirectory.php` returns line 3
- `grep -n "public static function resolve(): string" app/Support/HomeDirectory.php` returns a match
- `grep -n "getenv('HOME')" app/Support/HomeDirectory.php` returns a match
- `grep -n "posix_getpwuid" app/Support/HomeDirectory.php` returns a match
- `grep -n "Could not resolve HOME directory" app/Support/HomeDirectory.php` returns a match
- `grep -n "rtrim" app/Support/HomeDirectory.php` returns at least 3 matches (one per successful path)
</acceptance_criteria>
</task>

<task id="1.1.2">
<title>Update app/Config/GlobalConfig.php — fix HOME resolution and add retry accessors</title>
<read_first>
- app/Config/GlobalConfig.php (current file — read before any edit)
- app/Support/HomeDirectory.php (the helper created in task 1.1.1)
</read_first>
<action>
Make three changes to `app/Config/GlobalConfig.php`:

**Change 1 — Add import.** Add `use App\Support\HomeDirectory;` to the use block at the top, after `use RuntimeException;`.

**Change 2 — Fix resolvePath().** Replace the HOME resolution block in `resolvePath()` (lines 22-27 in current file):
```php
$home = $_SERVER['HOME'] ?? null;

if (! is_string($home) || $home === '') {
    throw new RuntimeException('HOME is not set.');
}
```
With:
```php
$home = HomeDirectory::resolve();
```
The variables `$preferred` and `$legacy` and the rest of the method body remain unchanged. Remove the now-redundant `RuntimeException` throw from this method (the exception is thrown by `HomeDirectory::resolve()` if needed).

**Change 3 — Add retry accessors and update default YAML.** Add these two new public methods after `repos()`:
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

Also update the `$default` heredoc string inside `ensureExists()` to append the new `api.retry` block. The updated heredoc content must be:
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

api:
  retry:
    max_attempts: 3
    base_delay_seconds: 1
```
</action>
<acceptance_criteria>
- `grep -n "use App\\\\Support\\\\HomeDirectory;" app/Config/GlobalConfig.php` returns a match
- `grep -n "HomeDirectory::resolve()" app/Config/GlobalConfig.php` returns exactly 1 match
- `grep -n "\$_SERVER\['HOME'\]" app/Config/GlobalConfig.php` returns 0 matches
- `grep -n "public function retryMaxAttempts(): int" app/Config/GlobalConfig.php` returns a match
- `grep -n "public function retryBaseDelaySeconds(): int" app/Config/GlobalConfig.php` returns a match
- `grep -n "'api'\]\['retry'\]\['max_attempts'\]" app/Config/GlobalConfig.php` returns a match
- `grep -n "'api'\]\['retry'\]\['base_delay_seconds'\]" app/Config/GlobalConfig.php` returns a match
- `grep -n "api:" app/Config/GlobalConfig.php` returns a match (in the heredoc)
- `grep -n "max_attempts: 3" app/Config/GlobalConfig.php` returns a match (in the heredoc)
- `./vendor/bin/pest tests/Unit/GlobalConfigTest.php` passes without errors
</acceptance_criteria>
</task>

<task id="1.1.3">
<title>Update app/Support/PlanArtifactStore.php — fix HOME resolution</title>
<read_first>
- app/Support/PlanArtifactStore.php (current file — read before any edit)
- app/Support/HomeDirectory.php (the helper created in task 1.1.1)
</read_first>
<action>
Make two changes to `app/Support/PlanArtifactStore.php`:

**Change 1 — Add import.** Add `use App\Support\HomeDirectory;` to the use block at the top. Current imports are `App\Data\PlanResult` and `RuntimeException`.

**Change 2 — Replace homeDirectory() method body.** Replace the entire body of the private `homeDirectory(): string` method (lines 86-95 in current file):
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
With:
```php
private function homeDirectory(): string
{
    return HomeDirectory::resolve();
}
```

The method signature and visibility remain the same. The `use RuntimeException;` import can remain (it may still be used by other methods via `throw new RuntimeException` calls elsewhere in the file).
</action>
<acceptance_criteria>
- `grep -n "use App\\\\Support\\\\HomeDirectory;" app/Support/PlanArtifactStore.php` returns a match
- `grep -n "HomeDirectory::resolve()" app/Support/PlanArtifactStore.php` returns exactly 1 match
- `grep -n "\$_SERVER\['HOME'\]" app/Support/PlanArtifactStore.php` returns 0 matches
- `grep -c "private function homeDirectory" app/Support/PlanArtifactStore.php` returns 1 (method still exists)
- `./vendor/bin/pest tests/Unit/PlanArtifactStoreTest.php` passes without errors
</acceptance_criteria>
</task>

## Verification

Run the full test suite after all three tasks complete:
```bash
./vendor/bin/pest
```

All tests must pass. No new test failures introduced by this wave.

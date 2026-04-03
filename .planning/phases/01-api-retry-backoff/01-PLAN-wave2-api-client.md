---
phase: 1
wave: 2
depends_on: [wave 1]
files_modified:
  - app/Support/AnthropicApiClient.php
requirements: [RELY-01]
autonomous: true
---

# Plan: Wave 2 — AnthropicApiClient Retry Wrapper

## Objective

Create `app/Support/AnthropicApiClient.php` — the central retry wrapper that all three Claude services will use. This class wraps the Anthropic SDK `Client`, exposes a single `messages()` method that replicates the `$client->messages->create()` call signature, and implements exponential backoff retry for 429 and 5xx responses while failing immediately on non-retryable 4xx errors. Wave 3 services inject this class via constructor.

## must_haves

- `AnthropicApiClient` exists at `app/Support/AnthropicApiClient.php`
- Constructor accepts `Anthropic\Client $client`, `int $maxAttempts = 3`, `int $baseDelaySeconds = 1`
- `messages()` method signature matches what the three services pass to `$client->messages->create()`
- 429 and 5xx status codes cause retry with exponential backoff; attempt 1 waits `$baseDelay * 2^0`, attempt 2 waits `$baseDelay * 2^1`, etc.
- 4xx non-429 status codes throw `RuntimeException` immediately without retrying
- Network errors (no response / null status) are retried
- After exhausting all attempts, `RuntimeException` is thrown with message including attempt count
- The injected `Client` is stored as a property, enabling Phase 8 mock injection

## Tasks

<task id="1.2.1">
<title>Create app/Support/AnthropicApiClient.php</title>
<read_first>
- app/Services/ClaudeExecutorService.php (lines 91-97: exact call signature for messages->create used by executor)
- app/Services/ClaudePlannerService.php (lines 53-59: exact call signature for messages->create used by planner)
- app/Services/ClaudeSelectorService.php (lines 43-49: exact call signature for messages->create used by selector)
- app/Services/GitHubService.php (exception handling pattern to follow — catch with status code extraction)
</read_first>
<action>
Create `app/Support/AnthropicApiClient.php` with namespace `App\Support`.

The class must:

1. **Constructor** — accept three parameters:
   - `private Client $client` (type `Anthropic\Client`)
   - `private int $maxAttempts = 3`
   - `private int $baseDelaySeconds = 1`

2. **`messages()` public method** — signature:
   ```php
   public function messages(
       string $model,
       int $maxTokens,
       string|array $system,
       array $tools,
       array $messages,
   ): object
   ```
   The method passes all parameters through to `$this->client->messages->create()` using named arguments: `model:`, `maxTokens:`, `system:`, `tools:`, `messages:`. It returns the SDK response object.

   NOTE: `ClaudePlannerService` and `ClaudeSelectorService` do NOT pass `system` or `tools`. The `messages()` method must handle this. Use default values of `string $system = ''` and `array $tools = []`, and only pass these named arguments when they are non-empty:
   - If `$system === ''` AND `$tools === []`, call with only `model:`, `maxTokens:`, `messages:`
   - If `$system !== ''` AND `$tools === []`, call with `model:`, `maxTokens:`, `system:`, `messages:`
   - If `$tools !== []`, always pass `tools:`
   - If `$system !== ''`, always pass `system:`

   Actually, the simplest approach: build `$params` array and unpack it. Call:
   ```php
   $params = ['model' => $model, 'maxTokens' => $maxTokens, 'messages' => $messages];
   if ($system !== '') {
       $params['system'] = $system;
   }
   if ($tools !== []) {
       $params['tools'] = $tools;
   }
   return $this->client->messages->create(...$params);
   ```

3. **Retry loop** — wrap the create call in a while loop up to `$this->maxAttempts` attempts:
   ```
   $attempt = 0;
   $lastException = null;
   while ($attempt < $this->maxAttempts) {
       $attempt++;
       try {
           // call create, return on success
       } catch (\Throwable $e) {
           $status = $this->extractStatusCode($e);
           if ($this->isRetryable($status)) {
               $lastException = $e;
               if ($attempt < $this->maxAttempts) {
                   sleep($this->backoffDelay($attempt));
               }
           } else {
               throw new RuntimeException(
                   "Anthropic API error (HTTP {$status}): " . $e->getMessage(),
                   0,
                   $e
               );
           }
       }
   }
   throw new RuntimeException(
       "Anthropic API failed after {$this->maxAttempts} attempts: " . ($lastException?->getMessage() ?? 'unknown error'),
       0,
       $lastException
   );
   ```

4. **`extractStatusCode()` private method** — signature `private function extractStatusCode(\Throwable $e): int|string`:
   - If `method_exists($e, 'getResponse')` and `$e->getResponse() !== null` and `method_exists($e->getResponse(), 'getStatusCode')`, return `(int) $e->getResponse()->getStatusCode()`
   - Otherwise return `'network_error'`

5. **`isRetryable()` private method** — signature `private function isRetryable(int|string $status): bool`:
   - Return true if `$status === 429`
   - Return true if `is_int($status) && $status >= 500 && $status < 600`
   - Return true if `$status === 'network_error'`
   - Return false for all other values

6. **`backoffDelay()` private method** — signature `private function backoffDelay(int $attempt): int`:
   - Return `$this->baseDelaySeconds * (2 ** ($attempt - 1))`
   - For attempt 1: `baseDelay * 1` (no wait before attempt 1, this is the delay AFTER attempt 1 fails)
   - For attempt 2: `baseDelay * 2`

Required imports:
```php
use Anthropic\Client;
use RuntimeException;
```
</action>
<acceptance_criteria>
- File exists at `app/Support/AnthropicApiClient.php`
- `grep -n "namespace App\\\\Support;" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "use Anthropic\\\\Client;" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "public function __construct" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "private Client \\\$client" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "private int \\\$maxAttempts" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "private int \\\$baseDelaySeconds" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "public function messages(" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "private function extractStatusCode" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "private function isRetryable" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "private function backoffDelay" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "network_error" app/Support/AnthropicApiClient.php` returns at least 2 matches (one in extractStatusCode, one in isRetryable)
- `grep -n "after \\\$this->maxAttempts attempts" app/Support/AnthropicApiClient.php` returns a match
- `grep -n "sleep(" app/Support/AnthropicApiClient.php` returns a match
- `./vendor/bin/pest` passes (no syntax errors introduced)
</acceptance_criteria>
</task>

## Verification

After creating the file, verify PHP syntax is valid:
```bash
php -l app/Support/AnthropicApiClient.php
```

Run full test suite:
```bash
./vendor/bin/pest
```

All existing tests must continue to pass (Wave 2 adds a new file with no callsites yet — nothing breaks).

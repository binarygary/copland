# Phase 14: LlmClient Contracts — Plan

**Goal:** Copland's three Claude services depend on a `LlmClient` interface, not `AnthropicApiClient` directly, and all existing tests pass unchanged
**Status:** Ready for execution
**Planned:** 2026-04-08
**Requirements:** PROV-01, PROV-02

---

## Pre-flight Checklist

- [ ] Read `.planning/phases/14-llmclient-contracts/14-CONTEXT.md`
- [ ] Read `.planning/STATE.md`
- [ ] Verify all files to modify exist:
  - `app/Support/AnthropicApiClient.php`
  - `app/Services/ClaudeSelectorService.php`
  - `app/Services/ClaudePlannerService.php`
  - `app/Services/ClaudeExecutorService.php`
  - `app/Providers/AppServiceProvider.php`
- [ ] Run `php artisan test` and confirm all tests pass (green baseline)

---

## Context

### Existing signatures to understand before editing

**`AnthropicApiClient::messages()` current signature:**
```php
public function messages(
    string $model,
    int $maxTokens,
    string|array $system = '',
    array $tools = [],
    array $messages = [],
): object
```
The `$system` parameter currently accepts either a plain string or an array of Anthropic SDK `TextBlockParam` objects (used by the executor). After this phase, `complete()` replaces this externally; `messages()` is kept private/internal.

**Response object shape the services currently access (Anthropic SDK stdClass):**
```
$response->content      // array of stdClass objects with ->type, ->text, ->name, ->id, ->input
$response->stopReason   // string: 'end_turn', 'tool_use', 'max_tokens'
$response->usage->inputTokens
$response->usage->outputTokens
$response->usage->cacheCreationInputTokens
$response->usage->cacheReadInputTokens
```
After this phase, services call `complete()` which returns `LlmResponse`. Content becomes plain assoc arrays (D-01). Services switch to array access `$block['type']`, `$block['text']` etc. (D-02).

**Test compatibility constraint:**
- `tests/Unit/AnthropicApiClientTest.php` calls `$apiClient->messages(...)` directly — `messages()` must remain public and unchanged.
- `tests/Feature/ClaudeServicesTest.php` passes `new AnthropicApiClient(...)` to service constructors — works because `AnthropicApiClient` implements `LlmClient`.
- `tests/Unit/ClaudeExecutorServiceTest.php` `fakeResponse()` returns stdClass with `->stopReason`, `->content` (array of stdClass objects), `->usage` — BUT after Task 3, `ClaudeExecutorService` will call `$apiClient->complete()` which returns an `LlmResponse`. The test's mock passes an `AnthropicApiClient` wrapping a fake client, so the real `complete()` method will run and wrap the SDK response in `LlmResponse`. The fake client's `create()` returns the same stdClass fakeResponse — `complete()` must map this correctly.

---

## Tasks

### Task 1: Create LlmClient interface and value objects

**Files:**
- `app/Contracts/LlmClient.php` (new)
- `app/Data/LlmResponse.php` (new)
- `app/Data/LlmUsage.php` (new)
- `app/Data/SystemBlock.php` (new)

**Acceptance:** All four files exist with correct PHP 8.2 namespace declarations, correct shapes per decisions D-01 through D-09, and pass `php artisan test` (tests unchanged, new files have no tests yet).

**Steps:**

1. Create `app/Contracts/LlmClient.php`:
```php
<?php

namespace App\Contracts;

use App\Data\LlmResponse;
use App\Data\SystemBlock;

interface LlmClient
{
    /**
     * @param  array<array<string, mixed>>  $messages
     * @param  array<array<string, mixed>>  $tools
     * @param  SystemBlock[]  $systemBlocks
     */
    public function complete(
        string $model,
        int $maxTokens,
        array $messages,
        array $tools = [],
        array $systemBlocks = [],
    ): LlmResponse;
}
```

2. Create `app/Data/LlmUsage.php`:
```php
<?php

namespace App\Data;

final class LlmUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}
}
```

3. Create `app/Data/LlmResponse.php`:
```php
<?php

namespace App\Data;

final class LlmResponse
{
    /**
     * @param  array<array<string, mixed>>  $content  Plain assoc arrays, e.g.
     *   ['type' => 'text', 'text' => '...'] or
     *   ['type' => 'tool_use', 'name' => '...', 'id' => '...', 'input' => [...]]
     */
    public function __construct(
        public readonly array $content,
        public readonly string $stopReason,
        public readonly LlmUsage $usage,
    ) {}
}
```

4. Create `app/Data/SystemBlock.php`:
```php
<?php

namespace App\Data;

final class SystemBlock
{
    public function __construct(
        public readonly string $text,
        public readonly bool $cache = false,
    ) {}
}
```

5. Run `php artisan test` — all existing tests must still pass (these are new files with no callsites yet).

---

### Task 2: Add `complete()` to AnthropicApiClient

**Files:**
- `app/Support/AnthropicApiClient.php` (modify)

**Acceptance:** `AnthropicApiClient` declares `implements \App\Contracts\LlmClient`, has a `complete()` method that translates `SystemBlock[]` to Anthropic SDK types and wraps the SDK response in `LlmResponse`/`LlmUsage`. Existing `messages()` method is unchanged and public. `php artisan test` still passes.

**Steps:**

1. Read `app/Support/AnthropicApiClient.php` in full before editing.

2. Add `implements \App\Contracts\LlmClient` to the class declaration.

3. Add `use` statements at the top:
   ```php
   use Anthropic\Messages\CacheControlEphemeral;
   use Anthropic\Messages\TextBlockParam;
   use App\Contracts\LlmClient;
   use App\Data\LlmResponse;
   use App\Data\LlmUsage;
   use App\Data\SystemBlock;
   ```

4. Add the `complete()` method BEFORE the existing `messages()` method. This method:
   - Translates `SystemBlock[]` to the Anthropic SDK `TextBlockParam[]` format (with `CacheControlEphemeral` when `$block->cache === true`)
   - Calls `$this->messages()` with the translated system array
   - Wraps the SDK response in `LlmResponse` + `LlmUsage`
   - Maps `$response->content` from SDK stdClass objects to plain assoc arrays
   - `stopReason` is passed through as-is (normalization deferred to Phase 15 per discretion — keep `end_turn` as returned by Anthropic)

   ```php
   public function complete(
       string $model,
       int $maxTokens,
       array $messages,
       array $tools = [],
       array $systemBlocks = [],
   ): LlmResponse {
       $system = [];
       foreach ($systemBlocks as $block) {
           if ($block->cache) {
               $system[] = TextBlockParam::with(
                   text: $block->text,
                   cacheControl: CacheControlEphemeral::with()
               );
           } else {
               $system[] = TextBlockParam::with(text: $block->text);
           }
       }

       $sdkResponse = $this->messages(
           model: $model,
           maxTokens: $maxTokens,
           system: $system !== [] ? $system : '',
           tools: $tools,
           messages: $messages,
       );

       $content = [];
       foreach ($sdkResponse->content as $block) {
           $entry = ['type' => $block->type];
           if (isset($block->text)) {
               $entry['text'] = $block->text;
           }
           if (isset($block->name)) {
               $entry['name'] = $block->name;
           }
           if (isset($block->id)) {
               $entry['id'] = $block->id;
           }
           if (isset($block->input)) {
               $entry['input'] = (array) $block->input;
           }
           $content[] = $entry;
       }

       $usage = new LlmUsage(
           inputTokens: $sdkResponse->usage->inputTokens ?? 0,
           outputTokens: $sdkResponse->usage->outputTokens ?? 0,
           cacheWriteTokens: $sdkResponse->usage->cacheCreationInputTokens ?? 0,
           cacheReadTokens: $sdkResponse->usage->cacheReadInputTokens ?? 0,
       );

       return new LlmResponse(
           content: $content,
           stopReason: $sdkResponse->stopReason,
           usage: $usage,
       );
   }
   ```

5. Run `php artisan test` — all existing tests must still pass. The `AnthropicApiClientTest` tests call `messages()` directly and must remain unaffected.

---

### Task 3: Update three Claude services to use LlmClient

**Files:**
- `app/Services/ClaudeSelectorService.php` (modify)
- `app/Services/ClaudePlannerService.php` (modify)
- `app/Services/ClaudeExecutorService.php` (modify)

**Files NOT modified:**
- `app/Support/AnthropicMessageSerializer.php` — this file and its test are untouched; see step 14 below for how the executor avoids needing to change it

**Acceptance:** All three constructors type-hint `LlmClient` (not `AnthropicApiClient`). All three call `$this->apiClient->complete()` (not `->messages()`). Selector and planner use array access `$response->content[0]['text']`. Executor uses array access `$block['type']`, `$block['text']`, `$block['name']`, `$block['id']`, `$block['input']`, and `$block['input']` as array. Executor's `usageFromResponse` uses `LlmUsage` field names. All existing tests pass.

**Steps:**

1. Read all three service files before editing.

#### ClaudeSelectorService

2. Replace `use App\Support\AnthropicApiClient;` with `use App\Contracts\LlmClient;`

3. Change constructor parameter: `private AnthropicApiClient $apiClient` → `private LlmClient $apiClient`

4. Replace the `$this->apiClient->messages(...)` call with `$this->apiClient->complete(...)`:
   ```php
   $response = $this->apiClient->complete(
       model: $this->model,
       maxTokens: 1024,
       messages: [
           ['role' => 'user', 'content' => $prompt],
       ],
   );
   ```

5. Change content access from object property to array access:
   ```php
   $text = $response->content[0]['text'] ?? '';
   ```

6. Update `usageFromResponse()` to accept `LlmResponse` and use `LlmUsage` field names. Add `use App\Data\LlmResponse;` import. Change signature to `private function usageFromResponse(LlmResponse $response): ?ModelUsage` and update the body:
   ```php
   private function usageFromResponse(LlmResponse $response): ?ModelUsage
   {
       return AnthropicCostEstimator::forModel(
           $this->model,
           $response->usage->inputTokens,
           $response->usage->outputTokens,
           $response->usage->cacheWriteTokens,
           $response->usage->cacheReadTokens,
       );
   }
   ```
   Remove the `if (! isset($response->usage))` guard — `LlmResponse->usage` is non-nullable.

#### ClaudePlannerService

7. Make the same changes as ClaudeSelectorService:
   - Replace `AnthropicApiClient` import with `LlmClient`
   - Change constructor type-hint
   - Replace `->messages(...)` call with `->complete(...)`
   - Change `$response->content[0]->text` to `$response->content[0]['text']`
   - Update `usageFromResponse()` to accept `LlmResponse`, use `LlmUsage` fields

#### ClaudeExecutorService

8. Replace `use App\Support\AnthropicApiClient;` with `use App\Contracts\LlmClient;`. Remove `use Anthropic\Messages\CacheControlEphemeral;` and `use Anthropic\Messages\TextBlockParam;` — these move to `AnthropicApiClient`. Add `use App\Data\LlmResponse;` and `use App\Data\SystemBlock;`.

9. Change constructor parameter: `private AnthropicApiClient $apiClient` → `private LlmClient $apiClient`

10. Replace the `$system` array construction (currently uses `TextBlockParam`/`CacheControlEphemeral`) with `SystemBlock` value objects:
    ```php
    $system = [
        new SystemBlock(text: $this->systemPrompt(), cache: true),
    ];
    ```

11. Replace the `$this->apiClient->messages(...)` call in the agentic loop with `->complete(...)`:
    ```php
    $response = $this->apiClient->complete(
        model: $this->model,
        maxTokens: 4096,
        messages: $messages,
        tools: $tools,
        systemBlocks: $system,
    );
    ```

12. Update token accumulation — `LlmUsage` uses `cacheWriteTokens`/`cacheReadTokens` (not `cacheCreationInputTokens`/`cacheReadInputTokens`):
    ```php
    if ($response->usage !== null) {
        $totalInputTokens += $response->usage->inputTokens;
        $totalOutputTokens += $response->usage->outputTokens;
        $totalCacheWriteTokens += $response->usage->cacheWriteTokens;
        $totalCacheReadTokens += $response->usage->cacheReadTokens;
        $this->updateSnapshot(...);
    }
    ```
    Note: `LlmUsage` is non-nullable on `LlmResponse`, so `if ($response->usage !== null)` is always true — keep the check for safety but it won't be null.

13. Update `ExecutorProgressFormatter::response(...)` call — the `cacheWrite` and `cacheRead` args now come from `$response->usage->cacheWriteTokens` and `$response->usage->cacheReadTokens`.

14. Update content block iteration — switch ALL `$block->type`, `$block->text`, `$block->name`, `$block->id`, `$block->input` property accesses to array access `$block['type']`, `$block['text']`, etc.:
    - `$toolUses` counting loop: `$block['type'] === 'tool_use'`
    - `$response->stopReason === 'end_turn'` remains (stopReason is a string property on LlmResponse)
    - Text accumulation: `if ($block['type'] === 'text') { $finalText .= $block['text']; }`
    - Tool dispatch loop: `if ($block['type'] !== 'tool_use') { continue; }`, `$block['name']`, `(array) $block['input']`, `$block['id']`
    - Tool result entry: `'tool_use_id' => $block['id']`

    **AnthropicMessageSerializer — do NOT call assistantContent():** Where the executor currently calls `AnthropicMessageSerializer::assistantContent($response->content)` to build the assistant message for the next round, replace that call with `$response->content` directly. `LlmResponse->content` is already in plain assoc array format, which is exactly what the Anthropic messages API expects for assistant content. The serializer call is redundant and would break because `AnthropicMessageSerializerTest` passes real SDK objects (`TextBlock::with(...)`, `ToolUseBlock::with(...)`) — modifying the serializer to accept arrays would break that test. Use `$response->content` directly instead:
    ```php
    // Before:
    $messages[] = ['role' => 'assistant', 'content' => AnthropicMessageSerializer::assistantContent($response->content)];
    // After:
    $messages[] = ['role' => 'assistant', 'content' => $response->content];
    ```

15. Run `php artisan test` after all three services are updated. All tests must pass green with no modifications to any test file.

---

### Task 4: Register LlmClient binding in AppServiceProvider

**Files:**
- `app/Providers/AppServiceProvider.php` (modify)

**Acceptance:** Laravel Zero's container resolves `LlmClient` to `AnthropicApiClient` at runtime. Running `php artisan run --help` (or any artisan command) does not throw "Target [App\Contracts\LlmClient] is not instantiable". `php artisan test` still passes.

**Steps:**

1. Read `app/Providers/AppServiceProvider.php` in full before editing.

2. Add `use` statements at the top of the file:
   ```php
   use App\Contracts\LlmClient;
   use App\Support\AnthropicApiClient;
   ```

3. Inside the `register()` method, add the container binding:
   ```php
   $this->app->bind(LlmClient::class, AnthropicApiClient::class);
   ```

4. Run `php artisan test` — all tests must still pass.

5. Smoke-test that the container binding resolves without error:
   ```bash
   php artisan list
   ```
   The command must complete without a "not instantiable" exception.

---

## Verification

- [ ] `app/Contracts/LlmClient.php` exists with `complete()` interface method per D-08
- [ ] `app/Data/LlmResponse.php` exists — `readonly array $content`, `readonly string $stopReason`, `readonly LlmUsage $usage` per D-03
- [ ] `app/Data/LlmUsage.php` exists — `inputTokens`, `outputTokens`, `cacheWriteTokens`, `cacheReadTokens` per D-09
- [ ] `app/Data/SystemBlock.php` exists — `string $text`, `bool $cache = false` per D-05
- [ ] `AnthropicApiClient implements \App\Contracts\LlmClient`
- [ ] `AnthropicApiClient::complete()` exists and translates `SystemBlock[]` to Anthropic SDK types (D-06)
- [ ] `AnthropicApiClient::messages()` remains public and unchanged (backward compat for tests)
- [ ] `ClaudeSelectorService` constructor accepts `LlmClient $apiClient`
- [ ] `ClaudePlannerService` constructor accepts `LlmClient $apiClient`
- [ ] `ClaudeExecutorService` constructor accepts `LlmClient $apiClient`
- [ ] All three services call `->complete()` not `->messages()`
- [ ] Executor builds `SystemBlock(cache: true)` for system prompt (D-13 preserved)
- [ ] `LlmUsage::cacheWriteTokens` / `cacheReadTokens` preserved (D-14)
- [ ] `ModelUsage` data class is unchanged (D-11)
- [ ] `app/Support/AnthropicMessageSerializer.php` is NOT modified
- [ ] Executor uses `$response->content` directly (not `AnthropicMessageSerializer::assistantContent()`) when appending assistant turn to messages
- [ ] `AppServiceProvider::register()` binds `LlmClient::class` to `AnthropicApiClient::class`
- [ ] `php artisan list` completes without "not instantiable" exception
- [ ] No test files modified
- [ ] Run: `php artisan test` — all pass

---

## Rollback

If anything breaks, revert with:

```bash
git diff --name-only HEAD
git checkout HEAD -- app/Support/AnthropicApiClient.php app/Services/ClaudeSelectorService.php app/Services/ClaudePlannerService.php app/Services/ClaudeExecutorService.php app/Providers/AppServiceProvider.php
```

New files can be removed:
```bash
rm app/Contracts/LlmClient.php app/Data/LlmResponse.php app/Data/LlmUsage.php app/Data/SystemBlock.php
rmdir app/Contracts  # only if empty
```

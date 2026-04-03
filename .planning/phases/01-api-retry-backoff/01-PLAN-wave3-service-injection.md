---
phase: 1
wave: 3
depends_on: [wave 2]
files_modified:
  - app/Services/ClaudeExecutorService.php
  - app/Services/ClaudePlannerService.php
  - app/Services/ClaudeSelectorService.php
requirements: [RELY-01]
autonomous: true
---

# Plan: Wave 3 — Inject AnthropicApiClient into Three Claude Services

## Objective

Update all three Claude service constructors to accept `AnthropicApiClient` as an injected dependency and remove their direct `new Client(...)` instantiation. Replace every `$this->client->messages->create(...)` call with `$this->apiClient->messages(...)`. After this wave, retry logic is active for all three services.

## must_haves

- `ClaudeExecutorService`, `ClaudePlannerService`, and `ClaudeSelectorService` no longer instantiate `Anthropic\Client` internally
- All three services accept `AnthropicApiClient $apiClient` as a constructor parameter
- All three `$this->client->messages->create(...)` call sites are replaced with `$this->apiClient->messages(...)`
- The `private Client $client` property is removed from all three services
- The `use Anthropic\Client;` import is removed from all three services
- The `use App\Support\AnthropicApiClient;` import is added to all three services
- All existing tests continue to pass (the `ClaudeServicesTest` test must be updated to inject `AnthropicApiClient`)

## Tasks

<task id="1.3.1">
<title>Update app/Services/ClaudeExecutorService.php</title>
<read_first>
- app/Services/ClaudeExecutorService.php (full file — read before editing)
- app/Support/AnthropicApiClient.php (method signature to call)
</read_first>
<action>
Make four changes to `app/Services/ClaudeExecutorService.php`:

**Change 1 — Replace import.** Remove `use Anthropic\Client;` and add `use App\Support\AnthropicApiClient;` in its place.

**Change 2 — Remove the `$client` property declaration.** Remove line:
```php
private Client $client;
```

**Change 3 — Replace constructor.** The current constructor is:
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(
        apiKey: $this->config->claudeApiKey(),
    );
    $this->model = $this->config->executorModel();
}
```
Replace with:
```php
public function __construct(
    private GlobalConfig $config,
    private AnthropicApiClient $apiClient,
) {
    $this->model = $this->config->executorModel();
}
```

**Change 4 — Replace the API call.** The current call at line 91 is:
```php
$response = $this->client->messages->create(
    model: $this->model,
    maxTokens: 4096,
    system: $systemPrompt,
    tools: $tools,
    messages: $messages,
);
```
Replace with:
```php
$response = $this->apiClient->messages(
    model: $this->model,
    maxTokens: 4096,
    system: $systemPrompt,
    tools: $tools,
    messages: $messages,
);
```
</action>
<acceptance_criteria>
- `grep -n "use App\\\\Support\\\\AnthropicApiClient;" app/Services/ClaudeExecutorService.php` returns a match
- `grep -n "use Anthropic\\\\Client;" app/Services/ClaudeExecutorService.php` returns 0 matches
- `grep -n "private Client \\\$client;" app/Services/ClaudeExecutorService.php` returns 0 matches
- `grep -n "new Client(" app/Services/ClaudeExecutorService.php` returns 0 matches
- `grep -n "private AnthropicApiClient \\\$apiClient" app/Services/ClaudeExecutorService.php` returns a match
- `grep -n "\\\$this->apiClient->messages(" app/Services/ClaudeExecutorService.php` returns exactly 1 match
- `grep -n "\\\$this->client->messages" app/Services/ClaudeExecutorService.php` returns 0 matches
- `php -l app/Services/ClaudeExecutorService.php` outputs `No syntax errors detected`
</acceptance_criteria>
</task>

<task id="1.3.2">
<title>Update app/Services/ClaudePlannerService.php</title>
<read_first>
- app/Services/ClaudePlannerService.php (full file — read before editing)
- app/Support/AnthropicApiClient.php (method signature — note that planner does NOT pass system or tools)
</read_first>
<action>
Make four changes to `app/Services/ClaudePlannerService.php`:

**Change 1 — Replace import.** Remove `use Anthropic\Client;` and add `use App\Support\AnthropicApiClient;` in its place.

**Change 2 — Remove the `$client` property declaration.** Remove line:
```php
private Client $client;
```

**Change 3 — Replace constructor.** The current constructor is:
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(
        apiKey: $this->config->claudeApiKey(),
    );
    $this->model = $this->config->plannerModel();
}
```
Replace with:
```php
public function __construct(
    private GlobalConfig $config,
    private AnthropicApiClient $apiClient,
) {
    $this->model = $this->config->plannerModel();
}
```

**Change 4 — Replace the API call.** The current call at line 53 is:
```php
$response = $this->client->messages->create(
    model: $this->model,
    maxTokens: 2048,
    messages: [
        ['role' => 'user', 'content' => $prompt],
    ],
);
```
Replace with:
```php
$response = $this->apiClient->messages(
    model: $this->model,
    maxTokens: 2048,
    messages: [
        ['role' => 'user', 'content' => $prompt],
    ],
);
```
Note: `system` and `tools` are not passed here. The `AnthropicApiClient::messages()` method defaults them to `''` and `[]` respectively, so they will be omitted from the underlying SDK call.
</action>
<acceptance_criteria>
- `grep -n "use App\\\\Support\\\\AnthropicApiClient;" app/Services/ClaudePlannerService.php` returns a match
- `grep -n "use Anthropic\\\\Client;" app/Services/ClaudePlannerService.php` returns 0 matches
- `grep -n "private Client \\\$client;" app/Services/ClaudePlannerService.php` returns 0 matches
- `grep -n "new Client(" app/Services/ClaudePlannerService.php` returns 0 matches
- `grep -n "private AnthropicApiClient \\\$apiClient" app/Services/ClaudePlannerService.php` returns a match
- `grep -n "\\\$this->apiClient->messages(" app/Services/ClaudePlannerService.php` returns exactly 1 match
- `grep -n "\\\$this->client->messages" app/Services/ClaudePlannerService.php` returns 0 matches
- `php -l app/Services/ClaudePlannerService.php` outputs `No syntax errors detected`
</acceptance_criteria>
</task>

<task id="1.3.3">
<title>Update app/Services/ClaudeSelectorService.php</title>
<read_first>
- app/Services/ClaudeSelectorService.php (full file — read before editing)
- app/Support/AnthropicApiClient.php (method signature — note that selector does NOT pass system or tools)
</read_first>
<action>
Make four changes to `app/Services/ClaudeSelectorService.php`:

**Change 1 — Replace import.** Remove `use Anthropic\Client;` and add `use App\Support\AnthropicApiClient;` in its place.

**Change 2 — Remove the `$client` property declaration.** Remove line:
```php
private Client $client;
```

**Change 3 — Replace constructor.** The current constructor is:
```php
public function __construct(private GlobalConfig $config)
{
    $this->client = new Client(
        apiKey: $this->config->claudeApiKey(),
    );
    $this->model = $this->config->selectorModel();
}
```
Replace with:
```php
public function __construct(
    private GlobalConfig $config,
    private AnthropicApiClient $apiClient,
) {
    $this->model = $this->config->selectorModel();
}
```

**Change 4 — Replace the API call.** The current call at line 43 is:
```php
$response = $this->client->messages->create(
    model: $this->model,
    maxTokens: 1024,
    messages: [
        ['role' => 'user', 'content' => $prompt],
    ],
);
```
Replace with:
```php
$response = $this->apiClient->messages(
    model: $this->model,
    maxTokens: 1024,
    messages: [
        ['role' => 'user', 'content' => $prompt],
    ],
);
```
Note: `system` and `tools` are not passed here. The `AnthropicApiClient::messages()` method defaults them to `''` and `[]` respectively.
</action>
<acceptance_criteria>
- `grep -n "use App\\\\Support\\\\AnthropicApiClient;" app/Services/ClaudeSelectorService.php` returns a match
- `grep -n "use Anthropic\\\\Client;" app/Services/ClaudeSelectorService.php` returns 0 matches
- `grep -n "private Client \\\$client;" app/Services/ClaudeSelectorService.php` returns 0 matches
- `grep -n "new Client(" app/Services/ClaudeSelectorService.php` returns 0 matches
- `grep -n "private AnthropicApiClient \\\$apiClient" app/Services/ClaudeSelectorService.php` returns a match
- `grep -n "\\\$this->apiClient->messages(" app/Services/ClaudeSelectorService.php` returns exactly 1 match
- `grep -n "\\\$this->client->messages" app/Services/ClaudeSelectorService.php` returns 0 matches
- `php -l app/Services/ClaudeSelectorService.php` outputs `No syntax errors detected`
</acceptance_criteria>
</task>

## Verification

Run full test suite after all three service updates:
```bash
./vendor/bin/pest
```

NOTE: `tests/Feature/ClaudeServicesTest.php` will fail at this point because it constructs services with only `$config` as argument. That failure is expected and is fixed in Wave 4, task 1.4.2. If running the suite before Wave 4, expect exactly one failing test in `ClaudeServicesTest`.

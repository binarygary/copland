# Phase 15: Provider Implementations - Research

**Researched:** 2026-04-08
**Domain:** PHP LLM client abstraction — openai-php/client, Ollama OpenAI-compat layer, OpenRouter
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Keep existing `models:` key. Add separate `llm:` block for provider/routing. Both coexist.
- **D-02:** `llm:` block uses nested provider objects (see CONTEXT.md for YAML shape).
- **D-03:** When `llm:` is absent, factory defaults to `provider: anthropic` using `claude_api_key`. Backwards-compatible.
- **D-04:** Per-repo `.copland.yml` uses same nested structure. Same-granularity override.
- **D-05:** Resolution order: repo `llm.stages.{stage}` → global `llm.stages.{stage}` → repo `llm.default` → global `llm.default` → implicit Anthropic fallback.
- **D-06:** Services receive resolved client (not factory) in constructors.
- **D-07:** Factory signature: `LlmClientFactory::forStage(string $stage, GlobalConfig $global, ?RepoConfig $repo = null): LlmClient`
- **D-08:** `AppServiceProvider` registers `LlmClientFactory` and calls `forStage()` per service. Single `LlmClient::class` binding replaced by per-service bindings.
- **D-09:** `ToolSchemaTranslator` class handles Anthropic → OpenAI tool schema mapping. Called inside `OpenAiCompatClient::complete()`.
- **D-10:** Translation: Anthropic `input_schema` → OpenAI `parameters`, wrapped in `['type' => 'function', 'function' => [...]]`.
- **D-11:** `cache_control` blocks in `SystemBlock` stripped by `OpenAiCompatClient` before building request.
- **D-12:** Canonical `LlmResponse.stopReason` values: `stop` and `tool_calls` (OpenAI conventions).
- **D-13:** `LlmResponseNormalizer` maps: `end_turn` → `stop`, `tool_use` → `tool_calls`. Used by `AnthropicApiClient`.
- **D-14:** Claude services updated to check `$response->stopReason === 'tool_calls'` (previously `'tool_use'`).
- **D-15:** Before orchestration loop, probe `GET {base_url}/api/tags` for Ollama stages. Exit with clear error on failure.
- **D-16:** Probe deduped by base_url, runs once.
- **D-17:** Known tool-capable model list hardcoded in constant.
- **D-18:** Warning fires once at startup if model not on list. Run continues.
- **D-19:** OpenRouter requests include `HTTP-Referer: https://github.com/binarygary/copland` and `X-Title: Copland`.

### Claude's Discretion

- Exact namespace for `LlmClientFactory`, `ToolSchemaTranslator`, `LlmResponseNormalizer`
- Whether `LlmResponseNormalizer` is a class, trait, or static helper
- How `openai-php/client` is instantiated for Ollama vs OpenRouter
- Exact list of known tool-capable Ollama models to hardcode
- Whether reachability probe lives in `RunOrchestratorService` or `RunCommand`

### Deferred Ideas (OUT OF SCOPE)

None — discussion stayed within phase scope.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PROV-03 | User can set default LLM provider in `~/.copland.yml` | `GlobalConfig` getter pattern established; `llm:` key parsed with `$this->data['llm'] ?? []` |
| PROV-04 | User can override LLM provider per repo in `.copland.yml` | `RepoConfig` same YAML parsing pattern; same `llm:` block shape |
| PROV-05 | User can configure different providers per stage | `LlmClientFactory::forStage()` resolution order; D-05 provides complete algorithm |
| OLLAMA-01 | User can configure Ollama with base URL and model name | `openai-php/client` factory supports full `http://` base URIs; `withBaseUri('http://localhost:11434/v1')` + `withApiKey('ollama')` |
| OLLAMA-02 | Copland probes Ollama reachability before loop; fails fast | `GET /api/tags` endpoint confirmed; returns `{"models": [...]}` or connection error |
| OLLAMA-03 | Copland warns at startup if model not on tool-capable list | Hardcoded constant in `OpenAiCompatClient`; warning before run, no abort |
| OPENR-01 | User can configure OpenRouter with API key and model name | Base URL `https://openrouter.ai/api/v1`; `withApiKey($key)` + `withBaseUri(...)` |
| OPENR-02 | Copland sends attribution headers on OpenRouter requests | `withHttpHeader('HTTP-Referer', '...')` + `withHttpHeader('X-Title', 'Copland')` |
</phase_requirements>

---

## Summary

Phase 15 adds `OpenAiCompatClient` (serving both Ollama and OpenRouter), `LlmClientFactory` for per-stage client resolution, config parsing for the `llm:` block, tool schema translation, and `stopReason` normalization. The `openai-php/client` library (v0.19.1) is **not yet installed** — it must be added as a Composer dependency. It supports custom base URIs including full `http://` URLs and per-request custom headers via the factory pattern, making it suitable for both Ollama (local, no auth) and OpenRouter (remote, bearer auth).

The most critical implementation detail is the **message format mismatch** between Anthropic and OpenAI tool-call conventions. The executor currently uses Anthropic `tool_use` content blocks and `tool_result` user messages. With `OpenAiCompatClient`, the response content blocks must still be returned as Anthropic-style assoc arrays (since `LlmResponse.content` is typed as `array<array<string, mixed>>`), but the LLM is called with OpenAI-format tool messages. This means `OpenAiCompatClient` must both translate outgoing tool schemas (Anthropic → OpenAI) and map incoming tool call responses (OpenAI → Anthropic-style content blocks).

The `ClaudeExecutorService` has three sites that check `'tool_use'` and one that checks `'end_turn'`. After D-12/D-13/D-14 normalization, all clients emit canonical `stop`/`tool_calls` values, so the executor is updated to check `'tool_calls'` and `'stop'` instead.

**Primary recommendation:** Install `openai-php/client` ^0.19, implement `OpenAiCompatClient` using factory pattern with conditional base URI / headers, map OpenAI response tool calls to Anthropic-style content blocks in `complete()`, and update all four stopReason check sites in the executor.

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| openai-php/client | ^0.19.1 | HTTP client for OpenAI-compat APIs | Covers Ollama + OpenRouter; factory pattern supports custom base URL and headers |
| symfony/yaml | ^8.0 (installed) | Parse `llm:` config blocks | Already in use; no change |
| guzzlehttp/guzzle | 7.x (transitive) | HTTP for Ollama reachability probe | Available via openai-php/client dependencies |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| anthropic-ai/sdk | ^0.8.0 (installed) | Existing Anthropic API calls | AnthropicApiClient stays unchanged except stopReason normalization |

**Installation:**
```bash
composer require openai-php/client
```

**Version verification (confirmed 2026-04-08):** `openai-php/client` latest stable = v0.19.1 (released 2026-03-17). Not yet in composer.json.

---

## Architecture Patterns

### Recommended Project Structure

New files for this phase:

```
app/
├── Support/
│   ├── AnthropicApiClient.php         (existing — add LlmResponseNormalizer call)
│   ├── LlmResponseNormalizer.php      (new — static helper)
│   ├── ToolSchemaTranslator.php        (new)
│   └── OpenAiCompatClient.php          (new)
├── Services/
│   ├── LlmClientFactory.php            (new — or app/Support/)
│   ├── ClaudeExecutorService.php       (existing — update stopReason checks)
│   ├── ClaudePlannerService.php        (existing — no stopReason change needed)
│   └── ClaudeSelectorService.php       (existing — no stopReason change needed)
├── Config/
│   ├── GlobalConfig.php                (existing — add llm() getter)
│   └── RepoConfig.php                  (existing — add llm() getter)
└── Providers/
    └── AppServiceProvider.php          (existing — replace single bind with per-service factory calls)
```

**Namespace decision (Claude's discretion):** Both `LlmClientFactory` and `LlmResponseNormalizer` fit `App\Support\` (not service-like entities — they are infrastructure helpers). `ToolSchemaTranslator` also fits `App\Support\`.

### Pattern 1: openai-php/client Factory Instantiation

**What:** Use `OpenAI::factory()` instead of `OpenAI::client()` to configure base URI and headers.
**When to use:** Always — the static `OpenAI::client()` only supports openai.com.

```php
// Ollama
$client = OpenAI::factory()
    ->withApiKey('ollama')               // required by client; ignored by Ollama
    ->withBaseUri('http://localhost:11434/v1')  // full http:// URI supported
    ->make();

// OpenRouter
$client = OpenAI::factory()
    ->withApiKey($apiKey)
    ->withBaseUri('https://openrouter.ai/api/v1')
    ->withHttpHeader('HTTP-Referer', 'https://github.com/binarygary/copland')
    ->withHttpHeader('X-Title', 'Copland')
    ->make();
```

**CRITICAL:** `withBaseUri()` normalizes the URI via `BaseUri::from()`. The `toString()` method checks for `http://` or `https://` prefix and preserves it. A domain-only string gets `https://` prepended. So `http://localhost:11434/v1` will work correctly.

**Source:** openai-php/client `src/ValueObjects/Transporter/BaseUri.php` (verified 2026-04-08)

### Pattern 2: Chat Completions Call with Tools

```php
$response = $openAiClient->chat()->create([
    'model' => $model,
    'max_tokens' => $maxTokens,
    'messages' => $messages,         // OpenAI message format
    'tools' => $translatedTools,     // translated via ToolSchemaTranslator
]);

// Response access (verified from source):
$choice = $response->choices[0];
$finishReason = $choice->finishReason;     // 'stop' | 'tool_calls' | 'length' | null
$message = $choice->message;
$content = $message->content;              // ?string
$toolCalls = $message->toolCalls;          // array<int, CreateResponseToolCall>

foreach ($toolCalls as $toolCall) {
    $toolCall->id;                         // string, e.g. 'call_abc123'
    $toolCall->type;                       // 'function'
    $toolCall->function->name;             // string
    $toolCall->function->arguments;        // JSON string — must json_decode
}

// Usage
$response->usage->promptTokens;
$response->usage->completionTokens;
```

**Source:** openai-php/client `src/Responses/Chat/CreateResponseChoice.php`, `CreateResponseMessage.php`, `CreateResponseToolCall.php`, `CreateResponseToolCallFunction.php` (verified 2026-04-08)

### Pattern 3: OpenAI → Anthropic Content Block Mapping

`LlmResponse.content` is typed as `array<array<string, mixed>>` with Anthropic-style blocks. `OpenAiCompatClient::complete()` must convert OpenAI's response into that shape.

```php
// OpenAI response → Anthropic-style content blocks (for LlmResponse.content)
$content = [];

// Text block (when content is not null)
if ($message->content !== null && $message->content !== '') {
    $content[] = ['type' => 'text', 'text' => $message->content];
}

// Tool use blocks (from OpenAI toolCalls → Anthropic tool_use)
foreach ($message->toolCalls as $toolCall) {
    $content[] = [
        'type' => 'tool_use',
        'id'   => $toolCall->id,
        'name' => $toolCall->function->name,
        'input' => json_decode($toolCall->function->arguments, true) ?? [],
    ];
}

// stopReason: map 'tool_calls' → 'tool_calls', 'stop' → 'stop' (pass through)
```

This means `ClaudeExecutorService` can continue iterating `$response->content` checking `$block['type'] === 'tool_use'` — after the rename from `'tool_use'` to `'tool_calls'` for stopReason, the content block type stays `'tool_use'` in `LlmResponse.content`.

**IMPORTANT:** The `stopReason` normalization (D-12/D-13) affects `LlmResponse.stopReason` values only. Content block types remain `'tool_use'` / `'text'` in `LlmResponse.content` array — they are not renamed.

### Pattern 4: Tool Results Back to OpenAI-compat API

The executor builds tool results and appends them as a `'user'` message. For OpenAI-compat, the correct format is different from Anthropic:

**Anthropic (current executor output):**
```php
[
    'role' => 'user',
    'content' => [
        ['type' => 'tool_result', 'tool_use_id' => 'id-1', 'content' => '...', 'is_error' => false],
    ],
]
```

**OpenAI-compat format (required by Ollama/OpenRouter):**
```php
// Assistant message must contain tool_calls array:
['role' => 'assistant', 'content' => null, 'tool_calls' => [
    ['id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{"path":"..."}']],
]]
// Tool result messages use role 'tool':
['role' => 'tool', 'tool_call_id' => 'call_abc', 'content' => 'result text']
```

**Resolution:** `OpenAiCompatClient` must keep track of the message history in OpenAI format internally. The `complete()` method receives `array $messages` in the `LlmClient` interface format. The client must either:
1. Accept messages already formatted for OpenAI (preferred — the executor passes `$response->content` back as the assistant message), OR
2. Translate message history on each call.

Since `ClaudeExecutorService` builds the message array by appending `$response->content` directly as the assistant message content (`'content' => $response->content`), **`OpenAiCompatClient::complete()` must reconstruct the proper OpenAI assistant message format** from the `LlmResponse.content` blocks and tool results format.

This is the most complex translation in the phase. The `complete()` method needs to:
- Translate incoming `messages` where `role=assistant` and `content` is an array with `tool_use` blocks → `role=assistant, content=null, tool_calls=[...]`
- Translate `role=user, content=[{type:tool_result, tool_use_id:...}]` → multiple `role=tool` messages

### Pattern 5: ToolSchemaTranslator

```php
// Input (Anthropic format from ClaudeExecutorService::buildTools()):
[
    'name' => 'read_file',
    'description' => 'Read a file in the workspace',
    'input_schema' => ['type' => 'object', 'properties' => [...], 'required' => [...]]
]

// Output (OpenAI format):
[
    'type' => 'function',
    'function' => [
        'name' => 'read_file',
        'description' => 'Read a file in the workspace',
        'parameters' => ['type' => 'object', 'properties' => [...], 'required' => [...]]
    ]
]
```

Translation is: wrap in `['type' => 'function', 'function' => [...]]`, rename `input_schema` → `parameters`. Everything inside `input_schema` passes through unchanged.

### Pattern 6: stopReason Normalization

`LlmResponseNormalizer` — simplest form is a final class with a static method:

```php
final class LlmResponseNormalizer
{
    public static function normalize(string $stopReason): string
    {
        return match ($stopReason) {
            'end_turn'  => 'stop',
            'tool_use'  => 'tool_calls',
            default     => $stopReason,
        };
    }
}
```

Used in `AnthropicApiClient::complete()` at the line:
```php
stopReason: $sdkResponse->stopReason,
// becomes:
stopReason: LlmResponseNormalizer::normalize($sdkResponse->stopReason),
```

`OpenAiCompatClient` passes `stop` and `tool_calls` through unchanged (they are already canonical).

### Pattern 7: Ollama Reachability Probe

```php
// Using Guzzle (available transitively):
$httpClient = new \GuzzleHttp\Client(['timeout' => 3]);
try {
    $response = $httpClient->get(rtrim($baseUrl, '/') . '/api/tags');
    // success: $response->getStatusCode() === 200
    // optionally parse body to get model list for OLLAMA-03 warning
} catch (\GuzzleHttp\Exception\ConnectException $e) {
    throw new \RuntimeException("Ollama is not reachable at {$baseUrl}. Is it running?");
} catch (\Throwable $e) {
    throw new \RuntimeException("Ollama probe failed at {$baseUrl}: " . $e->getMessage());
}
```

The `/api/tags` response body shape:
```json
{
  "models": [
    {
      "name": "llama3.1:latest",
      "model": "llama3.1:latest",
      "modified_at": "2025-10-03T...",
      "size": 3338801804,
      "digest": "sha256:...",
      "details": { "family": "llama", "parameter_size": "8B", ... }
    }
  ]
}
```

To check if a model is pulled: search `models[].name` for the configured model name. Model names may include tags (e.g., `llama3.1:latest`); compare case-insensitively and handle missing tag as `:latest`.

### Pattern 8: Config Parsing for llm: Block

Following existing `GlobalConfig` getter pattern:

```php
// In GlobalConfig (and RepoConfig):
public function llmConfig(): array
{
    return $this->data['llm'] ?? [];
}
```

`LlmClientFactory::forStage()` receives the raw `llm` arrays from both configs and applies the resolution order (D-05):

```php
public static function forStage(string $stage, GlobalConfig $global, ?RepoConfig $repo = null): LlmClient
{
    $repoLlm   = $repo?->llmConfig() ?? [];
    $globalLlm = $global->llmConfig();

    // Resolution order:
    $config = $repoLlm['stages'][$stage]
        ?? $globalLlm['stages'][$stage]
        ?? $repoLlm['default']
        ?? $globalLlm['default']
        ?? ['provider' => 'anthropic'];  // implicit fallback

    return match ($config['provider'] ?? 'anthropic') {
        'ollama'      => self::buildOllama($config),
        'openrouter'  => self::buildOpenRouter($config),
        default       => self::buildAnthropic($global),
    };
}
```

### Anti-Patterns to Avoid

- **Using `OpenAI::client($apiKey)` for custom endpoints:** This only supports openai.com. Always use `OpenAI::factory()`.
- **Assuming `withBaseUri` rejects `http://`:** It supports full `http://` URLs — confirmed in source.
- **Passing Anthropic `tool_use` message format to OpenAI-compat API:** Will fail. Must translate messages on the way out.
- **Checking `$response->stopReason === 'end_turn'` after normalization:** After D-14, check `=== 'stop'` only.
- **Using `tool_choice` with Ollama:** Not supported by Ollama's OpenAI-compat layer.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| OpenAI-compat HTTP | Custom Guzzle client | `openai-php/client` | Handles auth headers, retries, PSR-18 |
| Ollama reachability HTTP | New HTTP client dependency | Guzzle (already transitive via openai-php) | No new dependency needed |
| OpenRouter base URL | Hardcode per-call | `withBaseUri('https://openrouter.ai/api/v1')` | Consistent, tested |

---

## Common Pitfalls

### Pitfall 1: message history format mismatch breaks agentic loop

**What goes wrong:** `OpenAiCompatClient::complete()` receives messages that have been built by `ClaudeExecutorService` in Anthropic format (assistant content as `tool_use` block array, tool results as `tool_result` user messages). Passing these directly to the OpenAI API causes a 400 error.

**Why it happens:** `ClaudeExecutorService` appends `$response->content` directly as the assistant message: `['role' => 'assistant', 'content' => $response->content]`. `LlmResponse.content` uses Anthropic `tool_use` block shape.

**How to avoid:** `OpenAiCompatClient::complete()` must translate the `$messages` array before sending:
1. For `role=assistant` messages where `content` is an array containing `type=tool_use` blocks: reformat as OpenAI assistant message with `tool_calls` array and `content: null`.
2. For `role=user` messages where `content` is an array containing `type=tool_result` blocks: convert each to a separate `role=tool` message.

**Warning signs:** HTTP 400 from Ollama/OpenRouter on the second round of the executor loop.

### Pitfall 2: `arguments` is a JSON string, not decoded array

**What goes wrong:** `$toolCall->function->arguments` is a JSON string (e.g., `'{"path":"src/foo.php"}'`). Passing it directly as `input` to `dispatchTool()` will fail because the executor expects an array.

**Why it happens:** OpenAI API returns function arguments as a serialized JSON string to allow the model to return malformed JSON without crashing the client.

**How to avoid:** In `OpenAiCompatClient::complete()`, `json_decode($toolCall->function->arguments, true) ?? []` when building the `input` field of the `tool_use` content block.

**Warning signs:** `Tool 'read_file' requires a non-empty string 'path' field` error from `requireString()`.

### Pitfall 3: stopReason check sites are dispersed

**What goes wrong:** Updating only `ClaudeExecutorService` while missing the content block type check on line 114 (`$block['type'] === 'tool_use'`) or the tool result builder on line 155 — these check content block types, not stopReason, and must remain `'tool_use'` because `LlmResponse.content` still uses Anthropic-style block types.

**Why it happens:** Confusion between `LlmResponse.stopReason` (being renamed to `'stop'`/`'tool_calls'`) vs content block `type` field (remains `'tool_use'`/`'text'`).

**How to avoid:** The four stopReason check sites to update:
1. `ClaudeExecutorService.php:135` — `'end_turn'` → `'stop'`
2. Content block `type` checks (lines 114, 155) — these stay `'tool_use'` (content block type is not renamed)
3. `fakeResponse()` in `ClaudeExecutorServiceTest.php` — test helper uses `stopReason: 'tool_use'` and `stopReason: 'end_turn'` which will break after normalization is added

**Warning signs:** Executor exits immediately on first response (thinks it's `stop` when it's actually `tool_calls`).

### Pitfall 4: Ollama model name tag matching

**What goes wrong:** User configures `model: llama3.1` but `/api/tags` returns `llama3.1:latest`. String equality fails.

**Why it happens:** Ollama appends `:latest` tag when no tag is specified.

**How to avoid:** When checking the pulled-model list, normalize: if configured model has no `:` tag, append `:latest` before comparing. Or compare via `str_starts_with($pulledModel, $configuredModel)`.

### Pitfall 5: `withBaseUri` protocol handling

**What goes wrong:** Passing `localhost:11434/v1` without `http://` prefix causes the client to prepend `https://`, failing for local HTTP-only Ollama.

**Why it happens:** `BaseUri::toString()` defaults to `https://` when no protocol prefix is detected.

**How to avoid:** Always include `http://` or `https://` in the configured `base_url` value. Document this in the default YAML comment.

---

## Code Examples

### OpenAiCompatClient Skeleton

```php
// Source: openai-php/client README + BaseUri.php analysis
final class OpenAiCompatClient implements LlmClient
{
    private const TOOL_CAPABLE_MODELS = [
        'llama3.1', 'llama3.1:latest', 'llama3.1:8b', 'llama3.1:70b',
        'llama3.2', 'llama3.2:latest', 'llama3.2:3b', 'llama3.2:1b',
        'mistral', 'mistral:latest', 'mistral-nemo', 'mistral-nemo:latest',
        'qwen2.5', 'qwen2.5:latest', 'qwen2.5:7b', 'qwen2.5:14b',
        'command-r', 'command-r:latest',
        'firefunction-v2', 'firefunction-v2:latest',
    ];

    public function __construct(
        private object $client,   // openai-php/client instance
    ) {}

    public function complete(
        string $model,
        int $maxTokens,
        array $messages,
        array $tools = [],
        array $systemBlocks = [],
    ): LlmResponse {
        $translatedTools = array_map(
            [ToolSchemaTranslator::class, 'translate'],
            $tools
        );

        $openAiMessages = $this->translateMessages($messages, $systemBlocks);

        $response = $this->client->chat()->create([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $openAiMessages,
            'tools'      => $translatedTools !== [] ? $translatedTools : null,
        ]);

        $choice = $response->choices[0];
        return new LlmResponse(
            content: $this->mapContent($choice->message),
            stopReason: $choice->finishReason ?? 'stop',
            usage: new LlmUsage(
                inputTokens: $response->usage?->promptTokens ?? 0,
                outputTokens: $response->usage?->completionTokens ?? 0,
                cacheWriteTokens: 0,
                cacheReadTokens: 0,
            ),
        );
    }
}
```

### Message Translation (Anthropic → OpenAI)

```php
private function translateMessages(array $messages, array $systemBlocks): array
{
    $result = [];

    // System blocks → system message
    if ($systemBlocks !== []) {
        $systemText = implode("\n\n", array_map(
            static fn(SystemBlock $b): string => $b->text,  // strip cache_control (D-11)
            $systemBlocks
        ));
        $result[] = ['role' => 'system', 'content' => $systemText];
    }

    foreach ($messages as $msg) {
        $role    = $msg['role'];
        $content = $msg['content'];

        if ($role === 'user' && is_array($content)) {
            // Tool results (Anthropic) → tool messages (OpenAI)
            $hasToolResult = array_any($content, fn($b) => ($b['type'] ?? '') === 'tool_result');
            if ($hasToolResult) {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $result[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $block['tool_use_id'],
                            'content'      => $block['content'],
                        ];
                    }
                }
                continue;
            }
        }

        if ($role === 'assistant' && is_array($content)) {
            // Tool use blocks → OpenAI assistant message with tool_calls
            $toolCalls = [];
            $textContent = null;
            foreach ($content as $block) {
                if ($block['type'] === 'tool_use') {
                    $toolCalls[] = [
                        'id' => $block['id'],
                        'type' => 'function',
                        'function' => [
                            'name'      => $block['name'],
                            'arguments' => json_encode($block['input']),
                        ],
                    ];
                } elseif ($block['type'] === 'text') {
                    $textContent = $block['text'];
                }
            }
            $result[] = array_filter([
                'role'       => 'assistant',
                'content'    => $textContent,
                'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
            ]);
            continue;
        }

        // Plain user/assistant string message
        $result[] = ['role' => $role, 'content' => is_string($content) ? $content : json_encode($content)];
    }

    return $result;
}
```

Note: `array_any()` is PHP 8.5+. For PHP 8.2 compatibility, use `array_filter()` with a count check or a foreach.

### Content Block Mapping (OpenAI response → LlmResponse.content)

```php
private function mapContent(object $message): array
{
    $content = [];

    if ($message->content !== null && $message->content !== '') {
        $content[] = ['type' => 'text', 'text' => $message->content];
    }

    foreach ($message->toolCalls as $toolCall) {
        $content[] = [
            'type'  => 'tool_use',
            'id'    => $toolCall->id,
            'name'  => $toolCall->function->name,
            'input' => json_decode($toolCall->function->arguments, true) ?? [],
        ];
    }

    return $content;
}
```

### LlmResponseNormalizer

```php
// app/Support/LlmResponseNormalizer.php
final class LlmResponseNormalizer
{
    public static function normalize(string $stopReason): string
    {
        return match ($stopReason) {
            'end_turn' => 'stop',
            'tool_use' => 'tool_calls',
            default    => $stopReason,
        };
    }
}
```

### ToolSchemaTranslator

```php
// app/Support/ToolSchemaTranslator.php
final class ToolSchemaTranslator
{
    public static function translate(array $tool): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters'  => $tool['input_schema'],
            ],
        ];
    }
}
```

### AppServiceProvider Update

```php
public function register(): void
{
    $global = new GlobalConfig();

    $this->app->bind(LlmClient::class . '.selector', function () use ($global) {
        return LlmClientFactory::forStage('selector', $global);
    });
    $this->app->bind(LlmClient::class . '.planner', function () use ($global) {
        return LlmClientFactory::forStage('planner', $global);
    });
    $this->app->bind(LlmClient::class . '.executor', function () use ($global) {
        return LlmClientFactory::forStage('executor', $global);
    });

    // Services resolved with their specific client
    $this->app->bind(ClaudeSelectorService::class, function ($app) {
        return new ClaudeSelectorService(
            $app->make(GlobalConfig::class),
            $app->make(LlmClient::class . '.selector'),
        );
    });
    // ... similarly for planner and executor
}
```

---

## stopReason Check Sites in ClaudeExecutorService

Exact lines requiring changes (confirmed by grep):

| Line | Current Value | After D-14 | Note |
|------|--------------|------------|------|
| 114 | `$block['type'] === 'tool_use'` | **NO CHANGE** | Content block type — not stopReason |
| 135 | `$response->stopReason === 'end_turn'` | `=== 'stop'` | stopReason check |
| 155 | `$block['type'] !== 'tool_use'` | **NO CHANGE** | Content block type — not stopReason |
| 201 | `'tool_use_id' => $block['id']` | **NO CHANGE** | Message field building |

Only **line 135** changes. Lines 114 and 155 check `$block['type']` (content block type), which remains `'tool_use'` in `LlmResponse.content`.

Additionally, `ClaudeExecutorServiceTest.php` helper `fakeResponse()` uses `stopReason: 'tool_use'` and `stopReason: 'end_turn'` — these test helpers must be updated to match the new canonical values (`'tool_calls'` and `'stop'`) OR the test fake responses must pre-normalize.

---

## Ollama /api/tags Response

**Endpoint:** `GET {base_url}/api/tags` (not the OpenAI-compat `/v1/` path — this is the native Ollama API)

**Response:**
```json
{
  "models": [
    {
      "name": "llama3.1:latest",
      "model": "llama3.1:latest",
      "modified_at": "2025-10-03T23:34:03Z",
      "size": 4702410544,
      "digest": "sha256:...",
      "details": {
        "format": "gguf",
        "family": "llama",
        "families": ["llama"],
        "parameter_size": "8.0B",
        "quantization_level": "Q4_K_M"
      }
    }
  ]
}
```

**For OLLAMA-02 (reachability):** A successful 200 response (any body) means Ollama is running. Connection refused or timeout means it is not.

**For OLLAMA-03 (model warning):** Parse `models[].name` from the response to check if the configured model is pulled. If `models` is empty or the model name is absent: still emit capability warning (model is not pulled — tool use will fail regardless).

**Base URL for probe:** Strip the `/v1` suffix from the configured `base_url`. The Ollama native API lives at `http://localhost:11434`, not `http://localhost:11434/v1`. So: `rtrim(str_replace('/v1', '', $baseUrl), '/')`.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All code | Yes | Darwin 25.4.0 (system PHP) | — |
| Composer | openai-php/client install | Yes (assumed, project uses it) | — | — |
| openai-php/client | OpenAiCompatClient | No — NOT installed | — | Must install |
| Guzzle | Ollama probe HTTP | Transitive via openai-php | — | Available after install |

**Missing dependencies with no fallback:**
- `openai-php/client` ^0.19 — must be added to `composer.json` and installed before any code in this phase can run.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 3.8.4 / 4.1.2 |
| Config file | `phpunit.xml` (Laravel Zero default) |
| Quick run command | `./vendor/bin/pest tests/Unit/` |
| Full suite command | `./vendor/bin/pest --no-coverage` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PROV-03 | `GlobalConfig::llmConfig()` returns `llm:` block data | unit | `./vendor/bin/pest tests/Unit/GlobalConfigTest.php` | Exists — add cases |
| PROV-04 | `RepoConfig::llmConfig()` returns `llm:` block data | unit | `./vendor/bin/pest tests/Unit/RepoConfigTest.php` | Exists — add cases |
| PROV-05 | `LlmClientFactory::forStage()` resolution order | unit | `./vendor/bin/pest tests/Unit/LlmClientFactoryTest.php` | Wave 0 gap |
| OLLAMA-01 | `OpenAiCompatClient` instantiation with custom base URL | unit | `./vendor/bin/pest tests/Unit/OpenAiCompatClientTest.php` | Wave 0 gap |
| OLLAMA-02 | Ollama probe fires correct HTTP request; throws on failure | unit | `./vendor/bin/pest tests/Unit/OllamaReachabilityTest.php` (or within factory test) | Wave 0 gap |
| OLLAMA-03 | Warning emitted for unknown model; no abort | unit | Within `OpenAiCompatClientTest.php` or `LlmClientFactoryTest.php` | Wave 0 gap |
| OPENR-01 | `OpenAiCompatClient` instantiation with OpenRouter config | unit | `./vendor/bin/pest tests/Unit/OpenAiCompatClientTest.php` | Wave 0 gap |
| OPENR-02 | Attribution headers present in OpenRouter requests | unit | `./vendor/bin/pest tests/Unit/OpenAiCompatClientTest.php` | Wave 0 gap |

### Sampling Rate
- **Per task commit:** `./vendor/bin/pest tests/Unit/ --no-coverage`
- **Per wave merge:** `./vendor/bin/pest --no-coverage`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/LlmClientFactoryTest.php` — covers PROV-05 resolution order, all 5 config scenarios
- [ ] `tests/Unit/OpenAiCompatClientTest.php` — covers OLLAMA-01, OPENR-01, OPENR-02, tool schema translation, content block mapping, message history translation
- [ ] `tests/Unit/ToolSchemaTranslatorTest.php` — covers D-10 translation shape
- [ ] `tests/Unit/LlmResponseNormalizerTest.php` — covers D-12/D-13 normalization cases

---

## Open Questions

1. **`array_any()` availability**
   - What we know: `array_any()` is PHP 8.5+, not PHP 8.2+
   - What's unclear: Whether the project has any polyfill
   - Recommendation: Use `count(array_filter(...)) > 0` or a foreach in message translation code

2. **AppServiceProvider: RepoConfig at bind time**
   - What we know: `RepoConfig` requires a `$repoPath` — not available at container registration time
   - What's unclear: How to inject per-request `RepoConfig` into `LlmClientFactory::forStage()` when services are bound at registration
   - Recommendation: `LlmClientFactory::forStage()` should be called lazily (inside the closure, not at `register()` time). The `RunCommand` and orchestrator can pass `RepoConfig` when constructing services directly, rather than relying on the container for per-repo binding. Alternatively, factory is registered and services are resolved with repo config injected at call time in the command. This is a design decision for the planner — the simplest approach is for `AppServiceProvider` to bind with only `GlobalConfig` (no repo config), and the command/orchestrator re-resolves or directly instantiates services with repo-level config when needed.

3. **Test helpers after stopReason rename**
   - What we know: `fakeResponse(stopReason: 'tool_use', ...)` and `fakeResponse(stopReason: 'end_turn', ...)` are used in `ClaudeExecutorServiceTest.php`
   - What's unclear: Whether tests should use pre-normalization values (testing `AnthropicApiClient` normalization separately) or post-normalization values
   - Recommendation: `fakeResponse()` in executor tests should use the new canonical values (`'tool_calls'`, `'stop'`) since it creates `LlmResponse` objects directly (which should already be normalized). Separate `AnthropicApiClientTest` tests should verify the normalization itself.

---

## Sources

### Primary (HIGH confidence)
- openai-php/client GitHub source — `Factory.php`, `BaseUri.php`, `CreateResponseChoice.php`, `CreateResponseMessage.php`, `CreateResponseToolCall.php`, `CreateResponseToolCallFunction.php` — verified 2026-04-08
- docs.ollama.com/api/tags — `/api/tags` response shape — verified 2026-04-08
- openrouter.ai/docs/app-attribution — attribution headers — verified 2026-04-08
- developers.openai.com/api/docs/guides/function-calling — tool call multi-turn message format — verified 2026-04-08

### Secondary (MEDIUM confidence)
- packagist.org/packages/openai-php/client — v0.19.1 latest stable (2026-03-17) — verified via Packagist
- docs.ollama.com/api/openai-compatibility — Ollama base URL and `tool_choice` limitation — verified 2026-04-08

### Tertiary (LOW confidence)
- None — all critical claims verified from primary sources

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — version confirmed via Packagist; source code inspected
- Architecture: HIGH — executor source read directly; content block format confirmed in code
- Pitfalls: HIGH — stopReason check sites confirmed by grep; message format mismatch confirmed by reading executor code and OpenAI API docs

**Research date:** 2026-04-08
**Valid until:** 2026-05-08 (openai-php/client moves quickly; re-verify before implementing if delayed)

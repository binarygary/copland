# Stack Research: v1.1 Multi-Provider LLM + Asana

**Project:** Copland v1.1
**Researched:** 2026-04-08
**Confidence:** MEDIUM overall (training knowledge; flagged per item)

---

## Ollama / OpenAI-compatible

Ollama exposes a local HTTP server at `http://localhost:11434` with an OpenAI-compatible endpoint at `/v1/chat/completions`. The `/v1` path is the standard compatibility layer. [HIGH confidence]

**Recommended package:** `openai-php/client ^0.10`

```php
$client = OpenAI::factory()
    ->withBaseUri('http://localhost:11434/v1')
    ->withApiKey('ollama') // dummy — Ollama ignores it
    ->make();

$response = $client->chat()->create([
    'model'    => 'llama3.2',
    'messages' => [['role' => 'user', 'content' => $prompt]],
]);
// $response->choices[0]->message->content
// $response->usage->promptTokens / completionTokens
```

**Alternative — raw GuzzleHttp:** Since GuzzleHttp is already a transitive dependency, the `/v1/chat/completions` endpoint can be called directly without adding a package. This matches the `GitHubService` pattern. Viable for a non-streaming use case, but `openai-php/client` handles request/response serialization more cleanly.

**Recommendation:** Use `openai-php/client` for both Ollama and OpenRouter — one package, same endpoint shape.

---

## OpenRouter

OpenRouter exposes an OpenAI-compatible API at `https://openrouter.ai/api/v1`. Requires a Bearer API key. [HIGH confidence]

```php
$client = OpenAI::factory()
    ->withBaseUri('https://openrouter.ai/api/v1')
    ->withApiKey($config->openRouterApiKey())
    ->withHttpHeader('HTTP-Referer', 'https://github.com/binarygary/copland')
    ->withHttpHeader('X-Title', 'Copland')
    ->make();
```

No separate package needed — same `openai-php/client` covers both Ollama and OpenRouter.

---

## Asana API

**Auth:** Personal Access Token (PAT). Bearer token in `Authorization` header. Stored in `~/.copland.yml`. [HIGH confidence]

**Recommendation: Use raw GuzzleHttp — do NOT add `asana/asana` official SDK.**

Rationale:
- Copland needs only two Asana operations: fetch tasks from a project, post a comment with the PR URL.
- `GitHubService` already demonstrates this exact pattern (GuzzleHttp + Bearer token + JSON decode). `AsanaService` mirrors it identically.
- The official `asana/asana` SDK is auto-generated and heavyweight (~300 generated classes) — overkill for two endpoints.
- Avoids adding sub-dependencies to the PHAR build.

**Endpoints needed:**
- `GET https://app.asana.com/api/1.0/projects/{project_gid}/tasks?opt_fields=name,notes,assignee,completed` — fetch open tasks
- `POST https://app.asana.com/api/1.0/tasks/{task_gid}/stories` — post a comment with PR link

**Rate limits:** Asana free: 150 req/min per user. Pro: 1500/min. Overnight pattern makes this a non-issue. [MEDIUM confidence]

**Config shape for `~/.copland.yml`:**

```yaml
asana_api_key: "1/..."

asana_projects:
  - project_gid: "123456789"
    repo: owner/repo
```

---

## What NOT to Add

| Package | Why Not |
|---------|---------|
| `asana/asana` (official SDK) | Auto-generated bloat; 2-endpoint use case doesn't warrant it |
| Any LangChain-PHP / LLM framework | Immature in PHP ecosystem, overkill for known providers |
| A second HTTP client | GuzzleHttp already present transitively |
| Replace `anthropic-ai/sdk` with OpenAI-compat | Anthropic's tools/caching API is not in the OpenAI spec; would break existing prompt caching |

---

## Packages to Add

| Package | Constraint | Purpose |
|---------|-----------|---------|
| `openai-php/client` | `^0.10` | Ollama + OpenRouter (both OpenAI-compat) |

GuzzleHttp for Asana is already present transitively. No other new packages needed.

**Note:** The `openai-php/client` version constraint `^0.10` is from training data (August 2025). Verify current stable version on Packagist before locking.

---

## Integration Notes

**Key translation problem:** Existing services consume Anthropic SDK object shape (`$response->content[0]->text`, `$response->usage->inputTokens`). The OpenAI client returns `$response->choices[0]->message->content` and `$response->usage->promptTokens`. An `OpenAiCompatClient` wrapper normalizes this so the three service classes stay unchanged.

**Prompt caching:** Anthropic-specific. `CacheControlEphemeral` and `TextBlockParam` types must be stripped when routing to Ollama/OpenRouter. The `OpenAiCompatClient` omits them from requests.

**Tool calls:** Anthropic uses `input_schema` in tool definitions; OpenAI-compat uses `parameters`. The `OpenAiCompatClient` must translate the tool schema format. This is non-trivial for the executor phase — flag for dedicated phase research.

**Cost tracking:** `AnthropicCostEstimator` hard-codes Anthropic pricing. For Ollama (local, $0), return zero-cost `ModelUsage`. For OpenRouter, pricing varies per model — report raw token counts and mark cost as "n/a".

---

## Open Questions

1. **Tool schema translation:** Verify exact field mapping between Anthropic (`input_schema`) and OpenAI (`parameters`) before implementing the executor adapter.
2. **`openai-php/client` exact version:** Confirm current stable on Packagist before locking the constraint.
3. **Asana `opt_fields` field names:** Confirm `notes` vs `description` for task body in Asana API v1.
4. **Executor tool loop with Ollama:** Many local models don't reliably return structured JSON for multi-round tool use. Requires model capability guidance in docs.

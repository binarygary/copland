# Pitfalls Research: Multi-Provider LLM + Asana

**Project:** Copland v1.1 — Adding Ollama, OpenRouter, and Asana to an existing overnight PHP CLI agent
**Researched:** 2026-04-08
**Overall confidence:** HIGH for structural pitfalls (grounded in codebase review); MEDIUM for Ollama/OpenRouter specifics (training knowledge, no live docs available during research); HIGH for Asana API behavior (stable, well-documented)

---

## LLM Provider Abstraction

### Pitfall A1: The Abstraction Interface Will Leak Anthropic-Specific Concepts

**What goes wrong:** The current codebase has five Anthropic SDK-specific concepts baked directly into `ClaudeExecutorService`:

1. `CacheControlEphemeral::with()` — prompt caching control attached to `TextBlockParam` objects. There is no equivalent in OpenAI-compatible APIs (Ollama, OpenRouter). The system prompt is currently built as `array<TextBlockParam>`, not as a plain string.
2. `$response->usage->cacheCreationInputTokens` and `$response->usage->cacheReadInputTokens` — Anthropic-specific token fields. Other providers return `prompt_tokens` / `completion_tokens` (OpenAI schema) or nothing at all.
3. `AnthropicMessageSerializer::assistantContent()` serializes `thinking`, `redacted_thinking`, and `citations` block types. These are Anthropic-specific and do not exist in OpenAI-compatible response schemas.
4. `stopReason === 'end_turn'` — Anthropic calls it `end_turn`. OpenAI-compatible providers use `stop` as the finish reason. If the abstraction passes through the raw finish reason and the executor loop checks `=== 'end_turn'`, Ollama and OpenRouter runs will never terminate normally — the loop will exhaust `maxRounds` and return `success: false` every time.
5. The `tools` parameter format uses `input_schema` as the key (`buildTools()` at line 403 of `ClaudeExecutorService.php`). The OpenAI function-calling format uses `parameters`. Sending Anthropic-format tool definitions to an OpenAI-compatible endpoint causes a 400 or silent tool-call failure.

**Why it happens:** `AnthropicApiClient` wraps the Anthropic PHP SDK directly. The SDK's response objects are PHP class instances with named properties specific to Anthropic's schema. The executor is written against those property names directly.

**Consequences:** A naive "just switch the client" abstraction will produce a provider that returns results but silently fails: the executor loop never exits, tools are not called, and cost tracking reports zero for cache fields.

**Prevention:**
- Define a `LlmResponse` value object internal to Copland that normalises the response before the executor sees it. Fields: `content` (array of typed blocks), `stopReason` (normalised to `end_turn` | `tool_use` | `error`), `usage` (a `ModelUsage` object with zeros for unsupported fields).
- The provider adapter's job is to translate provider-specific responses into this `LlmResponse`. The executor never touches the raw SDK response.
- The tool definition builder must also be provider-aware. Anthropic format (`input_schema`) and OpenAI format (`parameters`) differ in key name. The adapter layer translates outbound tool definitions.
- Confidence: HIGH (these property differences are stable API contracts).

---

### Pitfall A2: AnthropicCostEstimator is Hardcoded to Anthropic Model Name Substrings

**What goes wrong:** `AnthropicCostEstimator::ratesForModel()` does substring matching on the model name (`haiku`, `sonnet`, `opus`). OpenRouter model names look like `anthropic/claude-3-haiku`, `meta-llama/llama-3-70b-instruct`, `mistralai/mixtral-8x7b`. Ollama model names look like `llama3.2`, `qwen2.5-coder:7b`, `mistral:latest`.

None of these match the existing substrings, so `ratesForModel()` falls through to the default `[3.0, 15.0]` Sonnet rate. Ollama runs — which are free — will be reported as costing $3/1M tokens. OpenRouter runs using Llama or Mistral models (which cost a fraction of Sonnet) will be massively over-reported.

For an overnight agent, cost reporting is how the operator detects runaway behavior. Incorrect cost reporting hides real cost anomalies.

**Prevention:**
- Extend the cost model to accept per-provider rate overrides via config. The `~/.copland.yml` global config already loads model names — add an optional `cost_per_million_input` / `cost_per_million_output` field per model entry.
- For Ollama, default to zero cost. For OpenRouter, the API response includes `usage.cost` in USD directly — use it instead of estimating.
- Rename `AnthropicCostEstimator` to `ModelCostEstimator` at the abstraction boundary.
- Confidence: HIGH (code is directly readable; OpenRouter does return cost in response).

---

### Pitfall A3: Prompt Caching Must Be Silently Disabled for Non-Anthropic Providers

**What goes wrong:** `executeWithPolicy()` builds the system prompt as a `TextBlockParam` with `CacheControlEphemeral::with()` attached (lines 52-57 of `ClaudeExecutorService.php`). This is Anthropic SDK object construction. Passing this to an OpenAI-compatible endpoint either throws a type error in PHP or sends malformed JSON.

If the abstraction strips it to a plain string for non-Anthropic providers, caching is silently lost. That is fine functionally but breaks cost tracking (the cache token fields will always be zero, which is correct for providers that do not cache).

**Prevention:**
- The system prompt builder must be provider-aware. Anthropic path: `TextBlockParam` with `CacheControlEphemeral`. OpenAI path: plain string.
- This is a one-if-block decision in the provider adapter, not a complex concern — but it must be deliberate, not forgotten.
- Document in config which providers support caching so users understand why cache tokens show zero for Ollama/OpenRouter runs.
- Confidence: HIGH.

---

### Pitfall A4: Retry Logic is Coupled to Anthropic Error Types

**What goes wrong:** `AnthropicApiClient::isRetryable()` checks HTTP status codes (429, 5xx). This logic works for any HTTP-based provider. However, `extractStatusCode()` uses `method_exists($e, 'getResponse')` — it is designed around Guzzle's `BadResponseException` shape, which is what the Anthropic SDK throws.

If the Ollama adapter or OpenRouter adapter throws different exception types (generic `RuntimeException`, Guzzle exceptions with different structures, or SDK-specific exceptions), `extractStatusCode()` returns `'network_error'` for all of them — which happens to be retryable. Every error from Ollama (including "model not loaded", "out of memory") will be retried three times.

**Prevention:**
- Standardise the exception contract at the provider adapter boundary: adapters must throw `ProviderException` with HTTP status code attached, or a `ProviderNetworkException` for connection-level failures.
- The retry logic in `AnthropicApiClient` (to be renamed `LlmApiClient`) then operates on a clean exception hierarchy, not on structural duck-typing.
- Confidence: HIGH (code is directly readable).

---

## Ollama

### Pitfall B1: Most Ollama Models Do Not Support Tool Use

**What goes wrong:** The executor's entire loop depends on tool use (function calling). The executor sends five tool definitions to the LLM and then processes `tool_use` blocks in the response. If the model does not support tool use, it will either:

1. Ignore the tools entirely and return a text response describing what it would do ("I would call read_file to...") — the executor sees no `tool_use` blocks, finds `stopReason === end_turn` (or equivalent), and returns `success: true` with an empty summary of "here is my plan".
2. Return malformed JSON attempting to imitate a tool call inside a text block — the executor sees a text block, finds no tool calls, and terminates.
3. Throw a 400 error from Ollama because the `/api/chat` endpoint received a `tools` array with a model that does not declare tool support.

As of mid-2025, Ollama's tool support is limited to models that explicitly declare it. Models that support tools in Ollama: `llama3.1`, `llama3.2`, `qwen2.5-coder`, `mistral-nemo`, `command-r`. Models that do NOT support tools: most base models, `codellama`, older `llama2`, `phi3` (partial), `gemma` family (partial). A user who configures `ollama_model: codellama:7b` will get silent executor failure every time.

**Consequences:** Overnight runs with unsupported models produce `success: true` with an empty diff, then open a PR with a nonsense description. This is worse than a clear failure.

**Prevention:**
- Maintain a whitelist of Ollama models known to support tool use. Warn at startup (before the run) if the configured Ollama model is not on the list.
- On executor startup, if provider is Ollama, send a minimal tool-use probe: one tool definition, one message, check that the response contains a `tool_use` / `tool_calls` block. If not, abort with a clear error before spending any tokens on selector/planner.
- The `copland doctor` command (already suggested in v1.0 PITFALLS) should include a provider capability check.
- Confidence: MEDIUM (Ollama tool support list based on training knowledge as of mid-2025; may have expanded).

---

### Pitfall B2: Ollama May Not Be Running When the Overnight Job Fires

**What goes wrong:** Ollama is a local HTTP server (`localhost:11434`). It runs as a user application, not a system daemon. On macOS, Ollama starts when the user opens it and may stop when the system sleeps aggressively or the user quits the app. A cron job that fires at 2am on a MacBook that sleeps at midnight will find Ollama unavailable.

There is no equivalent concern for Anthropic (cloud API, always available) or OpenRouter (cloud API, always available). Ollama is uniquely fragile for overnight use.

Additionally, when Ollama is running but the requested model has not been pulled, the API returns a 404 or error response. This is different from "Ollama is down" and requires different handling.

**Consequences:** The entire overnight run fails at the first API call. No PR, no log, no signal. The issue stays labeled `agent-ready` forever.

**Prevention:**
- On startup (before selector), check Ollama reachability: `GET http://localhost:11434/api/tags`. Timeout after 2 seconds. If unreachable, log and exit with a clear message.
- Check that the configured model appears in the tags response. If not, log "model not pulled: run `ollama pull <model>`" and exit.
- For overnight use, instruct users to install Ollama as a persistent background service (`ollama serve` via launchd). Document this in the setup guide.
- Consider making Ollama an "interactive use only" provider and documenting that it is not recommended for unattended overnight runs unless the user configures it as a persistent service.
- Confidence: HIGH (macOS application lifecycle behavior is well-understood).

---

### Pitfall B3: Local Model Context Windows Are Smaller and Variable

**What goes wrong:** The executor loop grows `$messages` with every round. With Anthropic Claude Sonnet, the context window is 200K tokens — generous enough that most runs never approach the limit. Common Ollama models have much smaller context windows:

- `llama3.2:3b` — 128K context, but effective quality degrades long before that
- `qwen2.5-coder:7b` — 32K default context (configurable, but default matters for users who do not read docs)
- `mistral:7b` — 32K context
- `codellama:7b` — 16K context

The executor reads files, appends them to messages, and retransmits everything each round. A run that comfortably fits in Anthropic's context window will overflow a 32K context window in 3-4 rounds on a medium-complexity task.

Ollama does not return a `context_length_exceeded` error in the same predictable way as Anthropic. Depending on the model runner, it may silently truncate the input (the model hallucinates about the missing context) or return a 400.

**Prevention:**
- Surface the provider's context limit in the adapter interface. The executor should track running token count and abort gracefully if approaching 80% of the provider's limit.
- For Ollama providers, default `max_executor_rounds` to 6 (down from 12) and `read_file_max_lines` to 150 (down from 300) in the provider adapter defaults.
- Allow users to override these per-provider in `~/.copland.yml`.
- Confidence: MEDIUM (context window sizes based on training knowledge; Ollama allows `num_ctx` override that changes defaults).

---

### Pitfall B4: Ollama Response Format Differs From Anthropic Format

**What goes wrong:** Ollama's `/api/chat` endpoint returns OpenAI-compatible JSON, not Anthropic-format JSON. Key differences:

- Tool calls appear as `message.tool_calls[].function.name` and `message.tool_calls[].function.arguments` (a JSON string, not an object). Anthropic returns `block.type === 'tool_use'` with `block.id`, `block.name`, `block.input` (an object).
- The `tool_use_id` for tool results (sent back by the client) is `tool_call_id` in OpenAI format vs `tool_use_id` in Anthropic format.
- There is no `id` on individual tool calls in some Ollama model implementations — the field may be absent or a numeric index.
- Usage statistics are `usage.prompt_tokens` / `usage.completion_tokens`, not `usage.inputTokens` / `usage.outputTokens`.
- Finish reason is `finish_reason: "tool_calls"` (not `stop_reason: "tool_use"`) and `finish_reason: "stop"` (not `stop_reason: "end_turn"`).

The `AnthropicMessageSerializer` which serializes assistant content back into the next message's turn will break entirely when processing OpenAI-format responses, since it checks `$block->type === 'tool_use'` and the block structure does not exist.

**Prevention:**
- The provider adapter must translate the Ollama/OpenAI response to the internal `LlmResponse` value object before returning it to the executor. This is the core value of the abstraction layer.
- The message serializer (building the next round's message array) must also be provider-aware: Anthropic and OpenAI formats have different shapes for `tool_result` messages.
- Confidence: HIGH (OpenAI vs Anthropic API schema differences are stable and well-documented).

---

## OpenRouter

### Pitfall C1: Model Naming Convention Includes Provider Prefix — Config Will Be Confusing

**What goes wrong:** OpenRouter model names are formatted as `{provider}/{model-name}` — for example `anthropic/claude-3-haiku-20240307`, `meta-llama/llama-3.1-70b-instruct`, `mistralai/mixtral-8x7b-instruct`. These differ from both Anthropic model IDs (`claude-haiku-4-5`) and Ollama model IDs (`llama3.1:70b`).

The existing `AnthropicCostEstimator::ratesForModel()` does substring matching on model names. OpenRouter model names will not match any existing substring (e.g., `meta-llama/llama-3.1-70b-instruct` does not contain `haiku`, `sonnet`, or `opus`).

Separately, OpenRouter allows routing the same request to a specific provider or to the cheapest available provider for a model. The model name format for "cheapest provider" routing is just the model name; for a specific provider it is `{provider}/{model}`. Users who configure `openrouter_model: gpt-4o` will get a different model than users who configure `openrouter_model: openai/gpt-4o`.

**Prevention:**
- Document the exact OpenRouter model name format in the config reference and in error messages.
- Add a config validation step: if the provider is OpenRouter and the model name does not contain a `/`, warn that this uses OpenRouter's "auto-route to cheapest provider" behavior and may produce inconsistent results.
- For cost tracking, use the `usage.cost` field in OpenRouter responses (OpenRouter returns actual USD cost per request) rather than estimating from token counts.
- Confidence: HIGH (OpenRouter API schema is stable and the `usage.cost` field is documented).

---

### Pitfall C2: OpenRouter Rate Limits Differ Per Underlying Model

**What goes wrong:** OpenRouter aggregates many providers, each with their own rate limits. The rate limit a request hits is not OpenRouter's rate limit — it is the underlying provider's rate limit, and it varies by model. A request to `anthropic/claude-3-haiku` via OpenRouter hits Anthropic's rate limits. A request to `mistralai/mixtral-8x7b-instruct` hits Mistral's rate limits. These are different values and change without notice.

OpenRouter returns a 429 when a rate limit is hit, but the `x-ratelimit-*` headers in the response reflect the underlying provider's limits, not OpenRouter's. The retry delay embedded in `Retry-After` may be very long (Anthropic rate limits on free tiers can be 1-minute waits).

The existing retry logic retries up to 3 times with exponential backoff capped at 4 seconds (`1 * 2^2 = 4s`). A rate limit with a 60-second `Retry-After` will exhaust all 3 retries in 7 seconds and then fail the run.

**Prevention:**
- Parse the `Retry-After` header from 429 responses and sleep for that duration before retrying (capped at a configurable maximum, e.g., 120 seconds for overnight runs where wall-clock time is unimportant).
- For overnight runs, a 60-second retry wait is acceptable — surface this as a log line so the morning review shows "waited 60s for rate limit" rather than appearing as a failure.
- Confidence: HIGH (HTTP `Retry-After` header behavior is a standard RFC).

---

### Pitfall C3: OpenRouter May Return Degraded Tool-Use Support for Some Models

**What goes wrong:** OpenRouter routes requests to underlying providers and normalises request/response formats. Tool use support depends on whether the underlying model supports it. Some models available on OpenRouter support tool use natively; others do not, and OpenRouter may attempt to emulate tool use via prompt injection — which produces text that looks like a tool call but is not a structured response.

OpenRouter has a model metadata API (`GET /api/v1/models`) that includes a `tools` capability flag per model. A model without the `tools` flag will not reliably call tools, even if you include a `tools` array in the request.

The failure mode is the same as Ollama B1: the executor sees no `tool_use` blocks, reaches `end_turn` / `stop`, and returns `success: true` with an empty diff.

**Prevention:**
- On provider startup, if using OpenRouter, call `/api/v1/models` and check the `tools` capability flag for the configured model. If `tools: false`, abort with a clear message.
- Alternatively, maintain a list of OpenRouter-recommended models known to support structured tool use: Claude models via OpenRouter, GPT-4o, Llama 3.1 70B/405B.
- Confidence: MEDIUM (OpenRouter model capability API behavior based on training knowledge; verify with OpenRouter docs during implementation).

---

### Pitfall C4: OpenRouter Authentication Uses `Bearer` Token but Model-Level Auth Varies

**What goes wrong:** OpenRouter authentication uses `Authorization: Bearer {OPENROUTER_API_KEY}` — the same HTTP pattern as Anthropic and OpenAI. However, OpenRouter also requires `HTTP-Referer` and `X-Title` headers to identify the application. Requests without these headers may be deprioritized or rejected by some underlying providers.

Separately, some OpenRouter models require the user to have an account with the underlying provider and have their own credits there (e.g., certain Cohere or AWS Bedrock models). Attempting to use these models without those credentials returns a 402 or 403.

**Prevention:**
- Always include `HTTP-Referer: copland-agent` and `X-Title: Copland` headers in OpenRouter requests.
- Document in the config reference that not all OpenRouter models are available to all users.
- Treat 402 and 403 responses as non-retryable (do not retry on auth/billing errors).
- Confidence: MEDIUM (OpenRouter header requirements are documented; specific model restrictions vary).

---

## Asana Integration

### Pitfall D1: Asana PAT Scopes Are Not Granular — Over-Permissioning Is the Default

**What goes wrong:** Asana Personal Access Tokens (PATs) are not scoped. A PAT has full access to everything the user account can access: all workspaces, all projects, all tasks. There is no way to create a PAT restricted to read-only task access on a single project.

For Copland's use case (read tasks, add a comment on completion), the minimal required operations are:
- `GET /projects/{id}/tasks` — list tasks
- `GET /tasks/{id}` — read task details
- `POST /tasks/{id}/stories` — add a comment

The PAT stored in `~/.copland.yml` grants write access to every task, project, and workspace the user owns. A bug in Copland's Asana integration could modify or delete tasks it was not intended to touch.

**Prevention:**
- In the `AsanaTaskService`, only ever call the three endpoints listed above. Add an explicit allowlist of Asana API operations in code — do not build a general-purpose Asana client.
- Add a `copland doctor --provider asana` check that lists which projects the PAT can access, so the user can audit the exposure surface.
- Store the PAT in the same `~/.copland.yml` alongside the Anthropic API key — it never goes in repo-level config.
- Confidence: HIGH (Asana PAT behavior is stable and well-documented).

---

### Pitfall D2: Asana Task Selection Logic Must Mirror GitHub Issue Selection — But Tasks Are Not Issues

**What goes wrong:** GitHub issues have: `labels` (structured filtering), `number` (stable ID), `body` (description), `state` (open/closed), and a clear `agent-ready` label convention that already exists in the codebase.

Asana tasks have: `tags` (similar to labels, but must be looked up by name via a separate API call), `gid` (opaque string ID, not a number), `notes` (the description), `completed` (boolean), and `custom_fields` (project-specific fields that vary by workspace setup).

Pitfalls:
1. **Tag lookup requires an extra API call.** There is no `GET /tasks?tag_name=agent-ready`. You must first `GET /workspaces/{id}/tags` to find the tag GID for "agent-ready", then `GET /tasks?tag={gid}`. If the tag does not exist in the workspace, the project has no filterable tasks.
2. **Custom fields are workspace-specific.** A task's custom fields cannot be assumed — the field names and allowed values vary by project. The selector prompt must be told which custom field indicates "ready for automation" if tags are not used.
3. **Task GID is a string, not an integer.** The existing `SelectionResult` stores `selectedIssueNumber` as `?int`. Asana task GIDs are strings like `"1208345678901234"`. The data model must accommodate both.
4. **Task comments use `stories` API.** Adding a comment is `POST /tasks/{gid}/stories` with `{"text": "..."}` — not the same endpoint pattern as GitHub issue comments.

**Prevention:**
- Define an `AgentTask` value object that normalises GitHub issues and Asana tasks to a common shape: `id` (string), `title`, `description`, `source` (enum: github|asana), `raw` (the original response for provider-specific operations).
- `SelectionResult` stores `selectedTaskId` as `string` (not `selectedIssueNumber` as `int`) to accommodate Asana GIDs.
- The Asana service must accept a configured tag name and resolve it to a GID on first use (cache in memory for the run).
- Document the "agent-ready" tag convention for Asana in the setup guide.
- Confidence: HIGH (Asana API behavior is stable and well-documented).

---

### Pitfall D3: Asana API Rate Limit Is Low and Poorly Documented

**What goes wrong:** Asana's API rate limit is 1500 requests per minute per user token. This sounds high, but pagination of task lists is per-page, and each page of results is one request. For a project with 100+ tasks, listing all tasks across multiple pages, then reading each task's details, can consume 20-30 requests before the selector even starts.

More importantly, Asana enforces a "burst" rate limit that is much lower than the sustained limit. Rapid sequential requests (no delay between them) can trigger 429s even when well under the 1500/minute ceiling.

Asana 429 responses include a `Retry-After` header, but it may be as long as 30 seconds for burst violations.

**Prevention:**
- Add a 100ms delay between Asana API calls in the task listing phase.
- Respect the `Retry-After` header on 429 responses.
- Cache the resolved tag GID and project task list for the duration of a single run (do not re-fetch).
- Confidence: MEDIUM (rate limits based on training knowledge; Asana's docs are not always precise on burst behavior).

---

### Pitfall D4: Asana Pagination Uses Offset Tokens, Not Page Numbers

**What goes wrong:** The existing GitHub `getIssues()` fetches page 1 with `?per_page=50&page=1` query parameters. Asana uses cursor-based pagination: each response includes a `next_page.offset` field, and you fetch the next page by passing `?offset={token}`. There is no `page=2` equivalent.

If the Asana service is written by someone familiar only with GitHub's pagination style, it will either miss pages or silently return only the first page of tasks.

**Prevention:**
- Implement explicit pagination in `AsanaTaskService::getTasks()` that loops while `next_page` is present in the response. Add a max-pages cap (e.g., 5 pages, 100 tasks total) to prevent runaway fetching.
- Confidence: HIGH (Asana pagination behavior is stable and documented).

---

### Pitfall D5: Asana Task Body Is Plain Text With No Markdown Rendering

**What goes wrong:** The selector and planner prompts currently receive GitHub issue bodies, which are Markdown. The prompts may include instructions like "the issue body uses Markdown formatting." Asana task `notes` are plain text (no Markdown rendering in Asana's web UI). The selector/planner should receive this as plain text, but if the prompts give Markdown-specific instructions, model behavior may degrade on plain-text input.

Conversely, the PR comment added to the Asana task (linking back to the GitHub PR) will be rendered as plain text in Asana. URLs will not be auto-linked unless the comment uses Asana's rich text format (`html_text` field instead of `text`).

**Prevention:**
- Strip Markdown-specific language from selector/planner prompts at the source adapter level, or pass a `source_format: plain_text` hint to the prompt template.
- Use `html_text` for the Asana comment to ensure the PR URL is rendered as a clickable link.
- Confidence: HIGH (Asana API behavior for `text` vs `html_text` is documented).

---

## Overnight / Unattended Operation

### Pitfall E1: Silent Failure Modes Are Worse With Multiple Providers

**What goes wrong:** With a single Anthropic provider, a failure is a known API error with a predictable shape. With three providers (Anthropic, Ollama, OpenRouter), new silent failure modes appear:

- Ollama is not running: the first API call throws a connection refused exception. If this is caught and treated as a retryable network error, the run retries 3 times with 7 seconds of total delay, then exits with "API failed after 3 attempts." The run log shows "API failure" — it is not clear whether this is a transient Anthropic hiccup or Ollama being offline.
- OpenRouter returns a valid HTTP 200 with a model error embedded in the response body (some OpenRouter models return errors as JSON with `error.message` inside a 200 response). The executor sees a `text` block containing the error message, no tool calls, and an `end_turn` — returns `success: true` with a summary that is actually an error message.
- Ollama model without tool support returns `success: true` every time with no diff.

All three of these return `success: true` or exit 0, which means no alert is raised in the morning review.

**Prevention:**
- After any run that returns `success: true`, verify the git diff is non-empty. A successful execution with zero file changes is always suspicious — treat it as a failure with reason "no files changed after reported success."
- The structured run log should include the provider name and model used so the morning review can correlate "zero changes" with "used Ollama".
- Confidence: HIGH (failure mode analysis directly from codebase + provider behavior).

---

### Pitfall E2: Provider Availability Gaps Mean Some Repos Never Get Processed

**What goes wrong:** Copland processes repos sequentially in the multi-repo runner. If the first repo uses Ollama and Ollama is unavailable, that repo's run fails. The runner then moves to the next repo (which may use Anthropic) and succeeds. The Ollama-dependent repo is silently skipped every night.

From the morning review perspective, the Anthropic repo has PRs. The Ollama repo has nothing. Without per-provider availability logging, the owner cannot distinguish "no suitable issues found" from "provider offline."

**Prevention:**
- The run log entry for each repo must include the provider name.
- A provider connectivity check should run before the full orchestration loop, not inside it. If Ollama is offline, log a clear "skipping repos configured for Ollama: repo1, repo2 — Ollama not reachable" before attempting any runs.
- Confidence: HIGH.

---

### Pitfall E3: Asana PAT Expiry Has No System Alert

**What goes wrong:** Asana PATs do not expire by default, but they can be revoked via the Asana UI. If a PAT is revoked (e.g., security audit, accidental revocation), the Asana API returns a 401. This happens silently at 2am. The run log shows an auth error, the issue stays un-processed, and the user may not notice for days.

There is no equivalent risk with GitHub auth because Copland uses `gh auth token` — if `gh` auth is broken, the `gh` CLI itself provides a clear message.

**Prevention:**
- On startup (before the run), test Asana auth with a lightweight call (`GET /users/me`). If it fails with 401, log a clear "Asana authentication failed — check PAT in ~/.copland.yml" and skip all Asana tasks for this run.
- Confidence: HIGH (HTTP 401 behavior is universal).

---

### Pitfall E4: Per-Provider Config Complexity Increases Config Error Rate

**What goes wrong:** The v1.1 `~/.copland.yml` will need new fields for each provider:

```yaml
provider: openrouter            # or anthropic, ollama
openrouter_api_key: sk-or-...
openrouter_model: anthropic/claude-3-haiku-20240307
ollama_base_url: http://localhost:11434
ollama_model: qwen2.5-coder:7b
```

And per-repo overrides in `.copland.yml`:

```yaml
provider: ollama   # override global default for this repo
ollama_model: llama3.1:8b
```

Mistakes: using `anthropic` model names with `ollama` provider, mixing up OpenRouter and Anthropic key formats, forgetting to set `ollama_base_url` on a non-standard port.

The tool currently has no startup config validation. A misconfigured provider fails at the first API call, 30 seconds into a run that has already paid selector tokens.

**Prevention:**
- Add a config validation step in `GlobalConfig` that runs before any API call. Validate: provider name is a known value, required key for the provider is present, model name format matches the provider convention (warn if Anthropic model name is used with Ollama).
- `copland doctor` should test-call each configured provider and report capability (auth OK, model available, tool-use supported).
- Confidence: HIGH.

---

## Prevention Strategies

| Pitfall | Category | Phase Priority | Concrete Action |
|---------|----------|----------------|----------------|
| A1: Anthropic concepts leak through abstraction | Architecture | Must-do before any provider | Define internal `LlmResponse` VO; adapters translate before executor sees response |
| A2: Cost estimator breaks for non-Anthropic models | Tracking | Must-do | Use OpenRouter's `usage.cost` field directly; make Ollama cost = $0 |
| A3: Prompt caching breaks for non-Anthropic | Architecture | Must-do | Provider-aware system prompt builder; non-Anthropic uses plain string |
| A4: Retry logic tied to Anthropic exception types | Reliability | Must-do | Standardise exception hierarchy at adapter boundary |
| B1: Ollama models without tool-use | Correctness | Must-do | Capability probe on startup; model whitelist in docs |
| B2: Ollama offline at 2am | Reliability | Must-do | Reachability check before run starts; launchd guide for persistent Ollama |
| B3: Small context windows in local models | Reliability | Must-do | Provider-aware default `max_executor_rounds` and `read_file_max_lines` |
| B4: OpenAI vs Anthropic response format | Architecture | Must-do before any provider | Provider adapter normalises response format |
| C1: OpenRouter model naming confusion | UX | Should-do | Config validation warns on malformed model names |
| C2: OpenRouter rate limits per underlying model | Reliability | Should-do | Parse `Retry-After` header; allow long waits in overnight mode |
| C3: OpenRouter degraded tool-use for some models | Correctness | Should-do | Check `/api/v1/models` capability flag on startup |
| C4: OpenRouter requires extra headers | Auth | Must-do | Always include `HTTP-Referer` and `X-Title` headers |
| D1: Asana PAT over-permissioning | Security | Must-do | Explicit API operation allowlist in `AsanaTaskService` |
| D2: Task != Issue (IDs, fields, shapes) | Architecture | Must-do | `AgentTask` VO normalises both sources; `selectedTaskId` as string |
| D3: Asana burst rate limits | Reliability | Should-do | 100ms inter-request delay; respect `Retry-After` |
| D4: Asana cursor pagination | Correctness | Must-do | Loop on `next_page.offset`; cap at 5 pages |
| D5: Asana plain-text body vs GitHub Markdown | Quality | Nice-to-have | Strip Markdown hints from prompts; use `html_text` for PR comment |
| E1: Silent success on no diff | Overnight | Must-do | Post-execution: treat `success: true` + zero diff as failure |
| E2: Provider offline skips repos silently | Overnight | Should-do | Provider connectivity check before orchestration; per-provider log entries |
| E3: Asana PAT revocation silent | Overnight | Should-do | `GET /users/me` auth probe on startup |
| E4: Config error rate increases | Overnight | Must-do | Config validation before first API call; `copland doctor` provider tests |

---

## Sources

- Grounded in direct code review: `ClaudeExecutorService.php` (lines 52-57, 101-113, 118-119, 140, 404-458), `AnthropicApiClient.php`, `AnthropicMessageSerializer.php`, `AnthropicCostEstimator.php`, `ClaudeSelectorService.php`, `ClaudePlannerService.php`
- Anthropic vs OpenAI tool-call format differences: HIGH confidence (stable API contracts)
- Ollama tool-use model support: MEDIUM confidence (training knowledge as of mid-2025; verify `ollama.com/search?c=tools` during implementation)
- OpenRouter model metadata API and `usage.cost` field: MEDIUM confidence (training knowledge; verify at `openrouter.ai/docs`)
- Asana PAT scope, pagination, and rate limit behavior: HIGH confidence (stable, well-documented Asana REST API)
- macOS Ollama application lifecycle behavior: HIGH confidence

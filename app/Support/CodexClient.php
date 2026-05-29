<?php

namespace App\Support;

use App\Contracts\LlmClient;
use App\Data\LlmResponse;
use App\Data\LlmUsage;
use App\Data\SystemBlock;

/**
 * LlmClient adapter mapping a Copland complete() call to exactly one
 * CodexRunner::runStage() invocation against `codex exec`.
 *
 * Codex drives its own internal agent loop (sandboxed by --sandbox), so every
 * call is a terminal single round from Copland's perspective: prompt +
 * output-schema in, structured JSON out, with a single terminal "stop" response.
 * Copland's ExecutorPolicy does not run on this path — Codex's sandbox enforces
 * tool/file access.
 *
 * Consumes the same subset of the wide LlmClient interface as ClaudeCodeClient:
 *   - $messages: plain-string role=user contents concatenated
 *   - $systemBlocks: prepended as plain text
 *   - $tools: IGNORED (Codex's sandbox replaces the tool whitelist)
 *   - $model: IGNORED — the configured Copland model names are Claude names;
 *     the Codex model is set explicitly via the `model` llm-config key (or left
 *     to Codex's own default). Forwarding a Claude name to `codex -m` would fail.
 *   - $maxTokens: IGNORED
 */
class CodexClient implements LlmClient
{
    public function __construct(
        private CodexRunner $runner,
        private string $jsonSchema,
        private string $sandbox,
        private string $workspaceCwd,
        private ?string $modelOverride = null,
    ) {}

    /**
     * @param  array<array<string, mixed>>  $messages
     * @param  array<array<string, mixed>>  $tools  IGNORED
     * @param  SystemBlock[]  $systemBlocks
     */
    public function complete(
        string $model,
        int $maxTokens,
        array $messages,
        array $tools = [],
        array $systemBlocks = [],
    ): LlmResponse {
        $prompt = $this->buildPrompt($messages, $systemBlocks);

        $envelope = $this->runner->runStage(
            prompt: $prompt,
            jsonSchema: $this->jsonSchema,
            sandbox: $this->sandbox,
            cwd: $this->workspaceCwd,
            // Ignore the incoming (Claude) $model; use the explicit Codex model
            // override or fall through to Codex's configured default.
            model: $this->modelOverride,
        );

        $resultText = $this->unwrapStructured($envelope['structured_output']);
        $usage = $envelope['usage'];

        return new LlmResponse(
            content: [
                ['type' => 'text', 'text' => $resultText],
            ],
            stopReason: 'stop',
            usage: new LlmUsage(
                inputTokens: (int) ($usage['input_tokens'] ?? 0),
                outputTokens: (int) ($usage['output_tokens'] ?? 0),
                cacheWriteTokens: 0,
                cacheReadTokens: (int) ($usage['cached_input_tokens'] ?? 0),
                providerCostUsd: 0.0,
            ),
        );
    }

    /**
     * Concatenate system blocks then the plain-string role=user message
     * contents with "\n\n" separators. Assistant / tool_result blocks are
     * skipped — Codex drives its own loop and never round-trips to Copland.
     *
     * @param  array<array<string, mixed>>  $messages
     * @param  SystemBlock[]  $systemBlocks
     */
    private function buildPrompt(array $messages, array $systemBlocks): string
    {
        $parts = [];

        foreach ($systemBlocks as $block) {
            $parts[] = $block->text;
        }

        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }

            $content = $message['content'] ?? '';
            if (! is_string($content)) {
                continue;
            }

            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Render the structured result as the text block delivered to the stage.
     * The executor schema is `{summary: string}` whose payload is the summary
     * text itself — unwrap it so a raw JSON literal isn't posted to GitHub.
     * Selector / planner schemas yield objects the downstream service decodes
     * again, so those are re-encoded.
     */
    private function unwrapStructured(mixed $structured): string
    {
        if (
            is_array($structured)
            && count($structured) === 1
            && array_key_exists('summary', $structured)
            && is_string($structured['summary'])
        ) {
            return $structured['summary'];
        }

        return (string) json_encode($structured);
    }
}

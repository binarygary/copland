<?php

namespace App\Support;

use App\Contracts\LlmClient;
use App\Data\LlmResponse;
use App\Data\LlmUsage;
use App\Data\SystemBlock;

/**
 * LlmClient adapter that maps a Copland complete() call to exactly one
 * ClaudeCodeRunner::runStage() invocation against `claude -p`.
 *
 * Claude Code drives its own internal agent loop with its native tools
 * (Read / Edit / Bash / ...), so from Copland's perspective every call is a
 * terminal single round: prompt + allowed-tools whitelist in, structured JSON
 * out (with a single terminal "stop" response and provider cost on usage).
 *
 * The LlmClient interface is wide for the Anthropic / OpenAI-compat paths;
 * this adapter only consumes the subset claude-code can express:
 *   - $messages: plain-string role=user contents concatenated (assistant /
 *     tool_result blocks are skipped — there is no round-trip to Copland on
 *     this path)
 *   - $systemBlocks: prepended to the prompt as plain text (cache flag ignored)
 *   - $tools: IGNORED — the allowed-tools whitelist is set at construction
 *     time via the factory, not derived from Copland's tool schemas
 *   - $model: forwarded to `claude --model`
 *   - $maxTokens: IGNORED — `claude -p` controls budget via --max-budget-usd
 */
class ClaudeCodeClient implements LlmClient
{
    /**
     * @param  array<int, string>  $allowedTools  e.g. ['Read'] for planner stage
     */
    public function __construct(
        private ClaudeCodeRunner $runner,
        private string $jsonSchema,
        private array $allowedTools,
        private string $workspaceCwd,
        private ?float $maxBudgetUsd = null,
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
            allowedTools: $this->allowedTools,
            cwd: $this->workspaceCwd,
            model: $model,
            maxBudgetUsd: $this->maxBudgetUsd,
        );

        $resultText = is_string($envelope['result'])
            ? $envelope['result']
            : (string) json_encode($envelope['result']);

        return new LlmResponse(
            content: [
                ['type' => 'text', 'text' => $resultText],
            ],
            stopReason: 'stop',
            usage: new LlmUsage(
                inputTokens: 0,
                outputTokens: 0,
                cacheWriteTokens: 0,
                cacheReadTokens: 0,
                providerCostUsd: (float) $envelope['total_cost_usd'],
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    public function allowedTools(): array
    {
        return $this->allowedTools;
    }

    /**
     * Concatenate system blocks (plain text, cache flag stripped) and the
     * plain-string contents of role=user messages with "\n\n" separators.
     * Assistant + tool_result blocks are skipped — claude-code drives its
     * own internal loop and never round-trips to Copland.
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
                // Skip tool_result and other structured blocks.
                continue;
            }

            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }
}

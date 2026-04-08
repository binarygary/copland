<?php

namespace App\Support;

use App\Contracts\LlmClient;
use App\Data\LlmResponse;
use App\Data\LlmUsage;
use App\Data\SystemBlock;

/**
 * LlmClient implementation for Ollama and OpenRouter via openai-php/client.
 *
 * Handles bidirectional message format translation:
 * - Outbound: Anthropic message/tool format → OpenAI-compatible format
 * - Inbound: OpenAI response → Anthropic-style LlmResponse
 *
 * HTTP-Referer and X-Title headers for OpenRouter are baked into the
 * underlying client by LlmClientFactory (D-19); no per-call header injection needed.
 */
class OpenAiCompatClient implements LlmClient
{
    /**
     * Models known to support tool/function calling when used with Ollama.
     * Used by LlmClientFactory for probe/warning checks.
     */
    public const array TOOL_CAPABLE_MODELS = [
        'llama3.1',
        'llama3.1:latest',
        'llama3.1:8b',
        'llama3.1:70b',
        'llama3.2',
        'llama3.2:latest',
        'llama3.2:3b',
        'llama3.2:1b',
        'mistral',
        'mistral:latest',
        'mistral-nemo',
        'mistral-nemo:latest',
        'qwen2.5',
        'qwen2.5:latest',
        'qwen2.5:7b',
        'qwen2.5:14b',
        'command-r',
        'command-r:latest',
        'firefunction-v2',
        'firefunction-v2:latest',
    ];

    public function __construct(private object $client) {}

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
    ): LlmResponse {
        $params = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $this->translateMessages($messages, $systemBlocks),
        ];

        if ($tools !== []) {
            $params['tools'] = ToolSchemaTranslator::translateAll($tools);
        }

        $response = $this->client->chat()->create($params);

        $choice = $response->choices[0];

        return new LlmResponse(
            content: $this->mapContent($choice->message),
            stopReason: $choice->finishReason ?? 'stop',
            usage: $this->mapUsage($response->usage),
        );
    }

    /**
     * Translate Anthropic-style messages to OpenAI-compatible format.
     *
     * - SystemBlock[] → single system message prepended (cache flag stripped)
     * - role=assistant with tool_use blocks → tool_calls format
     * - role=user with tool_result blocks → multiple role=tool messages
     * - Plain string user messages → passed through unchanged
     */
    private function translateMessages(array $messages, array $systemBlocks): array
    {
        $translated = [];

        // Prepend system message from SystemBlocks (cache flag stripped, plain text only)
        if ($systemBlocks !== []) {
            $systemText = implode("\n\n", array_map(fn (SystemBlock $b): string => $b->text, $systemBlocks));
            $translated[] = ['role' => 'system', 'content' => $systemText];
        }

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Plain string content — pass through unchanged
            if (is_string($content)) {
                $translated[] = ['role' => $role, 'content' => $content];

                continue;
            }

            // role=user with tool_result blocks → one role=tool message per block
            if ($role === 'user' && is_array($content) && isset($content[0]['type']) && $content[0]['type'] === 'tool_result') {
                foreach ($content as $block) {
                    $blockContent = $block['content'] ?? '';
                    if (($block['is_error'] ?? false) === true) {
                        $blockContent = 'ERROR: '.$blockContent;
                    }

                    $translated[] = [
                        'role' => 'tool',
                        'tool_call_id' => $block['tool_use_id'],
                        'content' => $blockContent,
                    ];
                }

                continue;
            }

            // role=assistant with tool_use blocks → tool_calls format
            if ($role === 'assistant' && is_array($content) && isset($content[0]['type']) && $content[0]['type'] === 'tool_use') {
                $toolCalls = array_map(fn (array $block): array => [
                    'id' => $block['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'],
                        'arguments' => json_encode($block['input'] ?? []),
                    ],
                ], $content);

                $translated[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => $toolCalls,
                ];

                continue;
            }

            // role=assistant with text blocks → implode text
            if ($role === 'assistant' && is_array($content)) {
                $text = implode('', array_map(fn (array $block): string => $block['text'] ?? '', $content));
                $translated[] = ['role' => 'assistant', 'content' => $text];

                continue;
            }

            // Fallback — pass through as-is
            $translated[] = ['role' => $role, 'content' => $content];
        }

        return $translated;
    }

    /**
     * Build Anthropic-style content block array from OpenAI response message.
     * Returns text blocks and/or tool_use blocks.
     */
    private function mapContent(object $message): array
    {
        $blocks = [];

        if ($message->content !== null && $message->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message->content];
        }

        foreach ($message->toolCalls as $tc) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $tc->id,
                'name' => $tc->function->name,
                'input' => json_decode($tc->function->arguments, true) ?? [],
            ];
        }

        return $blocks;
    }

    /**
     * Build LlmUsage from OpenAI response usage.
     * cacheWrite and cacheRead are always 0 (not applicable to OpenAI-compat providers).
     */
    private function mapUsage(object $usage): LlmUsage
    {
        return new LlmUsage(
            inputTokens: $usage->promptTokens,
            outputTokens: $usage->completionTokens,
            cacheWriteTokens: 0,
            cacheReadTokens: 0,
        );
    }
}

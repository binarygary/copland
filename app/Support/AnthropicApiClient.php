<?php

namespace App\Support;

use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\TextBlockParam;
use App\Contracts\LlmClient;
use App\Data\LlmResponse;
use App\Data\LlmUsage;
use App\Support\LlmResponseNormalizer;
use Closure;
use RuntimeException;
use Throwable;

class AnthropicApiClient implements LlmClient
{
    public function __construct(
        private object $client,
        private int $maxAttempts = 3,
        private int $baseDelaySeconds = 1,
        private ?Closure $delay = null,
    ) {
        $this->delay ??= static fn (int $seconds): int => sleep($seconds);
    }

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
            stopReason: LlmResponseNormalizer::normalize($sdkResponse->stopReason),
            usage: $usage,
        );
    }

    public function messages(
        string $model,
        int $maxTokens,
        string|array $system = '',
        array $tools = [],
        array $messages = [],
    ): object {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                $params = [
                    'model' => $model,
                    'maxTokens' => $maxTokens,
                    'messages' => $messages,
                ];

                if ($system !== '' && $system !== []) {
                    $params['system'] = $system;
                }

                if ($tools !== []) {
                    $params['tools'] = $tools;
                }

                return $this->client->messages->create(...$params);
            } catch (Throwable $e) {
                $status = $this->extractStatusCode($e);

                if (! $this->isRetryable($status)) {
                    throw new RuntimeException(
                        "Anthropic API error (HTTP {$status}): ".$e->getMessage(),
                        0,
                        $e
                    );
                }

                $lastException = $e;

                if ($attempt < $this->maxAttempts) {
                    ($this->delay)($this->backoffDelay($attempt));
                }
            }
        }

        throw new RuntimeException(
            "Anthropic API failed after {$this->maxAttempts} attempts: ".($lastException?->getMessage() ?? 'unknown error'),
            0,
            $lastException
        );
    }

    private function extractStatusCode(Throwable $e): int|string
    {
        if (method_exists($e, 'getResponse') && $e->getResponse() !== null && method_exists($e->getResponse(), 'getStatusCode')) {
            return (int) $e->getResponse()->getStatusCode();
        }

        return 'network_error';
    }

    private function isRetryable(int|string $status): bool
    {
        if ($status === 429 || $status === 'network_error') {
            return true;
        }

        return is_int($status) && $status >= 500 && $status < 600;
    }

    private function backoffDelay(int $attempt): int
    {
        return $this->baseDelaySeconds * (2 ** ($attempt - 1));
    }
}

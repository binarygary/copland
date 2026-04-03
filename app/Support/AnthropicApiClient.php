<?php

namespace App\Support;

use Anthropic\Client;
use RuntimeException;
use Throwable;

class AnthropicApiClient
{
    public function __construct(
        private Client $client,
        private int $maxAttempts = 3,
        private int $baseDelaySeconds = 1,
    ) {}

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

                if ($system !== '') {
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
                    sleep($this->backoffDelay($attempt));
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

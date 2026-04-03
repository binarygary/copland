<?php

use App\Support\AnthropicApiClient;

it('retries a 429 response and captures the configured backoff delay before succeeding', function () {
    $delays = [];
    $attempts = 0;
    $response = (object) ['id' => 'ok'];

    $messages = new class($attempts, $response) {
        public function __construct(
            private int &$attempts,
            private object $response,
        ) {}

        public function create(...$params): object
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new FakeApiException('rate limited', 429);
            }

            return $this->response;
        }
    };

    $client = new class($messages) {
        public function __construct(public object $messages) {}
    };

    $apiClient = new AnthropicApiClient(
        client: $client,
        maxAttempts: 3,
        baseDelaySeconds: 2,
        delay: function (int $seconds) use (&$delays): void {
            $delays[] = $seconds;
        },
    );

    $result = $apiClient->messages(model: 'claude-test', maxTokens: 100, messages: [['role' => 'user', 'content' => 'hi']]);

    expect($result)->toBe($response);
    expect($attempts)->toBe(2);
    expect($delays)->toBe([2]);
});

it('retries a 5xx response up to the configured attempt count and then succeeds', function () {
    $delays = [];
    $attempts = 0;
    $response = (object) ['id' => 'ok'];

    $messages = new class($attempts, $response) {
        public function __construct(
            private int &$attempts,
            private object $response,
        ) {}

        public function create(...$params): object
        {
            $this->attempts++;

            if ($this->attempts < 3) {
                throw new FakeApiException('server error', 503);
            }

            return $this->response;
        }
    };

    $client = new class($messages) {
        public function __construct(public object $messages) {}
    };

    $apiClient = new AnthropicApiClient(
        client: $client,
        maxAttempts: 3,
        baseDelaySeconds: 1,
        delay: function (int $seconds) use (&$delays): void {
            $delays[] = $seconds;
        },
    );

    $result = $apiClient->messages(model: 'claude-test', maxTokens: 100);

    expect($result)->toBe($response);
    expect($attempts)->toBe(3);
    expect($delays)->toBe([1, 2]);
});

it('does not retry non-429 4xx responses', function () {
    $delays = [];
    $attempts = 0;

    $messages = new class($attempts) {
        public function __construct(private int &$attempts) {}

        public function create(...$params): object
        {
            $this->attempts++;

            throw new FakeApiException('bad request', 400);
        }
    };

    $client = new class($messages) {
        public function __construct(public object $messages) {}
    };

    $apiClient = new AnthropicApiClient(
        client: $client,
        maxAttempts: 3,
        baseDelaySeconds: 1,
        delay: function (int $seconds) use (&$delays): void {
            $delays[] = $seconds;
        },
    );

    expect(fn () => $apiClient->messages(model: 'claude-test', maxTokens: 100))
        ->toThrow(\RuntimeException::class, 'Anthropic API error (HTTP 400): bad request');

    expect($attempts)->toBe(1);
    expect($delays)->toBe([]);
});

it('retries network-style failures and fails after the configured attempt limit', function () {
    $delays = [];
    $attempts = 0;

    $messages = new class($attempts) {
        public function __construct(private int &$attempts) {}

        public function create(...$params): object
        {
            $this->attempts++;

            throw new \RuntimeException('socket timeout');
        }
    };

    $client = new class($messages) {
        public function __construct(public object $messages) {}
    };

    $apiClient = new AnthropicApiClient(
        client: $client,
        maxAttempts: 3,
        baseDelaySeconds: 1,
        delay: function (int $seconds) use (&$delays): void {
            $delays[] = $seconds;
        },
    );

    expect(fn () => $apiClient->messages(model: 'claude-test', maxTokens: 100))
        ->toThrow(\RuntimeException::class, 'Anthropic API failed after 3 attempts: socket timeout');

    expect($attempts)->toBe(3);
    expect($delays)->toBe([1, 2]);
});

final class FakeApiException extends \RuntimeException
{
    public function __construct(string $message, private int $statusCode)
    {
        parent::__construct($message);
    }

    public function getResponse(): object
    {
        return new class($this->statusCode) {
            public function __construct(private int $statusCode) {}

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }
        };
    }
}

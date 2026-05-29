<?php

namespace Tests\Unit;

use App\Data\SystemBlock;
use App\Support\CodexClient;
use App\Support\CodexRunner;
use Tests\TestCase;

/**
 * Tests for CodexClient — maps a single complete() call to one CodexRunner
 * round-trip and adapts the structured result into an LlmResponse.
 */
class CodexClientTest extends TestCase
{
    /**
     * A CodexRunner double that records the call and returns a canned envelope.
     */
    private function fakeRunner(array $envelope): CodexRunner
    {
        return new class($envelope) extends CodexRunner
        {
            public array $captured = [];

            public function __construct(private array $envelope)
            {
                parent::__construct('codex');
            }

            public function runStage(
                string $prompt,
                string $jsonSchema,
                string $sandbox,
                string $cwd,
                ?string $model = null,
                int $timeoutSeconds = 600,
            ): array {
                $this->captured = compact('prompt', 'jsonSchema', 'sandbox', 'cwd', 'model');

                return $this->envelope;
            }
        };
    }

    public function test_complete_unwraps_summary_and_maps_usage_and_ignores_incoming_model(): void
    {
        $runner = $this->fakeRunner([
            'structured_output' => ['summary' => 'did it'],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cached_input_tokens' => 2],
        ]);

        $client = new CodexClient($runner, '{"schema":true}', 'workspace-write', '/repo', 'gpt-5-codex');

        $resp = $client->complete(
            'claude-haiku-4-5', // incoming Claude model name — must be ignored
            1000,
            [['role' => 'user', 'content' => 'hello']],
            [],
            [new SystemBlock('SYSTEM PROMPT')],
        );

        // Executor {summary} payload is unwrapped to the bare string.
        $this->assertSame([['type' => 'text', 'text' => 'did it']], $resp->content);
        $this->assertSame('stop', $resp->stopReason);

        $this->assertSame(10, $resp->usage->inputTokens);
        $this->assertSame(5, $resp->usage->outputTokens);
        $this->assertSame(2, $resp->usage->cacheReadTokens);
        $this->assertSame(0.0, $resp->usage->providerCostUsd);

        // The explicit Codex model is used; the Claude name is NOT forwarded.
        $this->assertSame('gpt-5-codex', $runner->captured['model']);
        $this->assertSame('workspace-write', $runner->captured['sandbox']);

        // Prompt is system blocks + user messages concatenated.
        $this->assertStringContainsString('SYSTEM PROMPT', $runner->captured['prompt']);
        $this->assertStringContainsString('hello', $runner->captured['prompt']);
    }

    public function test_complete_reencodes_non_summary_structured_output(): void
    {
        $runner = $this->fakeRunner([
            'structured_output' => ['decision' => 'act', 'selected_task_id' => 7],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'cached_input_tokens' => 0],
        ]);

        $client = new CodexClient($runner, '{}', 'read-only', '/repo', null);

        $resp = $client->complete('claude-haiku-4-5', 1000, [['role' => 'user', 'content' => 'p']]);

        $this->assertSame('text', $resp->content[0]['type']);
        $this->assertSame(
            ['decision' => 'act', 'selected_task_id' => 7],
            json_decode($resp->content[0]['text'], true),
        );

        // No explicit model override → null forwarded (Codex uses its own default).
        $this->assertNull($runner->captured['model']);
    }
}

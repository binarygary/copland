<?php

namespace Tests\Unit;

use App\Data\SystemBlock;
use App\Support\ClaudeCodeClient;
use App\Support\ClaudeCodeRunner;
use Tests\TestCase;

/**
 * Tests for ClaudeCodeClient — single-shot LlmClient adapter that maps a
 * Copland complete() call to exactly one ClaudeCodeRunner::runStage() call.
 *
 * The runner is stubbed via an anonymous subclass that overrides runStage()
 * to capture inputs and return a canned envelope.
 */
class ClaudeCodeClientTest extends TestCase
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a ClaudeCodeRunner test double that captures runStage args and
     * returns the supplied envelope.
     *
     * @param  array{result: mixed, total_cost_usd: float}  $envelope
     */
    private function makeRunnerStub(array $envelope, array &$captured): ClaudeCodeRunner
    {
        return new class($envelope, $captured) extends ClaudeCodeRunner
        {
            /** @var array<string, mixed> */
            private array $env;

            /** @var array<string, mixed> */
            private array $cap;

            public function __construct(array $envelope, array &$captured)
            {
                parent::__construct('claude');
                $this->env = $envelope;
                $this->cap = &$captured;
            }

            public function runStage(
                string $prompt,
                string $jsonSchema,
                array $allowedTools,
                string $cwd,
                ?string $model = null,
                ?float $maxBudgetUsd = null,
                int $timeoutSeconds = 600,
            ): array {
                $this->cap['prompt'] = $prompt;
                $this->cap['jsonSchema'] = $jsonSchema;
                $this->cap['allowedTools'] = $allowedTools;
                $this->cap['cwd'] = $cwd;
                $this->cap['model'] = $model;
                $this->cap['maxBudgetUsd'] = $maxBudgetUsd;
                $this->cap['timeoutSeconds'] = $timeoutSeconds;

                return [
                    'result' => $this->env['result'],
                    'total_cost_usd' => $this->env['total_cost_usd'],
                    'raw' => $this->env,
                ];
            }
        };
    }

    // ─── Test 1: happy path single user message ───────────────────────────────

    public function test_complete_with_single_user_message_calls_runner_and_returns_terminal_stop_response(): void
    {
        $envelopeResult = ['decision' => 'act', 'selected_task_id' => 42];
        $captured = [];
        $runner = $this->makeRunnerStub([
            'result' => $envelopeResult,
            'total_cost_usd' => 0.0456,
        ], $captured);

        $client = new ClaudeCodeClient(
            runner: $runner,
            jsonSchema: '{"type":"object"}',
            allowedTools: ['Read'],
            workspaceCwd: '/tmp/ws',
            maxBudgetUsd: 0.50,
        );

        $response = $client->complete(
            model: 'sonnet',
            maxTokens: 1024,
            messages: [
                ['role' => 'user', 'content' => 'pick a task'],
            ],
        );

        // Runner received the expected args
        $this->assertSame('pick a task', $captured['prompt']);
        $this->assertSame('{"type":"object"}', $captured['jsonSchema']);
        $this->assertSame(['Read'], $captured['allowedTools']);
        $this->assertSame('/tmp/ws', $captured['cwd']);
        $this->assertSame('sonnet', $captured['model']);
        $this->assertSame(0.50, $captured['maxBudgetUsd']);

        // Response shape: terminal stop, single text block, providerCostUsd populated
        $this->assertSame('stop', $response->stopReason);
        $this->assertCount(1, $response->content);
        $this->assertSame('text', $response->content[0]['type']);
        $this->assertSame(json_encode($envelopeResult), $response->content[0]['text']);

        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame(0, $response->usage->outputTokens);
        $this->assertSame(0.0456, $response->usage->providerCostUsd);
    }

    // ─── Test 2: systemBlocks prepended with \n\n separators ──────────────────

    public function test_complete_prepends_system_blocks_to_prompt_with_double_newline_separators(): void
    {
        $captured = [];
        $runner = $this->makeRunnerStub([
            'result' => 'ok',
            'total_cost_usd' => 0.001,
        ], $captured);

        $client = new ClaudeCodeClient(
            runner: $runner,
            jsonSchema: '{}',
            allowedTools: [],
            workspaceCwd: '/tmp/ws',
        );

        $client->complete(
            model: 'sonnet',
            maxTokens: 1024,
            messages: [
                ['role' => 'user', 'content' => 'do the thing'],
            ],
            systemBlocks: [
                new SystemBlock('System instruction A', cache: true),  // cache flag ignored
                new SystemBlock('System instruction B', cache: false),
            ],
        );

        $this->assertSame(
            "System instruction A\n\nSystem instruction B\n\ndo the thing",
            $captured['prompt']
        );
    }

    // ─── Test 3: $tools argument is IGNORED ───────────────────────────────────

    public function test_complete_ignores_tools_argument(): void
    {
        $captured = [];
        $runner = $this->makeRunnerStub([
            'result' => 'ok',
            'total_cost_usd' => 0.001,
        ], $captured);

        $client = new ClaudeCodeClient(
            runner: $runner,
            jsonSchema: '{}',
            allowedTools: ['Read'],
            workspaceCwd: '/tmp/ws',
        );

        $client->complete(
            model: 'sonnet',
            maxTokens: 1024,
            messages: [
                ['role' => 'user', 'content' => 'p'],
            ],
            tools: [
                ['name' => 'read_file', 'input_schema' => []],
                ['name' => 'write_file', 'input_schema' => []],
            ],
        );

        // allowedTools passed to runner come from the constructor, not from $tools
        $this->assertSame(['Read'], $captured['allowedTools']);
    }

    // ─── Test 4: assistant/tool_result messages skipped when building prompt ──

    public function test_complete_skips_assistant_and_tool_result_messages_when_building_prompt(): void
    {
        $captured = [];
        $runner = $this->makeRunnerStub([
            'result' => 'ok',
            'total_cost_usd' => 0.001,
        ], $captured);

        $client = new ClaudeCodeClient(
            runner: $runner,
            jsonSchema: '{}',
            allowedTools: [],
            workspaceCwd: '/tmp/ws',
        );

        $client->complete(
            model: 'sonnet',
            maxTokens: 1024,
            messages: [
                ['role' => 'user', 'content' => 'first user msg'],
                ['role' => 'assistant', 'content' => 'assistant reply'],
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => 'result'],
                ]],
                ['role' => 'user', 'content' => 'second user msg'],
            ],
        );

        // Only plain-string role=user contents contribute, joined by \n\n
        $this->assertSame("first user msg\n\nsecond user msg", $captured['prompt']);
    }

    // ─── Test 5: allowedTools accessor exposes the configured whitelist ───────

    public function test_allowed_tools_accessor_returns_configured_whitelist(): void
    {
        $captured = [];
        $runner = $this->makeRunnerStub([
            'result' => 'ok',
            'total_cost_usd' => 0.001,
        ], $captured);

        $client = new ClaudeCodeClient(
            runner: $runner,
            jsonSchema: '{}',
            allowedTools: ['Read', 'Edit', 'Bash(git status)'],
            workspaceCwd: '/tmp/ws',
        );

        $this->assertSame(['Read', 'Edit', 'Bash(git status)'], $client->allowedTools());
    }
}

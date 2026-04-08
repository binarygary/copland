<?php

namespace Tests\Unit;

use App\Contracts\LlmClient;
use App\Data\LlmUsage;
use App\Data\SystemBlock;
use App\Support\OpenAiCompatClient;
use Tests\TestCase;

/**
 * Tests for OpenAiCompatClient — bidirectional message translation between
 * Anthropic format (used internally by Copland) and OpenAI-compatible format
 * (used by Ollama and OpenRouter).
 */
class OpenAiCompatClientTest extends TestCase
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a fake openai-php client that returns a canned chat response.
     */
    private function fakeOpenAiClient(
        ?string $content,
        array $toolCalls = [],
        string $finishReason = 'stop',
        int $promptTokens = 10,
        int $completionTokens = 5,
    ): object {
        $toolCallObjects = array_map(function (array $tc): object {
            $fn = new class ($tc)
            {
                public string $name;

                public string $arguments;

                public function __construct(array $tc)
                {
                    $this->name = $tc['name'];
                    $this->arguments = $tc['arguments'];
                }
            };

            return new class ($tc['id'], $fn)
            {
                public string $id;

                public object $function;

                public function __construct(string $id, object $fn)
                {
                    $this->id = $id;
                    $this->function = $fn;
                }
            };
        }, $toolCalls);

        $message = new class ($content, $toolCallObjects)
        {
            public ?string $content;

            public array $toolCalls;

            public function __construct(?string $content, array $toolCalls)
            {
                $this->content = $content;
                $this->toolCalls = $toolCalls;
            }
        };

        $usage = new class ($promptTokens, $completionTokens)
        {
            public int $promptTokens;

            public int $completionTokens;

            public function __construct(int $promptTokens, int $completionTokens)
            {
                $this->promptTokens = $promptTokens;
                $this->completionTokens = $completionTokens;
            }
        };

        $choice = new class ($finishReason, $message)
        {
            public string $finishReason;

            public object $message;

            public function __construct(string $finishReason, object $message)
            {
                $this->finishReason = $finishReason;
                $this->message = $message;
            }
        };

        $response = new class ($choice, $usage)
        {
            public array $choices;

            public object $usage;

            public function __construct(object $choice, object $usage)
            {
                $this->choices = [$choice];
                $this->usage = $usage;
            }
        };

        $chat = new class ($response)
        {
            private object $response;

            public function __construct(object $response)
            {
                $this->response = $response;
            }

            public function create(array $params): object
            {
                return $this->response;
            }
        };

        return new class ($chat)
        {
            private object $chat;

            public function __construct(object $chat)
            {
                $this->chat = $chat;
            }

            public function chat(): object
            {
                return $this->chat;
            }
        };
    }

    /**
     * Call translateMessages() via reflection (private method).
     */
    private function callTranslateMessages(OpenAiCompatClient $client, array $messages, array $systemBlocks = []): array
    {
        $ref = new \ReflectionMethod($client, 'translateMessages');
        $ref->setAccessible(true);

        return $ref->invoke($client, $messages, $systemBlocks);
    }

    // ─── Test 1: plain text response ─────────────────────────────────────────

    public function test_complete_returns_text_content_block_for_plain_text_response(): void
    {
        $fakeClient = $this->fakeOpenAiClient(
            content: 'Hello world',
            finishReason: 'stop',
            promptTokens: 20,
            completionTokens: 8,
        );

        $client = new OpenAiCompatClient($fakeClient);

        $response = $client->complete(
            model: 'llama3.1',
            maxTokens: 512,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );

        $this->assertInstanceOf(\App\Data\LlmResponse::class, $response);
        $this->assertCount(1, $response->content);
        $this->assertSame('text', $response->content[0]['type']);
        $this->assertSame('Hello world', $response->content[0]['text']);
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(20, $response->usage->inputTokens);
        $this->assertSame(8, $response->usage->outputTokens);
        $this->assertSame(0, $response->usage->cacheWriteTokens);
        $this->assertSame(0, $response->usage->cacheReadTokens);
    }

    // ─── Test 2: tool_calls response ─────────────────────────────────────────

    public function test_complete_returns_tool_use_content_block_for_tool_calls_response(): void
    {
        $fakeClient = $this->fakeOpenAiClient(
            content: null,
            toolCalls: [
                [
                    'id' => 'call_abc123',
                    'name' => 'read_file',
                    'arguments' => '{"path": "app/Foo.php"}',
                ],
            ],
            finishReason: 'tool_calls',
            promptTokens: 30,
            completionTokens: 12,
        );

        $client = new OpenAiCompatClient($fakeClient);

        $response = $client->complete(
            model: 'llama3.1',
            maxTokens: 512,
            messages: [['role' => 'user', 'content' => 'Read the file']],
        );

        $this->assertSame('tool_calls', $response->stopReason);
        $this->assertCount(1, $response->content);
        $block = $response->content[0];
        $this->assertSame('tool_use', $block['type']);
        $this->assertSame('call_abc123', $block['id']);
        $this->assertSame('read_file', $block['name']);
        $this->assertSame(['path' => 'app/Foo.php'], $block['input']);
    }

    // ─── Test 3: translateMessages — assistant with tool_use blocks ───────────

    public function test_translate_messages_converts_assistant_tool_use_to_openai_tool_calls(): void
    {
        $client = new OpenAiCompatClient($this->fakeOpenAiClient('ok'));

        $messages = [
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_xyz',
                        'name' => 'write_file',
                        'input' => ['path' => 'Foo.php', 'content' => '<?php'],
                    ],
                ],
            ],
        ];

        $translated = $this->callTranslateMessages($client, $messages);

        $this->assertCount(1, $translated);
        $msg = $translated[0];
        $this->assertSame('assistant', $msg['role']);
        $this->assertNull($msg['content']);
        $this->assertCount(1, $msg['tool_calls']);
        $tc = $msg['tool_calls'][0];
        $this->assertSame('call_xyz', $tc['id']);
        $this->assertSame('function', $tc['type']);
        $this->assertSame('write_file', $tc['function']['name']);
        $this->assertSame(json_encode(['path' => 'Foo.php', 'content' => '<?php']), $tc['function']['arguments']);
    }

    // ─── Test 4: translateMessages — user with tool_result blocks ─────────────

    public function test_translate_messages_converts_user_tool_result_to_role_tool_messages(): void
    {
        $client = new OpenAiCompatClient($this->fakeOpenAiClient('ok'));

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => 'call_xyz',
                        'content' => 'file contents here',
                        'is_error' => false,
                    ],
                ],
            ],
        ];

        $translated = $this->callTranslateMessages($client, $messages);

        $this->assertCount(1, $translated);
        $this->assertSame('tool', $translated[0]['role']);
        $this->assertSame('call_xyz', $translated[0]['tool_call_id']);
        $this->assertSame('file contents here', $translated[0]['content']);
    }

    // ─── Test 5: translateMessages — is_error=true prepends "ERROR: " ─────────

    public function test_translate_messages_prepends_error_prefix_for_error_tool_results(): void
    {
        $client = new OpenAiCompatClient($this->fakeOpenAiClient('ok'));

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => 'call_err',
                        'content' => 'File not found',
                        'is_error' => true,
                    ],
                ],
            ],
        ];

        $translated = $this->callTranslateMessages($client, $messages);

        $this->assertCount(1, $translated);
        $this->assertSame('tool', $translated[0]['role']);
        $this->assertSame('ERROR: File not found', $translated[0]['content']);
    }

    // ─── Test 6: translateMessages — SystemBlocks become system message ────────

    public function test_translate_messages_prepends_system_message_from_system_blocks(): void
    {
        $client = new OpenAiCompatClient($this->fakeOpenAiClient('ok'));

        $systemBlocks = [
            new SystemBlock('You are a helpful assistant.', cache: true),
            new SystemBlock('You write PHP code.', cache: false),
        ];

        $messages = [
            ['role' => 'user', 'content' => 'Write some code'],
        ];

        $translated = $this->callTranslateMessages($client, $messages, $systemBlocks);

        // System block should be first message
        $this->assertCount(2, $translated);
        $sys = $translated[0];
        $this->assertSame('system', $sys['role']);
        // Cache flag stripped — plain text only
        $this->assertSame("You are a helpful assistant.\n\nYou write PHP code.", $sys['content']);
        // Original user message still present
        $this->assertSame('user', $translated[1]['role']);
    }
}

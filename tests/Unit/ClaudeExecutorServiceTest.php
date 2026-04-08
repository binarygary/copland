<?php

use App\Config\GlobalConfig;
use App\Data\PlanResult;
use App\Services\ClaudeExecutorService;
use App\Support\AnthropicApiClient;

it('dispatches a tool_use response through the real executor tool flow', function () {
    $workspace = makeWorkspace();
    $plan = makePlan(filesToChange: ['src/output.txt']);

    $service = makeExecutor([
        fakeResponse(
            stopReason: 'tool_calls',
            content: [
                toolUseBlock('tool-1', 'write_file', [
                    'path' => 'src/output.txt',
                    'content' => "hello from executor\n",
                ]),
            ],
        ),
        fakeResponse(
            stopReason: 'stop',
            content: [textBlock('done')],
        ),
    ]);

    try {
        $result = $service->executeWithRepoProfile($workspace, $plan, ['max_executor_rounds' => 3]);

        expect($result->success)->toBeTrue();
        expect($result->summary)->toBe('done');
        expect($result->toolCallCount)->toBe(1);
        expect($result->toolCallLog[0]['tool'])->toBe('write_file');
        expect($result->toolCallLog[0]['is_error'])->toBeFalse();
        expect(file_get_contents($workspace.'/src/output.txt'))->toBe("hello from executor\n");
    } finally {
        deleteDirectory($workspace);
    }
});

it('returns a failed execution result after repeated no-progress rounds', function () {
    $workspace = makeWorkspace();
    $plan = makePlan();

    $service = makeExecutor([
        fakeResponse(stopReason: 'max_tokens', content: [textBlock('thinking 1')]),
        fakeResponse(stopReason: 'max_tokens', content: [textBlock('thinking 2')]),
        fakeResponse(stopReason: 'max_tokens', content: [textBlock('thinking 3')]),
        fakeResponse(stopReason: 'max_tokens', content: [textBlock('thinking 4')]),
        fakeResponse(stopReason: 'max_tokens', content: [textBlock('thinking 5')]),
    ]);

    try {
        $result = $service->executeWithRepoProfile($workspace, $plan, ['max_executor_rounds' => 6]);

        expect($result->success)->toBeFalse();
        expect($result->summary)->toBe('Executor made no implementation progress after 5 rounds (no file writes or planned commands)');
        expect($result->toolCallCount)->toBe(0);
    } finally {
        deleteDirectory($workspace);
    }
});

it('captures blocked write policy violations in the failed execution result', function () {
    $workspace = makeWorkspace();
    $plan = makePlan(
        filesToChange: ['src/blocked.txt'],
        blockedWritePaths: ['src/blocked.txt'],
    );

    $service = makeExecutor([
        fakeResponse(
            stopReason: 'tool_calls',
            content: [
                toolUseBlock('tool-1', 'write_file', [
                    'path' => 'src/blocked.txt',
                    'content' => 'should not write',
                ]),
            ],
        ),
    ]);

    try {
        $result = $service->executeWithRepoProfile($workspace, $plan, ['max_executor_rounds' => 1]);

        expect($result->success)->toBeFalse();
        expect($result->summary)->toBe('Executor stopped after 1 rounds without reaching completion');
        expect($result->toolCallCount)->toBe(1);
        expect($result->toolCallLog[0]['tool'])->toBe('write_file');
        expect($result->toolCallLog[0]['is_error'])->toBeTrue();
        expect($result->toolCallLog[0]['outcome'])->toContain("Policy violation: Write to 'src/blocked.txt' blocked by blocked_write_paths");
        expect(file_exists($workspace.'/src/blocked.txt'))->toBeFalse();
    } finally {
        deleteDirectory($workspace);
    }
});

function makeExecutor(array $responses): ClaudeExecutorService
{
    $config = new class extends GlobalConfig
    {
        public function __construct() {}

        public function executorModel(): string
        {
            return 'claude-sonnet-4-6';
        }
    };

    $messages = new class($responses)
    {
        public function __construct(private array $responses) {}

        public function create(...$params): object
        {
            if ($this->responses === []) {
                throw new \RuntimeException('No fake executor responses remaining');
            }

            return array_shift($this->responses);
        }
    };

    $client = new class($messages)
    {
        public function __construct(public object $messages) {}
    };

    return new ClaudeExecutorService(
        config: $config,
        apiClient: new AnthropicApiClient($client, maxAttempts: 1),
        systemPrompt: 'executor test prompt',
    );
}

function makePlan(
    array $filesToRead = [],
    array $filesToChange = [],
    array $blockedWritePaths = [],
    array $commandsToRun = [],
): PlanResult {
    return new PlanResult(
        decision: 'accept',
        branchName: 'feature/test-plan',
        filesToRead: $filesToRead,
        filesToChange: $filesToChange,
        blockedWritePaths: $blockedWritePaths,
        steps: ['Implement the requested change'],
        commandsToRun: $commandsToRun,
        testsToUpdate: [],
        successCriteria: ['Tests pass'],
        guardrails: [],
        prTitle: null,
        prBody: null,
        maxFilesChanged: 3,
        maxLinesChanged: 250,
        declineReason: null,
    );
}

function fakeResponse(string $stopReason, array $content): object
{
    return (object) [
        'stopReason' => $stopReason,
        'content' => $content,
        'usage' => (object) [
            'inputTokens' => 0,
            'outputTokens' => 0,
            'cacheCreationInputTokens' => 0,
            'cacheReadInputTokens' => 0,
        ],
    ];
}

function textBlock(string $text): object
{
    return (object) [
        'type' => 'text',
        'text' => $text,
    ];
}

function toolUseBlock(string $id, string $name, array $input): object
{
    return (object) [
        'type' => 'tool_use',
        'id' => $id,
        'name' => $name,
        'input' => (object) $input,
    ];
}

function makeWorkspace(): string
{
    $workspace = sys_get_temp_dir().'/copland-executor-test-'.bin2hex(random_bytes(6));

    mkdir($workspace, 0755, true);

    return $workspace;
}

function deleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path.'/'.$entry;

        if (is_dir($child)) {
            deleteDirectory($child);

            continue;
        }

        unlink($child);
    }

    rmdir($path);
}

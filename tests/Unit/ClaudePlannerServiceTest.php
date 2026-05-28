<?php

use App\Config\GlobalConfig;
use App\Contracts\LlmClient;
use App\Data\LlmResponse;
use App\Data\LlmUsage;
use App\Data\PlanResult;
use App\Services\ClaudePlannerService;
use Tests\TestCase;

uses(TestCase::class);

function plannerTextBlock(string $text): array
{
    return ['type' => 'text', 'text' => $text];
}

function plannerToolUseBlock(string $id, string $name, array $input): array
{
    return [
        'type' => 'tool_use',
        'id' => $id,
        'name' => $name,
        'input' => $input,
    ];
}

function plannerResponse(string $stopReason, array $content): LlmResponse
{
    return new LlmResponse(
        content: $content,
        stopReason: $stopReason,
        usage: new LlmUsage(inputTokens: 10, outputTokens: 5),
    );
}

function makePlannerWorkspace(): string
{
    $workspace = sys_get_temp_dir().'/copland-planner-test-'.bin2hex(random_bytes(6));
    mkdir($workspace, 0755, true);

    return $workspace;
}

function deletePlannerDirectory(string $path): void
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
            deletePlannerDirectory($child);

            continue;
        }

        unlink($child);
    }

    rmdir($path);
}

function makePlanner(array $responses): array
{
    $config = new class extends GlobalConfig
    {
        public function __construct() {}

        public function plannerModel(): string
        {
            return 'claude-sonnet-4-6';
        }
    };

    $client = new class($responses) implements LlmClient
    {
        public array $calls = [];

        public function __construct(private array $responses) {}

        public function complete(string $model, int $maxTokens, array $messages, array $tools = [], array $systemBlocks = []): LlmResponse
        {
            $this->calls[] = [
                'model' => $model,
                'maxTokens' => $maxTokens,
                'messages' => $messages,
                'tools' => $tools,
            ];

            if ($this->responses === []) {
                throw new RuntimeException('No fake planner responses remaining');
            }

            return array_shift($this->responses);
        }
    };

    return [
        'service' => new ClaudePlannerService($config, $client),
        'client' => $client,
    ];
}

function fullPlanJsonWith(array $overrides = []): array
{
    return array_merge([
        'decision' => 'plan',
        'branch_name' => 'agent/issue-1-test',
        'files_to_read' => ['src/file.txt'],
        'files_to_change' => ['src/file.txt'],
        'blocked_write_paths' => [],
        'steps' => ['edit the line'],
        'commands_to_run' => [],
        'tests_to_update' => [],
        'success_criteria' => ['tests pass'],
        'guardrails' => [],
        'pr_title' => 'Fix issue',
        'pr_body' => 'Body',
        'max_files_changed' => 3,
        'max_lines_changed' => 250,
        'decline_reason' => null,
        'changes' => [],
    ], $overrides);
}

it('planner reads a file then emits changes referring to that file', function () {
    $workspace = makePlannerWorkspace();
    $fixtureRelative = 'src/file.txt';
    $fixtureDir = $workspace.'/src';
    mkdir($fixtureDir, 0755, true);
    $oldLine = "    const SECRET_TOKEN = 'old-token';\n";
    file_put_contents($workspace.'/'.$fixtureRelative, $oldLine);

    $finalJson = fullPlanJsonWith([
        'files_to_change' => [$fixtureRelative],
        'files_to_read' => [$fixtureRelative],
        'changes' => [
            [
                'file' => $fixtureRelative,
                'old' => trim($oldLine, "\n"),
                'new' => "    const SECRET_TOKEN = 'new-token';",
                'reason' => 'rotate token',
            ],
        ],
    ]);

    [$service, $client] = array_values(makePlanner([
        plannerResponse('tool_calls', [
            plannerToolUseBlock('tool-1', 'read_file', ['path' => $fixtureRelative]),
        ]),
        plannerResponse('stop', [
            plannerTextBlock(json_encode($finalJson, JSON_PRETTY_PRINT)),
        ]),
    ]));

    try {
        $result = $service->planTask(
            ['workspace_path' => $workspace, 'max_planner_rounds' => 4],
            ['number' => 1, 'title' => 'Rotate the secret token', 'body' => '', 'html_url' => ''],
        );

        expect($result)->toBeInstanceOf(PlanResult::class);
        expect($result->changes)->toHaveCount(1);
        expect($result->changes[0]['file'])->toBe($fixtureRelative);
        expect($result->changes[0]['old'])->toBe(trim($oldLine, "\n"));
        expect($result->changes[0]['new'])->toBe("    const SECRET_TOKEN = 'new-token';");
        expect($client->calls)->toHaveCount(2);

        // Second call's messages should include the tool_result for the read_file.
        $secondCallMessages = $client->calls[1]['messages'];
        $toolResultMessage = end($secondCallMessages);
        expect($toolResultMessage['role'])->toBe('user');
        expect($toolResultMessage['content'][0]['type'])->toBe('tool_result');
        expect($toolResultMessage['content'][0]['content'])->toContain('SECRET_TOKEN');
    } finally {
        deletePlannerDirectory($workspace);
    }
});

it('planner throws when max planner rounds is exceeded', function () {
    $workspace = makePlannerWorkspace();
    $fixtureRelative = 'src/file.txt';
    $fixtureDir = $workspace.'/src';
    mkdir($fixtureDir, 0755, true);
    file_put_contents($workspace.'/'.$fixtureRelative, "anything\n");

    [$service] = array_values(makePlanner([
        plannerResponse('tool_calls', [plannerToolUseBlock('t1', 'read_file', ['path' => $fixtureRelative])]),
        plannerResponse('tool_calls', [plannerToolUseBlock('t2', 'read_file', ['path' => $fixtureRelative])]),
        plannerResponse('tool_calls', [plannerToolUseBlock('t3', 'read_file', ['path' => $fixtureRelative])]),
    ]));

    try {
        expect(fn () => $service->planTask(
            ['workspace_path' => $workspace, 'max_planner_rounds' => 2],
            ['number' => 1, 'title' => 'x', 'body' => '', 'html_url' => ''],
        ))->toThrow(RuntimeException::class, 'Planner exceeded max rounds (2)');
    } finally {
        deletePlannerDirectory($workspace);
    }
});

it('planner returns empty changes when the model emits an empty array', function () {
    $workspace = makePlannerWorkspace();
    $finalJson = fullPlanJsonWith(['changes' => []]);

    [$service] = array_values(makePlanner([
        plannerResponse('stop', [plannerTextBlock(json_encode($finalJson, JSON_PRETTY_PRINT))]),
    ]));

    try {
        $result = $service->planTask(
            ['workspace_path' => $workspace, 'max_planner_rounds' => 2],
            ['number' => 1, 'title' => 'noop', 'body' => '', 'html_url' => ''],
        );

        expect($result->changes)->toBe([]);
    } finally {
        deletePlannerDirectory($workspace);
    }
});

it('planner drops changes entries for files it did not read this loop', function () {
    $workspace = makePlannerWorkspace();
    $readRelative = 'src/read.txt';
    $unreadRelative = 'src/unread.txt';
    mkdir($workspace.'/src', 0755, true);
    file_put_contents($workspace.'/'.$readRelative, "some line\n");
    file_put_contents($workspace.'/'.$unreadRelative, "other line\n");

    $finalJson = fullPlanJsonWith([
        'files_to_change' => [$readRelative, $unreadRelative],
        'files_to_read' => [$readRelative, $unreadRelative],
        'changes' => [
            ['file' => $readRelative,   'old' => 'some line',  'new' => 'changed',    'reason' => 'real'],
            ['file' => $unreadRelative, 'old' => 'other line', 'new' => 'fabricated', 'reason' => 'unread'],
        ],
    ]);

    [$service] = array_values(makePlanner([
        plannerResponse('tool_calls', [plannerToolUseBlock('t1', 'read_file', ['path' => $readRelative])]),
        plannerResponse('stop', [plannerTextBlock(json_encode($finalJson, JSON_PRETTY_PRINT))]),
    ]));

    try {
        $result = $service->planTask(
            ['workspace_path' => $workspace, 'max_planner_rounds' => 4],
            ['number' => 1, 'title' => 'partial', 'body' => '', 'html_url' => ''],
        );

        expect($result->changes)->toHaveCount(1);
        expect($result->changes[0]['file'])->toBe($readRelative);
    } finally {
        deletePlannerDirectory($workspace);
    }
});

it('planner workspace path falls back to repo_path before getcwd', function () {
    $workspace = makePlannerWorkspace();
    $fixtureRelative = 'src/file.txt';
    mkdir($workspace.'/src', 0755, true);
    file_put_contents($workspace.'/'.$fixtureRelative, "hello\n");

    $finalJson = fullPlanJsonWith([
        'files_to_change' => [$fixtureRelative],
        'files_to_read' => [$fixtureRelative],
        'changes' => [
            ['file' => $fixtureRelative, 'old' => 'hello', 'new' => 'world', 'reason' => 'demo'],
        ],
    ]);

    [$service] = array_values(makePlanner([
        plannerResponse('tool_calls', [plannerToolUseBlock('t1', 'read_file', ['path' => $fixtureRelative])]),
        plannerResponse('stop', [plannerTextBlock(json_encode($finalJson, JSON_PRETTY_PRINT))]),
    ]));

    try {
        // No workspace_path — should fall back to repo_path.
        $result = $service->planTask(
            ['repo_path' => $workspace, 'max_planner_rounds' => 4],
            ['number' => 1, 'title' => 'fallback', 'body' => '', 'html_url' => ''],
        );

        expect($result->changes)->toHaveCount(1);
        expect($result->changes[0]['file'])->toBe($fixtureRelative);
    } finally {
        deletePlannerDirectory($workspace);
    }
});

<?php

use App\Services\AsanaService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('fetches open tasks and returns them in selector-compatible format', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '111',
                    'name'        => 'Task A',
                    'notes'       => 'Some notes',
                    'completed'   => false,
                    'tags'        => [['gid' => 't1', 'name' => 'agent-ready']],
                    'memberships' => [['project' => ['gid' => 'proj1'], 'section' => ['name' => 'Backlog']]],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client = new Client([
        'base_uri' => 'https://app.asana.com/api/1.0/',
        'handler'  => $handlerStack,
    ]);

    $service = new AsanaService('test-token', 'proj1', [], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toBe([
        [
            'number' => '111',
            'title'  => 'Task A',
            'body'   => 'Some notes',
            'labels' => [['name' => 'agent-ready']],
        ],
    ]);

    expect($history)->toHaveCount(1);
    $uri = (string) $history[0]['request']->getUri();
    expect($uri)->toContain('projects/proj1/tasks');
    expect($uri)->toContain('completed_since=now');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

it('excludes tasks missing a required tag', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '222',
                    'name'        => 'No tag task',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [],
                ],
                [
                    'gid'         => '333',
                    'name'        => 'Has tag task',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [['gid' => 't1', 'name' => 'agent-ready']],
                    'memberships' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('tok', 'proj1', ['tags' => ['agent-ready']], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['number'])->toBe('333');
});

it('excludes tasks not in the required section of this project', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '444',
                    'name'        => 'Wrong section',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [['project' => ['gid' => 'proj1'], 'section' => ['name' => 'Done']]],
                ],
                [
                    'gid'         => '555',
                    'name'        => 'Right section',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [['project' => ['gid' => 'proj1'], 'section' => ['name' => 'Backlog']]],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('tok', 'proj1', ['section' => 'Backlog'], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['number'])->toBe('555');
});

it('does not match section from a different project membership', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '666',
                    'name'        => 'Cross-project task',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [
                        // Different project, matching section name — should not pass
                        ['project' => ['gid' => 'other-project'], 'section' => ['name' => 'Backlog']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('tok', 'proj1', ['section' => 'Backlog'], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(0);
});

it('applies both tag and section filters with AND logic', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '777',
                    'name'        => 'Has tag but wrong section',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [['gid' => 't1', 'name' => 'agent-ready']],
                    'memberships' => [['project' => ['gid' => 'proj1'], 'section' => ['name' => 'In Progress']]],
                ],
                [
                    'gid'         => '888',
                    'name'        => 'Has both',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [['gid' => 't1', 'name' => 'agent-ready']],
                    'memberships' => [['project' => ['gid' => 'proj1'], 'section' => ['name' => 'Backlog']]],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('tok', 'proj1', ['tags' => ['agent-ready'], 'section' => 'Backlog'], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['number'])->toBe('888');
});

it('posts a comment story to a task', function () {
    $history = [];
    $mock    = new MockHandler([
        new Response(200, [], json_encode(['data' => []], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => $handlerStack]);
    $service = new AsanaService('test-token', 'proj1', [], $client);
    $service->addStory('task-gid-1', 'Copland completed this task');

    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getMethod())->toBe('POST');
    expect((string) $history[0]['request']->getUri())->toContain('tasks/task-gid-1/stories');

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['data']['text'])->toBe('Copland completed this task');
});

it('removes a tag from a task by name', function () {
    $history = [];
    $mock    = new MockHandler([
        // First: fetch task tags
        new Response(200, [], json_encode([
            'data' => [
                'tags' => [
                    ['gid' => 'tag-gid-abc', 'name' => 'agent-ready'],
                    ['gid' => 'tag-gid-xyz', 'name' => 'other-tag'],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
        // Second: removeTag call
        new Response(200, [], json_encode(['data' => []], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => $handlerStack]);
    $service = new AsanaService('test-token', 'proj1', [], $client);
    $service->removeTag('task-gid-1', 'agent-ready');

    expect($history)->toHaveCount(2);

    // First request: GET the task tags
    expect($history[0]['request']->getMethod())->toBe('GET');
    expect((string) $history[0]['request']->getUri())->toContain('tasks/task-gid-1');

    // Second request: POST removeTag with the correct GID
    expect($history[1]['request']->getMethod())->toBe('POST');
    expect((string) $history[1]['request']->getUri())->toContain('tasks/task-gid-1/removeTag');
    $body = json_decode((string) $history[1]['request']->getBody(), true);
    expect($body['data']['tag'])->toBe('tag-gid-abc');
});

it('is a no-op when removing a tag not present on the task', function () {
    $history = [];
    $mock    = new MockHandler([
        // Fetch task tags — tag not present
        new Response(200, [], json_encode([
            'data' => [
                'tags' => [
                    ['gid' => 'tag-gid-xyz', 'name' => 'other-tag'],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => $handlerStack]);
    $service = new AsanaService('test-token', 'proj1', [], $client);
    $service->removeTag('task-gid-1', 'agent-ready');

    // Only one request (fetching tags) — no removeTag call
    expect($history)->toHaveCount(1);
});

it('throws a RuntimeException on Guzzle API error', function () {
    $mock = new MockHandler([
        new \GuzzleHttp\Exception\RequestException(
            'Server error',
            new \GuzzleHttp\Psr7\Request('GET', 'test'),
            new Response(500, [], 'Internal Server Error')
        ),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('test-token', 'proj1', [], $client);

    expect(fn () => $service->getOpenTasks())
        ->toThrow(RuntimeException::class, 'Asana API error');
});

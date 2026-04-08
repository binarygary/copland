<?php

use App\Services\AsanaService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('fetches open tasks and returns them in selector-compatible format', function () {
    $history      = [];
    $taskPayload  = [
        'data' => [
            [
                'gid'         => '987654321098765',
                'name'        => 'Fix login timeout',
                'notes'       => 'Users report session expires',
                'completed'   => false,
                'tags'        => [['gid' => '555666777888999', 'name' => 'agent-ready']],
                'memberships' => [[
                    'project' => ['gid' => '111222333444555'],
                    'section' => ['name' => 'Backlog'],
                ]],
            ],
        ],
    ];

    $mock         = new MockHandler([
        new Response(200, [], json_encode($taskPayload, JSON_THROW_ON_ERROR)),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => $handlerStack]);
    $service = new AsanaService('test-asana-pat', '111222333444555', [], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toBe([
        [
            'number' => '987654321098765',
            'title'  => 'Fix login timeout',
            'body'   => 'Users report session expires',
            'labels' => [['name' => 'agent-ready']],
        ],
    ]);

    expect($history)->toHaveCount(1);
    $uri = (string) $history[0]['request']->getUri();
    expect($uri)->toContain('projects/111222333444555/tasks');
    expect($uri)->toContain('completed_since=now');
    expect($uri)->toContain('opt_fields');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-asana-pat');
});

it('excludes tasks missing a required tag', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '111000111000111',
                    'name'        => 'No tag task',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [],
                ],
                [
                    'gid'         => '987654321098765',
                    'name'        => 'Has tag task',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [['gid' => '555666777888999', 'name' => 'agent-ready']],
                    'memberships' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('test-asana-pat', '111222333444555', ['tags' => ['agent-ready']], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['number'])->toBe('987654321098765');
});

it('requires ALL tags when multiple required tags are configured', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '111000111000111',
                    'name'        => 'Has only one tag',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [['gid' => '555666777888999', 'name' => 'agent-ready']],
                    'memberships' => [],
                ],
                [
                    'gid'         => '987654321098765',
                    'name'        => 'Has both tags',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [
                        ['gid' => '555666777888999', 'name' => 'agent-ready'],
                        ['gid' => '444333222111000', 'name' => 'priority-high'],
                    ],
                    'memberships' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('test-asana-pat', '111222333444555', ['tags' => ['agent-ready', 'priority-high']], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['number'])->toBe('987654321098765');
});

it('excludes tasks not in the required section of this project', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '111000111000111',
                    'name'        => 'Wrong section',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [['project' => ['gid' => '111222333444555'], 'section' => ['name' => 'Done']]],
                ],
                [
                    'gid'         => '987654321098765',
                    'name'        => 'Correct section',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [['project' => ['gid' => '111222333444555'], 'section' => ['name' => 'Backlog']]],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('test-asana-pat', '111222333444555', ['section' => 'Backlog'], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['number'])->toBe('987654321098765');
});

it('does not match section from a different project membership', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '987654321098765',
                    'name'        => 'Cross-project section match',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [],
                    'memberships' => [
                        // Correct section name but belongs to a DIFFERENT project
                        ['project' => ['gid' => '999888777666555'], 'section' => ['name' => 'Backlog']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('test-asana-pat', '111222333444555', ['section' => 'Backlog'], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(0);
});

it('applies tag and section filters with AND logic — task with tag but wrong section is excluded', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'data' => [
                [
                    'gid'         => '111000111000111',
                    'name'        => 'Has tag but wrong section',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [['gid' => '555666777888999', 'name' => 'agent-ready']],
                    'memberships' => [['project' => ['gid' => '111222333444555'], 'section' => ['name' => 'In Progress']]],
                ],
                [
                    'gid'         => '987654321098765',
                    'name'        => 'Has tag and correct section',
                    'notes'       => '',
                    'completed'   => false,
                    'tags'        => [['gid' => '555666777888999', 'name' => 'agent-ready']],
                    'memberships' => [['project' => ['gid' => '111222333444555'], 'section' => ['name' => 'Backlog']]],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => HandlerStack::create($mock)]);
    $service = new AsanaService('test-asana-pat', '111222333444555', ['tags' => ['agent-ready'], 'section' => 'Backlog'], $client);
    $tasks   = $service->getOpenTasks();

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['number'])->toBe('987654321098765');
});

it('posts a comment story to a task via addStory', function () {
    $history = [];
    $mock    = new MockHandler([
        new Response(200, [], json_encode(['data' => []], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => $handlerStack]);
    $service = new AsanaService('test-asana-pat', '111222333444555', [], $client);
    $service->addStory('987654321098765', 'Copland completed this task');

    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getMethod())->toBe('POST');
    expect((string) $history[0]['request']->getUri())->toContain('tasks/987654321098765/stories');

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['data']['text'])->toBe('Copland completed this task');
});

it('removes a tag from a task by name using the resolved GID', function () {
    $history = [];
    $mock    = new MockHandler([
        // First: GET /tasks/{gid} to fetch tags
        new Response(200, [], json_encode([
            'data' => [
                'tags' => [
                    ['gid' => '555666777888999', 'name' => 'agent-ready'],
                    ['gid' => '444333222111000', 'name' => 'other-tag'],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
        // Second: POST /tasks/{gid}/removeTag
        new Response(200, [], json_encode(['data' => []], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => $handlerStack]);
    $service = new AsanaService('test-asana-pat', '111222333444555', [], $client);
    $service->removeTag('987654321098765', 'agent-ready');

    expect($history)->toHaveCount(2);
    expect($history[0]['request']->getMethod())->toBe('GET');
    expect((string) $history[0]['request']->getUri())->toContain('tasks/987654321098765');
    expect($history[1]['request']->getMethod())->toBe('POST');
    expect((string) $history[1]['request']->getUri())->toContain('tasks/987654321098765/removeTag');

    $body = json_decode((string) $history[1]['request']->getBody(), true);
    expect($body['data']['tag'])->toBe('555666777888999');
});

it('is a no-op for removeTag when the named tag is not present on the task', function () {
    $history = [];
    $mock    = new MockHandler([
        // Fetch task tags — agent-ready not present
        new Response(200, [], json_encode([
            'data' => [
                'tags' => [
                    ['gid' => '444333222111000', 'name' => 'other-tag'],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client  = new Client(['base_uri' => 'https://app.asana.com/api/1.0/', 'handler' => $handlerStack]);
    $service = new AsanaService('test-asana-pat', '111222333444555', [], $client);
    $service->removeTag('987654321098765', 'agent-ready');

    // Only one request (fetching tags) — no removeTag call made
    expect($history)->toHaveCount(1);
});

<?php

use App\Services\GitHubService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('fetches issues through the installed guzzle client', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            ['number' => 123, 'title' => 'Test issue'],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client = new Client([
        'base_uri' => 'https://api.github.com',
        'handler' => $handlerStack,
    ]);

    $service = new GitHubService($client, fn (): string => 'test-token');

    $issues = $service->getIssues('Lone-Rock-Point/lrpbot', ['agent-ready']);

    expect($issues)->toBe([
        ['number' => 123, 'title' => 'Test issue'],
    ]);

    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token');
    expect((string) $history[0]['request']->getUri())->toContain('/repos/Lone-Rock-Point/lrpbot/issues');
    expect((string) $history[0]['request']->getUri())->toContain('labels=agent-ready');
});

it('removes an issue label through the github api', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode([], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));

    $client = new Client([
        'base_uri' => 'https://api.github.com',
        'handler' => $handlerStack,
    ]);

    $service = new GitHubService($client, fn (): string => 'test-token');

    $service->removeLabel('Lone-Rock-Point/lrpbot', 193, 'agent-ready');

    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getMethod())->toBe('DELETE');
    expect((string) $history[0]['request']->getUri())->toContain('/repos/Lone-Rock-Point/lrpbot/issues/193/labels/agent-ready');
});

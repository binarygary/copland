<?php

use App\Services\AsanaService;
use App\Services\AsanaTaskSource;
use App\Services\GitHubService;

afterEach(function () {
    \Mockery::close();
});

it('delegates fetchTasks to AsanaService::getOpenTasks ignoring repo and tags args', function () {
    $asana  = \Mockery::mock(AsanaService::class);
    $github = \Mockery::mock(GitHubService::class);

    $asana->shouldReceive('getOpenTasks')
        ->once()
        ->withNoArgs()
        ->andReturn([['number' => '1234567890123456', 'title' => 'Fix bug']]);

    $source = new AsanaTaskSource($asana, $github);
    $result = $source->fetchTasks('owner/repo', ['agent-ready']);

    expect($result)->toBe([['number' => '1234567890123456', 'title' => 'Fix bug']]);
});

it('delegates addComment to AsanaService::addStory with taskId cast to string', function () {
    $asana  = \Mockery::mock(AsanaService::class);
    $github = \Mockery::mock(GitHubService::class);

    $asana->shouldReceive('addStory')
        ->once()
        ->with('1234567890123456', 'comment body');

    $source = new AsanaTaskSource($asana, $github);
    $source->addComment('owner/repo', '1234567890123456', 'comment body');
});

it('delegates openDraftPr to GitHubService::createDraftPr', function () {
    $asana  = \Mockery::mock(AsanaService::class);
    $github = \Mockery::mock(GitHubService::class);

    $github->shouldReceive('createDraftPr')
        ->once()
        ->with('owner/repo', 'my-branch', 'PR Title', 'PR body')
        ->andReturn(['html_url' => 'https://example.test/pr/1', 'number' => 1]);

    $source = new AsanaTaskSource($asana, $github);
    $result = $source->openDraftPr('owner/repo', 'my-branch', 'PR Title', 'PR body');

    expect($result)->toBe(['html_url' => 'https://example.test/pr/1', 'number' => 1]);
});

it('delegates removeTag to AsanaService::removeTag with taskId cast to string', function () {
    $asana  = \Mockery::mock(AsanaService::class);
    $github = \Mockery::mock(GitHubService::class);

    $asana->shouldReceive('removeTag')
        ->once()
        ->with('1234567890123456', 'agent-ready');

    $source = new AsanaTaskSource($asana, $github);
    $source->removeTag('owner/repo', '1234567890123456', 'agent-ready');
});

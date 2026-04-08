<?php

use App\Services\GitHubService;
use App\Services\GitHubTaskSource;

afterEach(function () {
    \Mockery::close();
});

it('delegates fetchTasks to GitHubService::getIssues', function () {
    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')
        ->once()
        ->with('owner/repo', ['agent-ready'])
        ->andReturn([['number' => 1]]);

    $source = new GitHubTaskSource($github);
    $result = $source->fetchTasks('owner/repo', ['agent-ready']);

    expect($result)->toBe([['number' => 1]]);
});

it('delegates addComment to GitHubService::commentOnIssue', function () {
    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('commentOnIssue')
        ->once()
        ->with('owner/repo', 42, 'body text');

    $source = new GitHubTaskSource($github);
    $source->addComment('owner/repo', 42, 'body text');
});

it('delegates openDraftPr to GitHubService::createDraftPr', function () {
    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('createDraftPr')
        ->once()
        ->with('owner/repo', 'my-branch', 'PR Title', 'PR body')
        ->andReturn(['html_url' => 'https://example.test/pr/1', 'number' => 1]);

    $source = new GitHubTaskSource($github);
    $result = $source->openDraftPr('owner/repo', 'my-branch', 'PR Title', 'PR body');

    expect($result)->toBe(['html_url' => 'https://example.test/pr/1', 'number' => 1]);
});

it('delegates removeTag to GitHubService::removeLabel', function () {
    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('removeLabel')
        ->once()
        ->with('owner/repo', 42, 'agent-ready');

    $source = new GitHubTaskSource($github);
    $source->removeTag('owner/repo', 42, 'agent-ready');
});

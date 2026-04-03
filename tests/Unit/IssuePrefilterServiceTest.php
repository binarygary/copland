<?php

use App\Config\RepoConfig;
use App\Services\GitHubService;
use App\Services\IssuePrefilterService;

it('rejects issues that already have an open linked pr', function () {
    $repoPath = sys_get_temp_dir().'/copland-prefilter-'.uniqid();
    mkdir($repoPath, 0755, true);
    $config = new RepoConfig($repoPath);

    $github = new class extends GitHubService
    {
        public function __construct() {}

        public function hasOpenLinkedPr(string $repo, int $issueNumber): bool
        {
            return $issueNumber === 193;
        }
    };

    $prefilter = new IssuePrefilterService($config, $github, 'Lone-Rock-Point/lrpbot');

    $result = $prefilter->filter([
        ['number' => 193, 'title' => 'Test', 'body' => 'Body', 'labels' => []],
        ['number' => 194, 'title' => 'Other', 'body' => 'Body', 'labels' => []],
    ]);

    expect($result->accepted)->toHaveCount(1);
    expect($result->accepted[0]['number'])->toBe(194);
    expect($result->rejected)->toHaveCount(1);
    expect($result->rejected[0]['reason'])->toBe('has open linked PR');
});

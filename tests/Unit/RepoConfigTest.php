<?php

use App\Config\RepoConfig;

it('bootstraps a default repo config file when missing', function () {
    $repoPath = sys_get_temp_dir().'/copland-repo-config-'.uniqid();
    mkdir($repoPath, 0755, true);

    $config = new RepoConfig($repoPath);

    $path = $repoPath.'/.copland.yml';

    expect(file_exists($path))->toBeTrue();
    expect($config->baseBranch())->toBe('main');
    expect($config->maxExecutorRounds())->toBe(12);
    expect($config->requiredLabels())->toBe(['agent-ready']);
    expect($config->blockedLabels())->toBe(['agent-skip', 'blocked']);
    expect($config->allowedCommands())->toBe(['php artisan', 'composer', 'npm', 'pest']);
    expect(file_get_contents($path))->toContain('base_branch: main');
    expect(file_get_contents($path))->toContain('max_executor_rounds: 12');
    expect(file_get_contents($path))->not->toContain('worktree_base');
    expect(file_get_contents($path))->toContain('repo_summary: ""');
});

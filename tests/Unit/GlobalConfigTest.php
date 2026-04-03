<?php

use App\Config\GlobalConfig;

it('bootstraps a default home config file at ~/.copland.yml', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-global-config-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $config = new GlobalConfig;

    $path = $home.'/.copland.yml';

    expect($config->claudeApiKey())->toBe('');
    expect($config->selectorModel())->toBe('claude-haiku-4-5');
    expect($config->plannerModel())->toBe('claude-sonnet-4-6');
    expect($config->executorModel())->toBe('claude-sonnet-4-6');
    expect($config->defaultMaxFiles())->toBe(3);
    expect($config->defaultMaxLines())->toBe(250);
    expect($config->defaultBaseBranch())->toBe('main');
    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toContain('claude_api_key: ""');
    expect(file_get_contents($path))->toContain('selector: claude-haiku-4-5');

    $_SERVER['HOME'] = $originalHome;
});

it('returns default retry config values when api.retry is not in config', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-global-config-retry-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $config = new GlobalConfig;

    expect($config->retryMaxAttempts())->toBe(3);
    expect($config->retryBaseDelaySeconds())->toBe(1);

    $_SERVER['HOME'] = $originalHome;
});

it('normalizes configured repos from strings and objects', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $originalCwd = getcwd();
    $home = sys_get_temp_dir().'/copland-global-config-repos-'.uniqid();
    $repoPath = $home.'/repo';

    mkdir($repoPath, 0755, true);
    $_SERVER['HOME'] = $home;
    chdir($repoPath);

    file_put_contents($home.'/.copland.yml', <<<'YAML'
claude_api_key: ""
repos:
  - owner/current
  - slug: owner/other
    path: /tmp/owner-other
YAML
    );

    mkdir($repoPath.'/.git', 0755, true);
    mkdir($repoPath.'/.git/refs', 0755, true);
    file_put_contents($repoPath.'/.git/config', <<<'GIT'
[remote "origin"]
    url = git@github.com:owner/current.git
GIT
    );

    $config = new GlobalConfig;

    expect($config->configuredRepos())->toBe([
        ['slug' => 'owner/current', 'path' => getcwd()],
        ['slug' => 'owner/other', 'path' => '/tmp/owner-other'],
    ]);

    chdir($originalCwd);
    $_SERVER['HOME'] = $originalHome;
});

it('requires an explicit path for string repos outside the current checkout', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $originalCwd = getcwd();
    $home = sys_get_temp_dir().'/copland-global-config-repos-invalid-'.uniqid();
    $repoPath = $home.'/repo';

    mkdir($repoPath, 0755, true);
    $_SERVER['HOME'] = $home;
    chdir($repoPath);

    file_put_contents($home.'/.copland.yml', <<<'YAML'
claude_api_key: ""
repos:
  - owner/other
YAML
    );

    mkdir($repoPath.'/.git', 0755, true);
    mkdir($repoPath.'/.git/refs', 0755, true);
    file_put_contents($repoPath.'/.git/config', <<<'GIT'
[remote "origin"]
    url = git@github.com:owner/current.git
GIT
    );

    $config = new GlobalConfig;

    expect(fn () => $config->configuredRepos())
        ->toThrow(RuntimeException::class, "Configured repo 'owner/other' needs an explicit path");

    chdir($originalCwd);
    $_SERVER['HOME'] = $originalHome;
});

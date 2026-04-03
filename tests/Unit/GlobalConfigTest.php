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

<?php

use Anthropic\Client;
use App\Config\GlobalConfig;
use App\Services\ClaudeExecutorService;
use App\Services\ClaudePlannerService;
use App\Services\ClaudeSelectorService;
use App\Support\AnthropicApiClient;

it('constructs claude services with the installed anthropic sdk', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-test-home-'.uniqid();

    mkdir($home.'/.copland', 0755, true);
    file_put_contents($home.'/.copland/config.yml', "claude_api_key: test-key\n");
    $_SERVER['HOME'] = $home;

    $config = new GlobalConfig;
    $apiClient = new AnthropicApiClient(
        client: new Client(apiKey: $config->claudeApiKey()),
        maxAttempts: $config->retryMaxAttempts(),
        baseDelaySeconds: $config->retryBaseDelaySeconds(),
    );

    $selector = new ClaudeSelectorService($config, $apiClient);
    $planner = new ClaudePlannerService($config, $apiClient);
    $executor = new ClaudeExecutorService($config, $apiClient);

    expect($selector)->toBeInstanceOf(ClaudeSelectorService::class);
    expect($planner)->toBeInstanceOf(ClaudePlannerService::class);
    expect($executor)->toBeInstanceOf(ClaudeExecutorService::class);

    $_SERVER['HOME'] = $originalHome;
});

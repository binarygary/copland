<?php

use App\Data\ModelUsage;
use App\Support\RunLogStore;

it('appends structured jsonl records under the global copland logs directory', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-run-log-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $store = new RunLogStore;

    $path = $store->append([
        'repo' => 'Lone-Rock-Point/lrpbot',
        'status' => 'succeeded',
        'usage' => [
            'selector' => new ModelUsage('claude-haiku', 100, 20, 0.001),
            'total' => new ModelUsage('combined', 300, 90, 0.006),
        ],
    ]);

    $store->append([
        'repo' => 'Lone-Rock-Point/lrpbot',
        'status' => 'failed',
    ]);

    expect($path)->toBe($home.'/.copland/logs/runs.jsonl');
    expect(file_exists($path))->toBeTrue();

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($lines)->toHaveCount(2);

    $first = json_decode($lines[0], true);
    $second = json_decode($lines[1], true);

    expect($first['repo'])->toBe('Lone-Rock-Point/lrpbot');
    expect($first['status'])->toBe('succeeded');
    expect($first['usage']['selector'])->toBe([
        'model' => 'claude-haiku',
        'input_tokens' => 100,
        'output_tokens' => 20,
        'estimated_cost_usd' => 0.001,
    ]);
    expect($first['usage']['total'])->toBe([
        'model' => 'combined',
        'input_tokens' => 300,
        'output_tokens' => 90,
        'estimated_cost_usd' => 0.006,
    ]);
    expect($second['status'])->toBe('failed');

    $_SERVER['HOME'] = $originalHome;
});

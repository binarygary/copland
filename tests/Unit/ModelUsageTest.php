<?php

use App\Data\ModelUsage;

it('correctly sums token counts across rounds', function () {
    $round1 = new ModelUsage('claude-3-5-sonnet', 1000, 500, 0.0105, 800, 0);
    $round2 = new ModelUsage('claude-3-5-sonnet', 1000, 500, 0.0035, 0, 800);

    $total = $round1->add($round2);

    expect($total->model)->toBe('claude-3-5-sonnet');
    expect($total->inputTokens)->toBe(2000);
    expect($total->outputTokens)->toBe(1000);
    expect($total->estimatedCostUsd)->toBe(0.014);
    expect($total->cacheWriteTokens)->toBe(800);
    expect($total->cacheReadTokens)->toBe(800);
});

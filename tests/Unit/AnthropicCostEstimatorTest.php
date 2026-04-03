<?php

use App\Data\ModelUsage;
use App\Support\AnthropicCostEstimator;

it('estimates sonnet usage cost from input and output tokens', function () {
    $usage = AnthropicCostEstimator::forModel('claude-sonnet-4-6', 1000, 500);

    expect($usage)->toBeInstanceOf(ModelUsage::class);
    expect($usage->inputTokens)->toBe(1000);
    expect($usage->outputTokens)->toBe(500);
    expect($usage->estimatedCostUsd)->toBe(0.0105);
});

it('estimates haiku 4.5 usage cost from input and output tokens', function () {
    $usage = AnthropicCostEstimator::forModel('claude-haiku-4-5', 1000, 500);

    expect($usage)->toBeInstanceOf(ModelUsage::class);
    expect($usage->inputTokens)->toBe(1000);
    expect($usage->outputTokens)->toBe(500);
    expect($usage->estimatedCostUsd)->toBe(0.0035);
});

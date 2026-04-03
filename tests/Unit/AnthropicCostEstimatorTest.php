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

it('applies cache discounts for sonnet', function () {
    // Sonnet rates: $3/M input, $15/M output
    // Scenario: 1000 tokens total in inputTokens (which includes 800 read tokens)
    // Plus 1000 tokens added to cache (cacheWrite)
    // Plus 500 output tokens
    
    // Uncached: (200 * 3)/1M = 0.0006
    // Cache Read: (800 * 0.3)/1M = 0.00024
    // Cache Write: (1000 * 3.75)/1M = 0.00375
    // Output: (500 * 15)/1M = 0.0075
    // Total: 0.0006 + 0.00024 + 0.00375 + 0.0075 = 0.01209
    
    $usage = AnthropicCostEstimator::forModel('claude-sonnet-4-6', 1000, 500, 1000, 800);
    
    expect($usage->estimatedCostUsd)->toBe(0.01209);
});

it('formats usage with cache breakdown', function () {
    $usage = AnthropicCostEstimator::forModel('claude-sonnet-4-6', 1000, 500, 1000, 800);
    $formatted = AnthropicCostEstimator::format($usage);
    
    expect($formatted)->toBe('1,000 input (+1,000 write, 800 read), 500 output, $0.0121 est.');
});

it('formats usage without cache breakdown when zero', function () {
    $usage = AnthropicCostEstimator::forModel('claude-sonnet-4-6', 1000, 500);
    $formatted = AnthropicCostEstimator::format($usage);
    
    expect($formatted)->toBe('1,000 input, 500 output, $0.0105 est.');
});

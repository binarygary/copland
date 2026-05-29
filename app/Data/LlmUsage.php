<?php

namespace App\Data;

final class LlmUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
        // Set by providers that report their own dollar cost (e.g. ClaudeCodeClient
        // via the `claude -p` envelope's total_cost_usd). When non-null, services
        // bypass AnthropicCostEstimator and emit a token-less ModelUsage.
        public readonly ?float $providerCostUsd = null,
    ) {}
}

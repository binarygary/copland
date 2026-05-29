<?php

namespace App\Data;

class ModelUsage
{
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $estimatedCostUsd,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}

    /**
     * Build a ModelUsage from a provider-reported dollar cost (e.g. the
     * `total_cost_usd` field in the Claude Code CLI envelope) with zero
     * token counts. The CLI envelope does not surface token counts; the
     * cost is authoritative.
     */
    public static function fromProviderCost(string $model, float $providerCostUsd): self
    {
        return new self(
            model: $model,
            inputTokens: 0,
            outputTokens: 0,
            estimatedCostUsd: $providerCostUsd,
            cacheWriteTokens: 0,
            cacheReadTokens: 0,
        );
    }

    public function add(?self $other): self
    {
        if ($other === null) {
            return $this;
        }

        return new self(
            model: $this->model,
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            estimatedCostUsd: round($this->estimatedCostUsd + $other->estimatedCostUsd, 6),
            cacheWriteTokens: $this->cacheWriteTokens + $other->cacheWriteTokens,
            cacheReadTokens: $this->cacheReadTokens + $other->cacheReadTokens,
        );
    }
}

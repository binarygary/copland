<?php

namespace App\Data;

class ModelUsage
{
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $estimatedCostUsd,
    ) {}

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
        );
    }
}

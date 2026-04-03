<?php

namespace App\Data;

class ExecutionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $summary,
        public readonly array $toolCallLog,
        public readonly int $toolCallCount,
        public readonly float $durationSeconds,
        public readonly ?ModelUsage $usage = null,
    ) {}
}

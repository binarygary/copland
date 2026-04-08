<?php

namespace App\Data;

class RunResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $prUrl,
        public readonly ?int $prNumber,
        public readonly ?string $selectedIssueTitle,
        public readonly string|int|null $selectedTaskId,
        public readonly ?string $failureReason,
        public readonly array $log,
        public readonly string $startedAt,
        public readonly string $finishedAt,
        public readonly ?ModelUsage $selectorUsage = null,
        public readonly ?ModelUsage $plannerUsage = null,
        public readonly ?ModelUsage $executorUsage = null,
        public readonly ?float $executorDurationSeconds = null,
    ) {}
}

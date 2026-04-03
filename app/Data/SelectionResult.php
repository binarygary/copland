<?php

namespace App\Data;

class SelectionResult
{
    public function __construct(
        public readonly string $decision,
        public readonly ?int $selectedIssueNumber,
        public readonly string $reason,
        public readonly array $rejections,
        public readonly ?ModelUsage $usage = null,
    ) {}
}

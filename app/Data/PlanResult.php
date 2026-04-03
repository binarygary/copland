<?php

namespace App\Data;

class PlanResult
{
    public function __construct(
        public readonly string $decision,
        public readonly ?string $branchName,
        public readonly array $filesToRead,
        public readonly array $filesToChange,
        public readonly array $steps,
        public readonly array $commandsToRun,
        public readonly array $testsToUpdate,
        public readonly array $successCriteria,
        public readonly array $guardrails,
        public readonly ?string $prTitle,
        public readonly ?string $prBody,
        public readonly int $maxFilesChanged,
        public readonly int $maxLinesChanged,
        public readonly ?string $declineReason,
        public readonly ?ModelUsage $usage = null,
    ) {}
}

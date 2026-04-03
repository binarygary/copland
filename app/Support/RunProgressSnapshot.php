<?php

namespace App\Support;

use App\Data\ModelUsage;

class RunProgressSnapshot
{
    public ?string $repo = null;

    public ?string $selectedIssueTitle = null;

    public ?int $selectedIssueNumber = null;

    public ?ModelUsage $selectorUsage = null;

    public ?ModelUsage $plannerUsage = null;

    public ?ModelUsage $executorUsage = null;

    public ?float $executorDurationSeconds = null;
}

<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Show last run result per registered repo';

    public function handle(): void
    {
        $this->line('Use "cat ~/.copland/logs/runs.jsonl" to view recent runs or "copland console" to open the web console.');
    }
}

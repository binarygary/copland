<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Show last run result per registered repo';

    public function handle(): void
    {
        $this->line('Status not yet implemented');
    }
}

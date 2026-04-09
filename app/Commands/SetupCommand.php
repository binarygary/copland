<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    protected $signature = 'setup
        {--hour=2 : Hour for the nightly launchd run (0-23)}
        {--minute=0 : Minute for the nightly launchd run (0-59)}';

    protected $description = 'Deprecated — use `copland automate` instead';

    protected $hidden = true;

    public function handle(): int
    {
        $this->line('⚠ `copland setup` is deprecated — use `copland automate` instead.');
        $this->line('Running `copland automate`...');

        return $this->call('automate', [
            '--hour' => $this->option('hour'),
            '--minute' => $this->option('minute'),
        ]);
    }
}

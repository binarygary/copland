<?php

namespace App\Commands;

use App\Support\RunLogStore;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Point at the run log and the Godot console for status review';

    public function handle(): void
    {
        // Pull the path from the writer so we can't drift; if it ever moves,
        // we move it in one place.
        $runsPath = (new RunLogStore)->path();

        $this->line('Copland writes a status surface in two places — use whichever fits your review flow:');
        $this->line('');
        $this->line('  - Append-only audit trail: ~/.copland/logs/runs.jsonl');
        $this->line("      ({$runsPath})");
        $this->line('      One JSON line per run. Pipe through `jq` for ad-hoc queries.');
        $this->line('  - Live task manifest: `copland console`');
        $this->line('      Godot UI with the task state per repo, including in-flight runs.');
    }
}

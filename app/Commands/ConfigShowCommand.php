<?php

namespace App\Commands;

use App\Config\GlobalConfig;
use App\Services\ConfigShowService;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigShowCommand extends Command
{
    protected $signature = 'config:show {--json : Emit machine-readable JSON snapshot}';

    protected $description = 'Print the merged global + per-repo configuration snapshot. See tests/fixtures/config/show-snapshot.json for the v1 schema.';

    public function handle(): int
    {
        $service = new ConfigShowService(new GlobalConfig);
        $snapshot = $service->snapshot();

        if ($this->option('json')) {
            $this->writeJson($snapshot);

            return self::SUCCESS;
        }

        $this->writeHuman($snapshot);

        return self::SUCCESS;
    }

    /**
     * In JSON mode we must emit exactly one JSON document followed by a single
     * newline on stdout, and nothing else. We bypass $this->line() because it
     * routes through Symfony Console formatting which can alter the bytes.
     *
     * @param  array<string,mixed>  $snapshot
     */
    private function writeJson(array $snapshot): void
    {
        $payload = json_encode($snapshot, JSON_UNESCAPED_SLASHES)."\n";
        $this->output->write($payload, false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Plain-text summary for the bare `config:show` invocation. Layout is
     * intentionally simple: one section per top-level snapshot key.
     *
     * @param  array<string,mixed>  $snapshot
     */
    private function writeHuman(array $snapshot): void
    {
        $this->line('Schema version: '.$snapshot['schema_version']);
        $this->line('');

        $this->line('Defaults:');
        foreach ($snapshot['defaults'] as $key => $value) {
            $this->line("  {$key}: {$value}");
        }
        $this->line('');

        $this->line('Asana token set: '.($snapshot['asana_token_set'] ? 'yes' : 'no'));
        $this->line('');

        $this->line('Repos:');
        if ($snapshot['repos'] === []) {
            $this->line('  (none configured)');

            return;
        }

        foreach ($snapshot['repos'] as $repo) {
            $this->line("  - slug: {$repo['slug']}");
            $this->line("    path: {$repo['path']}");
            $this->line('    asana_project: '.($repo['asana_project'] ?? '(none)'));
            $this->line('    asana_filters: '.(empty($repo['asana_filters']) ? '(none)' : json_encode($repo['asana_filters'])));
            $this->line('    local_config: '.($repo['local_config'] === null ? '(none)' : 'present'));
        }
    }
}

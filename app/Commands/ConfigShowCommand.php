<?php

namespace App\Commands;

use App\Config\GlobalConfig;
use App\Services\ConfigShowService;
use App\Support\HomeDirectory;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;

class ConfigShowCommand extends Command
{
    protected $signature = 'config:show {--json : Emit machine-readable JSON snapshot}';

    protected $description = 'Print the merged global + per-repo configuration snapshot. See tests/fixtures/config/show-snapshot.json for the v1 schema.';

    public function handle(): int
    {
        // --- Preflight 1: ~/.copland.yml (or legacy ~/.copland/config.yml) MUST exist.
        // We check BEFORE instantiating GlobalConfig because GlobalConfig's ctor
        // auto-creates a default file via ensureExists() and would mask this case.
        $home = HomeDirectory::resolve();
        $preferred = $home.'/.copland.yml';
        $legacy = $home.'/.copland/config.yml';

        if (! file_exists($preferred) && ! file_exists($legacy)) {
            $this->writeError("Global config not found: expected {$preferred} (or legacy {$legacy}).");

            return self::FAILURE;
        }

        // --- Preflight 2: YAML must parse. Wrap GlobalConfig construction.
        try {
            $globalConfig = new GlobalConfig;
        } catch (ParseException $e) {
            $path = file_exists($preferred) ? $preferred : $legacy;
            $this->writeError("Failed to parse {$path}: ".$e->getMessage());

            return self::FAILURE;
        }

        // --- Preflight 3: every configured repo path must exist on disk.
        try {
            $configured = $globalConfig->configuredRepos();
        } catch (RuntimeException $e) {
            $this->writeError($e->getMessage());

            return self::FAILURE;
        }

        foreach ($configured as $repo) {
            if (! is_dir($repo['path'])) {
                $this->writeError("Configured repo '{$repo['slug']}' path does not exist: {$repo['path']}");

                return self::FAILURE;
            }
        }

        // --- All preflights passed: build the snapshot.
        $snapshot = (new ConfigShowService($globalConfig))->snapshot();

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

    /**
     * Route an error to stderr so JSON-mode stdout stays pure. ConsoleOutput
     * exposes a dedicated error stream; SymfonyStyle / $this->error() are
     * avoided because they may format or wrap.
     */
    private function writeError(string $message): void
    {
        $line = $message."\n";
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->write($line, false, OutputInterface::OUTPUT_RAW);

            return;
        }

        // Fallback for environments that hand the command a non-Console output
        // (e.g. CommandTester without capture_stderr_separately): write to stderr
        // directly so the test never sees the error on stdout.
        fwrite(STDERR, $line);
    }
}

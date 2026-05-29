<?php

namespace App\Commands;

use App\Support\GlobalConfigPath;
use App\Support\YamlBlockEditor;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConfigReposRemoveCommand extends Command
{
    protected $signature = 'config:repos:remove {--slug= : owner/repo identifier to remove}';

    protected $description = 'Drop a repo entry from the global ~/.copland.yml repos[] list. The Godot UI owns its own confirmation step.';

    public function handle(): int
    {
        $slug = $this->option('slug');

        if ($slug === null || $slug === '') {
            $this->writeError('Missing required flag: --slug');

            return self::FAILURE;
        }

        if (! GlobalConfigPath::exists()) {
            $paths = GlobalConfigPath::candidates();
            $this->writeError("Global config not found: expected {$paths['preferred']} (or legacy {$paths['legacy']}).");

            return self::FAILURE;
        }

        $activePath = GlobalConfigPath::activePath();

        try {
            Yaml::parseFile($activePath);
        } catch (ParseException $e) {
            $this->writeError("Failed to parse {$activePath}: ".$e->getMessage());

            return self::FAILURE;
        }

        $editor = new YamlBlockEditor($activePath);
        $existing = $editor->readBlock('repos');
        $repos = is_array($existing) ? $existing : [];

        $remaining = [];
        $found = false;

        foreach ($repos as $entry) {
            if ($this->extractSlug($entry) === $slug) {
                $found = true;

                continue;
            }
            $remaining[] = $entry;
        }

        if (! $found) {
            $this->writeError("Repo '{$slug}' not found in ~/.copland.yml.");

            return self::FAILURE;
        }

        // We deliberately write back even when the list is now empty —
        // Phase 25/26 read the empty list as "block present, no entries"
        // rather than "block missing". We do NOT call deleteBlock.
        $editor->writeBlock('repos', $remaining);

        $this->info("Removed repo: {$slug}");

        return self::SUCCESS;
    }

    private function extractSlug(mixed $entry): ?string
    {
        if (is_string($entry)) {
            return trim($entry) === '' ? null : trim($entry);
        }

        if (is_array($entry) && isset($entry['slug']) && is_string($entry['slug'])) {
            return trim($entry['slug']);
        }

        return null;
    }

    private function writeError(string $message): void
    {
        $line = $message."\n";
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->write($line, false, OutputInterface::OUTPUT_RAW);

            return;
        }

        fwrite(STDERR, $line);
    }
}

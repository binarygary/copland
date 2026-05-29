<?php

namespace App\Commands;

use App\Support\GlobalConfigPath;
use App\Support\YamlBlockEditor;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConfigReposEditCommand extends Command
{
    protected $signature = 'config:repos:edit {--slug= : owner/repo identifier to locate} {--path= : New absolute path for the local checkout}';

    protected $description = 'Rewrite the path of an existing repo entry in ~/.copland.yml. Slug is immutable.';

    public function handle(): int
    {
        $slug = $this->option('slug');
        $path = $this->option('path');

        if ($slug === null || $slug === '') {
            $this->writeError('Missing required flag: --slug');

            return self::FAILURE;
        }

        if ($path === null || $path === '') {
            $this->writeError('Missing required flag: --path');

            return self::FAILURE;
        }

        if (! is_dir($path)) {
            $this->writeError("Path '{$path}' does not exist or is not a directory.");

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

        $found = false;
        foreach ($repos as $idx => $entry) {
            $entrySlug = $this->extractSlug($entry);

            if ($entrySlug === $slug) {
                // Slug-only string entries become array entries on edit (we now
                // own a path for them). Slug stays — only path is rewritten.
                $repos[$idx] = ['slug' => $slug, 'path' => $path];
                $found = true;
                break;
            }
        }

        if (! $found) {
            $this->writeError("Repo '{$slug}' not found in ~/.copland.yml.");

            return self::FAILURE;
        }

        $editor->writeBlock('repos', $repos);

        $this->info("Updated repo: {$slug}");

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

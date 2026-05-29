<?php

namespace App\Commands;

use App\Support\GlobalConfigPath;
use App\Support\YamlBlockEditor;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConfigReposAddCommand extends Command
{
    protected $signature = 'config:repos:add {--slug= : owner/repo identifier (e.g. acme/api)} {--path= : Absolute path to the local checkout}';

    protected $description = 'Append a repo {slug, path} entry to the global ~/.copland.yml repos[] list.';

    // Slug regex: owner/repo, both segments restricted to safe filename chars.
    // Reused by config:repos:edit. Single source of truth.
    private const SLUG_REGEX = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';

    public function handle(): int
    {
        $slug = $this->option('slug');
        $path = $this->option('path');

        // --- Flag presence (T-24-01: validate BEFORE any filesystem touch).
        if ($slug === null || $slug === '') {
            $this->writeError('Missing required flag: --slug');

            return self::FAILURE;
        }

        if ($path === null || $path === '') {
            $this->writeError('Missing required flag: --path');

            return self::FAILURE;
        }

        // --- Slug format (T-24-01: shell-meta and path-traversal rejected here).
        if (preg_match(self::SLUG_REGEX, $slug) !== 1) {
            $this->writeError("Invalid slug '{$slug}'. Expected owner/repo format (alphanumerics, dot, dash, underscore).");

            return self::FAILURE;
        }

        // --- Path validation (T-24-01).
        if (! is_dir($path)) {
            $this->writeError("Path '{$path}' does not exist or is not a directory.");

            return self::FAILURE;
        }

        // --- Preflight 1: ~/.copland.yml MUST exist (mirrors ConfigShowCommand).
        if (! GlobalConfigPath::exists()) {
            $paths = GlobalConfigPath::candidates();
            $this->writeError("Global config not found: expected {$paths['preferred']} (or legacy {$paths['legacy']}).");

            return self::FAILURE;
        }

        // --- Preflight 2: WHOLE file must parse. Done as a full-file parse
        // (not just the repos block) so a malformed `defaults:` block still
        // surfaces a parse error instead of silently writing into a broken file.
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

        // --- Duplicate-slug detection (handles string and array entry forms).
        foreach ($repos as $entry) {
            $existingSlug = $this->extractSlug($entry);
            if ($existingSlug === $slug) {
                $this->writeError("Repo '{$slug}' already exists. Use config:repos:edit to change its path.");

                return self::FAILURE;
            }
        }

        $repos[] = ['slug' => $slug, 'path' => $path];

        $editor->writeBlock('repos', $repos);

        $this->info("Added repo: {$slug}");

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

    /**
     * Route an error to stderr so JSON-mode (downstream) stdout stays pure.
     * Mirrors ConfigShowCommand::writeError so behavior is uniform.
     */
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

<?php

namespace App\Commands;

use App\Config\GlobalConfig;
use App\Services\TaskListService;
use App\Support\HomeDirectory;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Throwable;

class TasksCommand extends Command
{
    protected $signature = 'tasks
        {repo? : Limit to a single owner/repo (default: all registered repos)}
        {--json : Emit the machine-readable task manifest as JSON}
        {--refresh : Bypass the issue cache and refetch from GitHub}';

    protected $description = 'Emit the unified Task Manifest (GitHub backlog enriched with run-store state) consumed by the Godot console.';

    public function handle(): int
    {
        $home = HomeDirectory::resolve();
        $preferred = $home.'/.copland.yml';
        $legacy = $home.'/.copland/config.yml';

        if (! file_exists($preferred) && ! file_exists($legacy)) {
            $this->writeError("Global config not found: expected {$preferred} (or legacy {$legacy}).");

            return self::FAILURE;
        }

        try {
            $globalConfig = new GlobalConfig;
        } catch (ParseException $e) {
            $path = file_exists($preferred) ? $preferred : $legacy;
            $this->writeError("Failed to parse {$path}: ".$e->getMessage());

            return self::FAILURE;
        }

        try {
            $tasks = (new TaskListService($globalConfig))->build(
                onlyRepo: $this->argument('repo'),
                forceRefresh: (bool) $this->option('refresh'),
            );
        } catch (Throwable $e) {
            $this->writeError('Failed to build task manifest: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->writeJson(['schema_version' => 1, 'tasks' => $tasks]);

            return self::SUCCESS;
        }

        $this->writeHuman($tasks);

        return self::SUCCESS;
    }

    /**
     * Emit exactly one JSON document plus a trailing newline on stdout, nothing
     * else — the Godot console parses stdout as JSON (see ConfigShowCommand).
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): void
    {
        $this->output->write(
            json_encode($payload, JSON_UNESCAPED_SLASHES)."\n",
            false,
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     */
    private function writeHuman(array $tasks): void
    {
        if ($tasks === []) {
            $this->line('No tasks. (No matching GitHub issues across registered repos.)');

            return;
        }

        $this->table(
            ['ID', 'State', 'Repo', 'Title'],
            array_map(fn ($t) => [
                $t['id'],
                $t['state'],
                $t['repo'],
                $t['title'],
            ], $tasks),
        );
    }

    /**
     * Route errors to stderr so JSON-mode stdout stays pure.
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

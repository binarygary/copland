<?php

namespace App\Services;

use App\Support\HomeDirectory;
use RuntimeException;

class TaskDirectoryWriterService
{
    private array $lastState = [];

    public function __construct(
        private $clock = null,
        private ?string $homeOverride = null,
    ) {}

    public function writeNewTask(
        string $repoSlug,
        string|int $taskId,
        ?string $title,
        ?string $body,
        string $repoPath,
        ?string $sourceUrl,
    ): void {
        $dir = $this->taskDir($repoSlug, $taskId);
        $this->ensureDirectoryExists($dir);

        $frontmatter = $this->renderFrontmatter([
            'id' => (string) $taskId,
            'title' => (string) ($title ?? ''),
            'repo_slug' => $repoSlug,
            'repo_path' => $repoPath,
            'source_url' => (string) ($sourceUrl ?? ''),
            'created_at' => $this->now(),
        ]);

        $content = "---\n{$frontmatter}---\n\n".((string) ($body ?? ''))."\n";

        $this->atomicWrite($dir.'/task.md', $content);
    }

    public function writeStatus(string $repoSlug, string|int $taskId, string $state): void
    {
        $dir = $this->taskDir($repoSlug, $taskId);
        $this->ensureDirectoryExists($dir);

        $statusPath = $dir.'/status.md';
        $now = $this->now();

        $frontmatter = $this->renderFrontmatter([
            'state' => $state,
            'updated_at' => $now,
        ]);

        $newRow = "| {$now} | {$state} |\n";

        if (is_file($statusPath)) {
            $existing = (string) file_get_contents($statusPath);
            $body = $this->extractBody($existing).$newRow;
        } else {
            $body = "## Transitions\n\n| Timestamp (UTC)        | State     |\n|------------------------|-----------|\n".$newRow;
        }

        $content = "---\n{$frontmatter}---\n\n{$body}";

        $this->atomicWrite($statusPath, $content);

        $this->lastState["{$repoSlug}/{$taskId}"] = $state;
    }

    public function writeBlockedIfNotTerminal(string $repoSlug, string|int $taskId): void
    {
        $current = $this->lastState["{$repoSlug}/{$taskId}"] ?? null;

        if ($current === null || $current === 'pr_open' || $current === 'blocked') {
            return;
        }

        $this->writeStatus($repoSlug, $taskId, 'blocked');
    }

    private function now(): string
    {
        if ($this->clock !== null) {
            return (string) ($this->clock)();
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function taskDir(string $repoSlug, string|int $taskId): string
    {
        $home = $this->homeOverride ?? HomeDirectory::resolve();
        $repoDir = str_replace('/', '__', $repoSlug);

        return "{$home}/.copland/tasks/{$repoDir}/".((string) $taskId);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create task directory at {$directory}");
        }
    }

    // Atomic-rename invariant: write to a sibling tmp file in the destination directory,
    // then rename onto the target. POSIX guarantees rename atomicity on the same filesystem
    // so readers (the Godot console) never observe a partial YAML frontmatter.
    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path.'.tmp';

        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Failed to write {$tmp}");
        }

        if (! rename($tmp, $path)) {
            throw new RuntimeException("Failed to rename {$tmp} to {$path}");
        }
    }

    private function renderFrontmatter(array $pairs): string
    {
        $rendered = '';

        foreach ($pairs as $key => $value) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
            $rendered .= "{$key}: \"{$escaped}\"\n";
        }

        return $rendered;
    }

    private function extractBody(string $existing): string
    {
        // Strip leading "---\n" if present, then strip frontmatter through the closing "---\n",
        // then strip the single blank line we always emit after the closing delimiter.
        if (! str_starts_with($existing, "---\n")) {
            return $existing;
        }

        $afterOpen = substr($existing, 4);
        $closePos = strpos($afterOpen, "\n---\n");

        if ($closePos === false) {
            return $existing;
        }

        $body = substr($afterOpen, $closePos + 5);

        if (str_starts_with($body, "\n")) {
            $body = substr($body, 1);
        }

        return $body;
    }
}

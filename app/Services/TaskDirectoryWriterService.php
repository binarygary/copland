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
        $key = "{$repoSlug}/{$taskId}";
        $current = $this->lastState[$key] ?? null;

        // pr_open and blocked are terminal. Any forward transition past them is
        // a bug (replay, recovery, or accidental orchestrator regression) — we'd
        // rather throw than silently revert pr_open back to new. A no-op repeat
        // of the same terminal state stays idempotent so retry-safe callers
        // can call writeStatus(pr_open) twice without surprise.
        if ($current === 'pr_open' || $current === 'blocked') {
            if ($current === $state) {
                return;
            }

            throw new RuntimeException(
                "Cannot transition {$key} from terminal state '{$current}' to '{$state}'."
            );
        }

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
            $body = self::freshTransitionsBody().$newRow;
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

    public function writeRunStatus(string $repoSlug, string|int $taskId, string $runId, string $state): void
    {
        $dir = $this->runDir($repoSlug, $taskId, $runId);
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
            $body = self::freshTransitionsBody().$newRow;
        }

        $content = "---\n{$frontmatter}---\n\n{$body}";

        $this->atomicWrite($statusPath, $content);

        // D-07: per-run state uses a 3-tuple key, coexisting with the task-level 2-tuple key.
        $this->lastState["{$repoSlug}/{$taskId}/runs/{$runId}"] = $state;
    }

    public function writeRunBlockedIfNotTerminal(string $repoSlug, string|int $taskId, string $runId): void
    {
        $current = $this->lastState["{$repoSlug}/{$taskId}/runs/{$runId}"] ?? null;

        if ($current === null || $current === 'pr_open' || $current === 'blocked') {
            return;
        }

        $this->writeRunStatus($repoSlug, $taskId, $runId, 'blocked');
    }

    public function writeRunOutcome(string $repoSlug, string|int $taskId, string $runId, array $outcome): void
    {
        $dir = $this->runDir($repoSlug, $taskId, $runId);
        $this->ensureDirectoryExists($dir);

        // The 9 D-05 keys (caller pre-builds via outcomePayload helper):
        //   run_id, status, pr_number, pr_url, cost_usd, started_at, finished_at, failure_reason, partial
        // Optional '_body' key (stripped before frontmatter render) carries an optional per-stage usage table.
        $body = isset($outcome['_body']) ? (string) $outcome['_body'] : '';
        unset($outcome['_body']);

        $frontmatter = $this->renderFrontmatter($outcome);

        $content = "---\n{$frontmatter}---\n\n{$body}";

        $this->atomicWrite($dir.'/outcome.md', $content);
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

    private function runDir(string $repoSlug, string|int $taskId, string $runId): string
    {
        return $this->taskDir($repoSlug, $taskId)."/runs/{$runId}";
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        // Use 0700 so the task store (which contains issue bodies, repo paths,
        // and other potentially sensitive context) is owner-only by default.
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
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
            // Escape \, ", \n, \r so the GDScript reader's line-based frontmatter
            // termination heuristic doesn't break on embedded newlines in values like
            // failure_reason (which can contain stderr/diff snippets).
            $escaped = str_replace(
                ["\\", "\"", "\n", "\r"],
                ['\\\\', '\\"', '\\n', '\\r'],
                (string) $value
            );
            $rendered .= "{$key}: \"{$escaped}\"\n";
        }

        return $rendered;
    }

    private function extractBody(string $existing): string
    {
        // Strip leading "---\n" if present, then strip frontmatter through the closing "---\n",
        // then strip the single blank line we always emit after the closing delimiter.
        //
        // If either delimiter is missing (truncation, partial write, manual edit),
        // returning $existing made writeStatus prepend a fresh frontmatter block in front
        // of the malformed file — each subsequent call then doubled the trapped content,
        // so the file size compounded and the embedded "---" markers became
        // indistinguishable. Reset to a fresh transitions table instead so the next
        // write produces a clean status.md.
        if (! str_starts_with($existing, "---\n")) {
            return self::freshTransitionsBody();
        }

        $afterOpen = substr($existing, 4);
        $closePos = strpos($afterOpen, "\n---\n");

        if ($closePos === false) {
            return self::freshTransitionsBody();
        }

        $body = substr($afterOpen, $closePos + 5);

        if (str_starts_with($body, "\n")) {
            $body = substr($body, 1);
        }

        return $body;
    }

    private static function freshTransitionsBody(): string
    {
        return "## Transitions\n\n| Timestamp (UTC)        | State     |\n|------------------------|-----------|\n";
    }
}

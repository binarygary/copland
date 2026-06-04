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
        $dir = $this->taskDir($repoSlug, $taskId);
        $statusPath = $dir.'/status.md';

        // Hydrate from disk on cache miss: $lastState is process-scoped and
        // RunCommand constructs a fresh writer per cron invocation, so the
        // in-memory check alone would let the next tick happily overwrite a
        // pr_open / blocked status.md.
        $this->hydrateLastState($key, $statusPath);

        $current = $this->lastState[$key] ?? null;

        // pr_open and blocked are terminal. The guard is specifically aimed at
        // *silent* regression — a planner/executor/recovery path overwriting a
        // terminal status with verifying/executing/etc. and erasing the prior
        // outcome trail.
        //
        // 'new' is permitted as a deliberate cycle reset, but ONLY from blocked:
        //   - blocked → 'new'  : legitimate retry. Most failure paths leave the
        //                        agent-ready label intact, so the task is re-
        //                        selected on the next cron tick and the
        //                        orchestrator writes 'new' at the top of run().
        //   - pr_open → 'new'  : forbidden. A pr_open task should never be
        //                        re-selected (prefilter excludes issues with
        //                        an open linked PR); allowing this would let a
        //                        regressed selector silently rewind a success
        //                        terminal — the exact class of bug the guard
        //                        was created to catch.
        // Same state repeated is idempotent (terminal re-emission stays safe).
        if ($current === 'pr_open' || $current === 'blocked') {
            if ($current === $state) {
                return;
            }

            if ($state === 'new' && $current === 'blocked') {
                // Allowed: see comment above. Fall through to the write below.
            } else {
                throw new RuntimeException(
                    "Cannot transition {$key} from terminal state '{$current}' to '{$state}'."
                );
            }
        }

        $this->ensureDirectoryExists($dir);

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
        $key = "{$repoSlug}/{$taskId}";
        $this->hydrateLastState($key, $this->taskDir($repoSlug, $taskId).'/status.md');

        $current = $this->lastState[$key] ?? null;

        if ($current === null || $current === 'pr_open' || $current === 'blocked') {
            return;
        }

        $this->writeStatus($repoSlug, $taskId, 'blocked');
    }

    public function writeRunStatus(string $repoSlug, string|int $taskId, string $runId, string $state): void
    {
        $key = "{$repoSlug}/{$taskId}/runs/{$runId}";
        $dir = $this->runDir($repoSlug, $taskId, $runId);
        $statusPath = $dir.'/status.md';

        // Mirror writeStatus: hydrate from disk on cache miss so the terminal
        // guard survives a fresh process (cron tick, replay, recovery).
        // claude + copilot: the orchestrator calls writeRunStatus directly, so
        // without the same forward-only guard a future regression or recovery
        // path could silently revert a run's pr_open back to new.
        //
        // The blocked→'new' cycle-reset carve-out is the same as writeStatus —
        // a reused runId after a blocked outcome is the only legitimate way a
        // per-run state could re-enter 'new'. pr_open→'new' stays forbidden:
        // re-emitting 'new' under a runId that already opened a PR would
        // silently rewrite a success terminal.
        $this->hydrateLastState($key, $statusPath);

        $current = $this->lastState[$key] ?? null;

        if ($current === 'pr_open' || $current === 'blocked') {
            if ($current === $state) {
                return;
            }

            if ($state === 'new' && $current === 'blocked') {
                // Allowed: see writeStatus() for the rationale.
            } else {
                throw new RuntimeException(
                    "Cannot transition {$key} from terminal state '{$current}' to '{$state}'."
                );
            }
        }

        $this->ensureDirectoryExists($dir);

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
        $key = "{$repoSlug}/{$taskId}/runs/{$runId}";
        $this->hydrateLastState($key, $this->runDir($repoSlug, $taskId, $runId).'/status.md');

        $current = $this->lastState[$key] ?? null;

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

    /**
     * Cache miss → check status.md on disk so the terminal guard works
     * across process restarts (cron tick, replay, recovery).
     */
    private function hydrateLastState(string $key, string $statusPath): void
    {
        if (isset($this->lastState[$key])) {
            return;
        }

        $state = $this->readPersistedState($statusPath);
        if ($state !== null) {
            $this->lastState[$key] = $state;
        }
    }

    /**
     * Parse the `state: "X"` value out of an existing status.md's frontmatter.
     * Returns null when the file is missing or has no state key.
     *
     * Fail-closed on a failed read (copilot inline #53): if status.md exists
     * but file_get_contents fails (permissions, disk error), don't silently
     * cast false to '' and let the caller treat that as "no prior state" —
     * the terminal guard would then allow overwriting an existing terminal
     * status.md the read couldn't see. Throw so the run aborts visibly.
     */
    private function readPersistedState(string $statusPath): ?string
    {
        if (! is_file($statusPath)) {
            return null;
        }

        $existing = @file_get_contents($statusPath);
        if ($existing === false) {
            throw new RuntimeException("Failed to read persisted state from {$statusPath}");
        }

        if (! str_starts_with($existing, "---\n")) {
            return null;
        }

        $afterOpen = substr($existing, 4);
        $closePos = strpos($afterOpen, "\n---\n");

        if ($closePos === false) {
            return null;
        }

        $frontmatter = substr($afterOpen, 0, $closePos);

        if (preg_match('/^state:\s*"([^"]*)"/m', $frontmatter, $matches) === 1) {
            return $matches[1];
        }

        return null;
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

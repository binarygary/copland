<?php

namespace App\Support;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Symfony Process wrapper that invokes the Codex CLI (`codex exec`) in
 * headless / single-shot mode and returns the parsed structured result.
 *
 * Like the Claude Code provider, Codex drives its own internal agentic loop
 * with its native tools, sandboxed by `--sandbox`; from Copland's perspective
 * each runStage() call is a single opaque round-trip: prompt in (via stdin),
 * schema-constrained JSON out. The prompt is piped on stdin because `codex exec`
 * reads instructions from stdin when no positional prompt is given — which
 * sidesteps argv quoting entirely. The final message is read from the
 * `--output-last-message` file (authoritative), and token usage is scraped from
 * the `turn.completed` event in the `--json` JSONL stream.
 *
 * Non-final to allow test substitution; subclass only in tests.
 */
class CodexRunner
{
    /**
     * @param  Closure(array<int, string>, string, int, string): object|null  $processFactory
     *                                                                                         Optional factory taking ($argv, $cwd, $timeoutSeconds, $stdin) and returning a
     *                                                                                         Process-like object exposing run()/isSuccessful()/getOutput()/getErrorOutput()/getExitCode().
     */
    public function __construct(
        private string $binaryPath = 'codex',
        private ?Closure $processFactory = null,
    ) {}

    /**
     * Execute a single `codex exec` invocation and return the structured result.
     *
     * @param  string  $prompt  Instructions — piped to the process via stdin.
     * @param  string  $jsonSchema  Pre-encoded JSON schema, written to a temp file for --output-schema.
     * @param  string  $sandbox  read-only | workspace-write | danger-full-access.
     * @param  string  $cwd  Workspace dir; passed via -C AND set as process cwd.
     * @return array{structured_output: mixed, usage: array{input_tokens:int, output_tokens:int, cached_input_tokens:int}}
     */
    public function runStage(
        string $prompt,
        string $jsonSchema,
        string $sandbox,
        string $cwd,
        ?string $model = null,
        int $timeoutSeconds = 600,
    ): array {
        // Created inside the try so a failure mid-creation can't leak a temp file
        // (finally only unlinks what was actually assigned).
        $schemaFile = null;
        $outFile = null;

        try {
            $schemaFile = $this->tempFile('schema');
            $outFile = $this->tempFile('out');

            if (file_put_contents($schemaFile, $jsonSchema) === false) {
                throw new RuntimeException('codex: failed to write schema temp file');
            }

            $argv = [
                $this->binaryPath, 'exec',
                '--json',
                '--ephemeral',
                '--skip-git-repo-check',
                '-s', $sandbox,
                '-C', $cwd,
                '--output-schema', $schemaFile,
                '-o', $outFile,
            ];

            if ($model !== null) {
                // Defensive: array-form Process bypasses the shell, but reject
                // anything that could be read as a flag regardless.
                if (! preg_match('#^[A-Za-z0-9._:/-]+$#', $model)) {
                    throw new RuntimeException("codex: invalid model name '{$model}'");
                }
                $argv[] = '-m';
                $argv[] = $model;
            }

            $process = $this->buildProcess($argv, $cwd, $timeoutSeconds, $prompt);
            $process->run();

            if (! $process->isSuccessful()) {
                $exit = $process->getExitCode();
                // The actionable failure (e.g. an invalid_json_schema 400) is in
                // the JSONL `error`/`turn.failed` events on stdout, not stderr —
                // stderr carries unrelated noise (MCP auth, deprecations). Prefer
                // the stream error so failures are diagnosable.
                $detail = $this->extractStreamError((string) $process->getOutput())
                    ?? (trim($process->getErrorOutput()) ?: '(no stderr)');
                throw new RuntimeException("codex: process exited with status {$exit}: {$detail}");
            }

            // The --output-last-message file holds the final, schema-constrained
            // message. Codex emits non-fatal warnings (deprecations, MCP auth) on
            // exit 0, so success is gated on exit code + a parseable result, not
            // on the absence of "error" events in the stream.
            $last = @file_get_contents($outFile);
            if ($last === false || trim($last) === '') {
                throw new RuntimeException('codex: produced no final message');
            }

            $structured = json_decode(trim($last), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('codex: failed to parse final message JSON: '.json_last_error_msg());
            }

            return [
                'structured_output' => $structured,
                'usage' => $this->parseUsage((string) $process->getOutput()),
            ];
        } finally {
            if ($schemaFile !== null) {
                @unlink($schemaFile);
            }
            if ($outFile !== null) {
                @unlink($outFile);
            }
        }
    }

    /**
     * Scrape token usage from the `turn.completed` event in the JSONL stream.
     * Returns zeros when absent — Codex runs on a ChatGPT subscription, so there
     * is no per-call USD cost to surface.
     *
     * @return array{input_tokens:int, output_tokens:int, cached_input_tokens:int}
     */
    private function parseUsage(string $stdout): array
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cached_input_tokens' => 0];

        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event) || ($event['type'] ?? '') !== 'turn.completed') {
                continue;
            }
            $u = is_array($event['usage'] ?? null) ? $event['usage'] : [];
            $usage['input_tokens'] = (int) ($u['input_tokens'] ?? 0);
            $usage['output_tokens'] = (int) ($u['output_tokens'] ?? 0);
            $usage['cached_input_tokens'] = (int) ($u['cached_input_tokens'] ?? 0);
        }

        return $usage;
    }

    /**
     * Extract the most relevant error message from the JSONL stream — codex
     * reports request failures (e.g. invalid_json_schema) as `error` and
     * `turn.failed` events on stdout rather than as a non-zero stderr message.
     * Returns null when no such event is present.
     */
    private function extractStreamError(string $stdout): ?string
    {
        // A turn.failed message is the authoritative failure; a generic `error`
        // event (e.g. a trailing deprecation) must never override it, regardless
        // of order. Track each kind separately and prefer turn.failed.
        $turnFailed = null;
        $genericError = null;

        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? '';
            if ($type === 'turn.failed' && isset($event['error']['message'])) {
                $turnFailed = (string) $event['error']['message'];
            } elseif ($type === 'error' && isset($event['message'])) {
                $genericError = (string) $event['message'];
            }
        }

        return $turnFailed ?? $genericError;
    }

    private function tempFile(string $kind): string
    {
        $path = tempnam(sys_get_temp_dir(), "copland-codex-{$kind}-");
        if ($path === false) {
            throw new RuntimeException("codex: failed to create temp {$kind} file");
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $argv
     */
    private function buildProcess(array $argv, string $cwd, int $timeoutSeconds, string $input): object
    {
        if ($this->processFactory !== null) {
            return ($this->processFactory)($argv, $cwd, $timeoutSeconds, $input);
        }

        $process = new Process($argv, $cwd);
        $process->setTimeout($timeoutSeconds);
        $process->setInput($input);

        return $process;
    }
}

<?php

namespace App\Support;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Symfony Process wrapper that invokes the Claude Code CLI (`claude -p`) in
 * headless / single-shot mode and returns the parsed JSON envelope.
 *
 * The CLI drives its own internal agentic loop with its native tools (Read,
 * Edit, Bash, etc.); from Copland's perspective each `runStage()` call is a
 * single opaque round-trip: prompt + allowed-tools whitelist in, structured
 * JSON out, plus a `total_cost_usd` value we surface as provider cost.
 *
 * Non-final to allow test substitution; subclass only in tests.
 */
class ClaudeCodeRunner
{
    /**
     * @param  Closure(array<int, string>, string, int): object|null  $processFactory
     *                                                                                 Optional factory that takes ($argv, $cwd, $timeoutSeconds) and returns
     *                                                                                 a Process-like object exposing run() / isSuccessful() / getOutput() /
     *                                                                                 getErrorOutput() / getExitCode(). Default builds a real Symfony Process.
     */
    public function __construct(
        private string $binaryPath = 'claude',
        private ?Closure $processFactory = null,
    ) {}

    /**
     * Execute a single `claude -p` invocation and return the parsed envelope.
     *
     * @param  string  $prompt  User prompt — placed as the LAST positional arg.
     * @param  string  $jsonSchema  Pre-encoded JSON schema, passed inline to --json-schema.
     * @param  array<int, string>  $allowedTools  e.g. ['Read', 'Edit', 'Bash(git status)']; [] = no tools.
     * @param  string  $cwd  Workspace dir; passed via --add-dir AND set as process cwd.
     * @return array{result: mixed, total_cost_usd: float, raw: array<string, mixed>}
     */
    public function runStage(
        string $prompt,
        string $jsonSchema,
        array $allowedTools,
        string $cwd,
        ?string $model = null,
        ?float $maxBudgetUsd = null,
        int $timeoutSeconds = 600,
    ): array {
        $argv = [
            $this->binaryPath,
            '-p',
            '--output-format', 'json',
            '--no-session-persistence',
            '--add-dir', $cwd,
            '--json-schema', $jsonSchema,
        ];

        // Pass each tool as a separate --allowedTools value so patterns containing
        // spaces (e.g. "Bash(git status)") round-trip without being mis-tokenized
        // by the CLI's space/comma splitter.
        foreach ($allowedTools as $tool) {
            $argv[] = '--allowedTools';
            $argv[] = $tool;
        }

        if ($model !== null) {
            // Defensive: model names are well-formed identifiers; reject anything
            // that could be interpreted as a flag or shell metacharacter even
            // though array-form Process bypasses the shell.
            if (! preg_match('/^[A-Za-z0-9._:-]+$/', $model)) {
                throw new RuntimeException("claude-code: invalid model name '{$model}'");
            }
            $argv[] = '--model';
            $argv[] = $model;
        }

        if ($maxBudgetUsd !== null) {
            $argv[] = '--max-budget-usd';
            // sprintf forces fixed-point; (string) cast of small floats like
            // 1.0e-5 would emit "1.0E-5", which the CLI may not accept. The
            // %f conversion is locale-aware in general but only on the
            // float-to-string emission of '.' vs ',' — sprintf runs in the
            // PHP process locale, so callers under LC_NUMERIC=de_DE could
            // still get a comma. Pin to C locale-equivalent via number_format
            // (locale-independent when separators are explicit).
            $argv[] = number_format($maxBudgetUsd, 6, '.', '');
        }

        // `--` terminates flag parsing so a prompt starting with '-' is not
        // misread as a CLI flag.
        $argv[] = '--';
        $argv[] = $prompt;

        $process = $this->buildProcess($argv, $cwd, $timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput()) ?: '(no stderr)';
            $exit = $process->getExitCode();
            throw new RuntimeException(
                "claude-code: process exited with status {$exit}: {$stderr}"
            );
        }

        $stdout = (string) $process->getOutput();

        if (trim($stdout) === '') {
            throw new RuntimeException('claude-code: process produced no stdout');
        }

        $envelope = json_decode($stdout, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($envelope)) {
            throw new RuntimeException(
                'claude-code: failed to parse JSON envelope: '.json_last_error_msg()
            );
        }

        if (! array_key_exists('result', $envelope)) {
            throw new RuntimeException('claude-code: envelope missing required envelope key: result');
        }

        if (! array_key_exists('total_cost_usd', $envelope)) {
            throw new RuntimeException('claude-code: envelope missing required envelope key: total_cost_usd');
        }

        return [
            'result' => $envelope['result'],
            'total_cost_usd' => (float) $envelope['total_cost_usd'],
            'raw' => $envelope,
        ];
    }

    /**
     * Build a Process (or test stub) for the given argv.
     *
     * @param  array<int, string>  $argv
     */
    private function buildProcess(array $argv, string $cwd, int $timeoutSeconds): object
    {
        if ($this->processFactory !== null) {
            return ($this->processFactory)($argv, $cwd, $timeoutSeconds);
        }

        $process = new Process($argv, $cwd);
        $process->setTimeout($timeoutSeconds);

        return $process;
    }
}

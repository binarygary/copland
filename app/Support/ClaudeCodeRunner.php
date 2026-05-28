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

        if ($allowedTools !== []) {
            $argv[] = '--allowedTools';
            $argv[] = implode(' ', $allowedTools);
        }

        if ($model !== null) {
            $argv[] = '--model';
            $argv[] = $model;
        }

        if ($maxBudgetUsd !== null) {
            $argv[] = '--max-budget-usd';
            $argv[] = (string) $maxBudgetUsd;
        }

        // Prompt MUST be the last positional arg.
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

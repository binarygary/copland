<?php

namespace Tests\Unit;

use App\Data\ModelUsage;
use App\Support\ClaudeCodeRunner;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests for ClaudeCodeRunner — Symfony Process wrapper for `claude -p` headless mode.
 *
 * No real `claude` binary is invoked. A Process-factory closure is injected so
 * tests capture the argv and provide canned stdout/stderr/exit-code.
 */
class ClaudeCodeRunnerTest extends TestCase
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Build an anonymous-class Process stub that mimics the subset of the
     * Symfony Process API that ClaudeCodeRunner consumes.
     */
    private function makeProcessStub(string $stdout = '', string $stderr = '', int $exitCode = 0): object
    {
        return new class($stdout, $stderr, $exitCode)
        {
            public function __construct(
                private string $stdout,
                private string $stderr,
                private int $exitCode,
            ) {}

            public function run(): int
            {
                return $this->exitCode;
            }

            public function isSuccessful(): bool
            {
                return $this->exitCode === 0;
            }

            public function getOutput(): string
            {
                return $this->stdout;
            }

            public function getErrorOutput(): string
            {
                return $this->stderr;
            }

            public function getExitCode(): ?int
            {
                return $this->exitCode;
            }
        };
    }

    /**
     * Build a process-factory closure that captures the argv/cwd/timeout
     * passed by ClaudeCodeRunner and returns the supplied stub.
     */
    private function captureFactory(array &$captured, object $stub): \Closure
    {
        return function (array $argv, string $cwd, int $timeout) use (&$captured, $stub): object {
            $captured['argv'] = $argv;
            $captured['cwd'] = $cwd;
            $captured['timeout'] = $timeout;

            return $stub;
        };
    }

    // ─── Test 1: argv construction (full happy path) ───────────────────────────

    public function test_run_stage_builds_expected_argv_with_all_options_set(): void
    {
        $captured = [];
        $stub = $this->makeProcessStub(
            stdout: json_encode([
                'result' => ['decision' => 'act', 'selected_task_id' => 7],
                'total_cost_usd' => 0.0123,
                'session_id' => 'x',
            ]),
        );
        $factory = $this->captureFactory($captured, $stub);

        $runner = new ClaudeCodeRunner('claude', $factory);

        $runner->runStage(
            prompt: 'do the thing',
            jsonSchema: '{"type":"object"}',
            allowedTools: ['Read', 'Edit', 'Bash(git status)'],
            cwd: '/tmp/workspace',
            model: 'sonnet',
            maxBudgetUsd: 0.50,
            timeoutSeconds: 300,
        );

        $argv = $captured['argv'];

        // Required fixed flags appear in order
        $this->assertSame('claude', $argv[0]);
        $this->assertSame('-p', $argv[1]);
        $this->assertSame('--output-format', $argv[2]);
        $this->assertSame('json', $argv[3]);
        $this->assertSame('--no-session-persistence', $argv[4]);
        $this->assertSame('--add-dir', $argv[5]);
        $this->assertSame('/tmp/workspace', $argv[6]);
        $this->assertSame('--json-schema', $argv[7]);
        $this->assertSame('{"type":"object"}', $argv[8]);

        // Each tool gets its own --allowedTools flag so patterns containing
        // spaces (e.g. "Bash(git status)") survive the CLI parser intact.
        $allowedToolsCount = count(array_keys($argv, '--allowedTools', true));
        $this->assertSame(3, $allowedToolsCount);
        $this->assertContains('Read', $argv);
        $this->assertContains('Edit', $argv);
        $this->assertContains('Bash(git status)', $argv);

        $this->assertContains('--model', $argv);
        $this->assertContains('sonnet', $argv);
        $this->assertContains('--max-budget-usd', $argv);
        $this->assertContains('0.5', $argv);

        // Prompt is preceded by `--` and is the final positional arg.
        $last = count($argv) - 1;
        $this->assertSame('do the thing', $argv[$last]);
        $this->assertSame('--', $argv[$last - 1]);

        // Factory received cwd + timeout
        $this->assertSame('/tmp/workspace', $captured['cwd']);
        $this->assertSame(300, $captured['timeout']);
    }

    // ─── Test 2: envelope parsing ─────────────────────────────────────────────

    public function test_run_stage_parses_json_envelope_and_returns_result_cost_raw(): void
    {
        $envelope = [
            'result' => ['decision' => 'act', 'selected_task_id' => 7],
            'total_cost_usd' => 0.0123,
            'session_id' => 'x',
        ];
        $stub = $this->makeProcessStub(stdout: json_encode($envelope));
        $captured = [];

        $runner = new ClaudeCodeRunner('claude', $this->captureFactory($captured, $stub));

        $result = $runner->runStage(
            prompt: 'p',
            jsonSchema: '{}',
            allowedTools: [],
            cwd: '/tmp',
        );

        $this->assertSame(['decision' => 'act', 'selected_task_id' => 7], $result['result']);
        $this->assertSame(0.0123, $result['total_cost_usd']);
        $this->assertSame($envelope, $result['raw']);
    }

    // ─── Test 3: non-zero exit → RuntimeException with stderr ─────────────────

    public function test_run_stage_throws_runtime_exception_on_non_zero_exit_with_stderr_in_message(): void
    {
        $stub = $this->makeProcessStub(stdout: '', stderr: 'auth required', exitCode: 1);
        $captured = [];

        $runner = new ClaudeCodeRunner('claude', $this->captureFactory($captured, $stub));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/auth required/');

        $runner->runStage(
            prompt: 'p',
            jsonSchema: '{}',
            allowedTools: [],
            cwd: '/tmp',
        );
    }

    // ─── Test 4: unparseable stdout → RuntimeException ────────────────────────

    public function test_run_stage_throws_when_stdout_is_not_valid_json(): void
    {
        $stub = $this->makeProcessStub(stdout: 'not json at all', exitCode: 0);
        $captured = [];

        $runner = new ClaudeCodeRunner('claude', $this->captureFactory($captured, $stub));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed to parse JSON envelope/');

        $runner->runStage(
            prompt: 'p',
            jsonSchema: '{}',
            allowedTools: [],
            cwd: '/tmp',
        );
    }

    // ─── Test 5: missing required envelope keys → RuntimeException ───────────

    public function test_run_stage_throws_when_envelope_missing_required_keys(): void
    {
        // Missing both result and total_cost_usd
        $stub = $this->makeProcessStub(stdout: json_encode(['session_id' => 'x']), exitCode: 0);
        $captured = [];

        $runner = new ClaudeCodeRunner('claude', $this->captureFactory($captured, $stub));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required envelope key/i');

        $runner->runStage(
            prompt: 'p',
            jsonSchema: '{}',
            allowedTools: [],
            cwd: '/tmp',
        );
    }

    // ─── Test 6: empty allowedTools → flag omitted ────────────────────────────

    public function test_run_stage_omits_allowed_tools_flag_when_array_is_empty(): void
    {
        $stub = $this->makeProcessStub(
            stdout: json_encode(['result' => 'ok', 'total_cost_usd' => 0.01]),
        );
        $captured = [];

        $runner = new ClaudeCodeRunner('claude', $this->captureFactory($captured, $stub));

        $runner->runStage(
            prompt: 'p',
            jsonSchema: '{}',
            allowedTools: [],
            cwd: '/tmp',
        );

        $this->assertNotContains('--allowedTools', $captured['argv']);
    }

    // ─── Test 7: null model / null maxBudgetUsd → flags omitted ───────────────

    public function test_run_stage_omits_model_and_budget_flags_when_null(): void
    {
        $stub = $this->makeProcessStub(
            stdout: json_encode(['result' => 'ok', 'total_cost_usd' => 0.01]),
        );
        $captured = [];

        $runner = new ClaudeCodeRunner('claude', $this->captureFactory($captured, $stub));

        $runner->runStage(
            prompt: 'p',
            jsonSchema: '{}',
            allowedTools: [],
            cwd: '/tmp',
            model: null,
            maxBudgetUsd: null,
        );

        $this->assertNotContains('--model', $captured['argv']);
        $this->assertNotContains('--max-budget-usd', $captured['argv']);
    }

    // ─── Task 2 piggyback: ModelUsage::fromProviderCost ───────────────────────
    // Lives here for now because ClaudeCodeRunner is the only producer of providerCostUsd.

    public function test_model_usage_from_provider_cost_zeros_tokens_and_carries_cost(): void
    {
        $usage = ModelUsage::fromProviderCost('claude-code/sonnet', 0.0123);

        $this->assertSame('claude-code/sonnet', $usage->model);
        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertSame(0, $usage->cacheWriteTokens);
        $this->assertSame(0, $usage->cacheReadTokens);
        $this->assertSame(0.0123, $usage->estimatedCostUsd);
    }
}

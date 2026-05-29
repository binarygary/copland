<?php

namespace Tests\Unit;

use App\Support\CodexRunner;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests for CodexRunner — Symfony Process wrapper for `codex exec` headless mode.
 *
 * No real `codex` binary is invoked. A Process-factory closure is injected so
 * tests capture the argv/stdin and emulate codex writing its final message to
 * the --output-last-message file, plus canned JSONL stdout for usage.
 */
class CodexRunnerTest extends TestCase
{
    private function makeProcessStub(string $stdout, string $stderr, int $exitCode): object
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
     * Factory that captures argv/cwd/timeout/stdin and, on success, emulates
     * codex writing $finalMessage to the `-o` file found in argv.
     */
    private function captureFactory(array &$captured, string $finalMessage, string $stdout, int $exitCode = 0, string $stderr = ''): \Closure
    {
        return function (array $argv, string $cwd, int $timeout, string $input) use (&$captured, $finalMessage, $stdout, $exitCode, $stderr): object {
            $captured['argv'] = $argv;
            $captured['cwd'] = $cwd;
            $captured['timeout'] = $timeout;
            $captured['input'] = $input;

            if ($exitCode === 0 && $finalMessage !== '') {
                $i = array_search('-o', $argv, true);
                if ($i !== false && isset($argv[$i + 1])) {
                    file_put_contents($argv[$i + 1], $finalMessage);
                }
            }

            return $this->makeProcessStub($stdout, $stderr, $exitCode);
        };
    }

    public function test_run_stage_builds_expected_argv_and_pipes_prompt_via_stdin(): void
    {
        $captured = [];
        $factory = $this->captureFactory($captured, '{"summary":"ok"}', '');

        (new CodexRunner('codex', $factory))->runStage(
            prompt: 'do the thing',
            jsonSchema: '{"type":"object"}',
            sandbox: 'workspace-write',
            cwd: '/tmp/workspace',
            model: 'gpt-5-codex',
            timeoutSeconds: 300,
        );

        $argv = $captured['argv'];
        $this->assertSame('codex', $argv[0]);
        $this->assertSame('exec', $argv[1]);
        $this->assertContains('--json', $argv);
        $this->assertContains('--ephemeral', $argv);
        $this->assertContains('--skip-git-repo-check', $argv);

        // Sandbox + working dir
        $s = array_search('-s', $argv, true);
        $this->assertSame('workspace-write', $argv[$s + 1]);
        $c = array_search('-C', $argv, true);
        $this->assertSame('/tmp/workspace', $argv[$c + 1]);

        // Schema + output files are real temp paths
        $sc = array_search('--output-schema', $argv, true);
        $this->assertNotFalse($sc);
        $this->assertNotEmpty($argv[$sc + 1]);
        $this->assertContains('-o', $argv);

        // Model forwarded via -m
        $m = array_search('-m', $argv, true);
        $this->assertSame('gpt-5-codex', $argv[$m + 1]);

        // Prompt is piped on stdin, not in argv.
        $this->assertSame('do the thing', $captured['input']);
        $this->assertNotContains('do the thing', $argv);
        $this->assertSame('/tmp/workspace', $captured['cwd']);
        $this->assertSame(300, $captured['timeout']);
    }

    public function test_run_stage_reads_structured_output_and_parses_usage(): void
    {
        $stdout = implode("\n", [
            '{"type":"thread.started","thread_id":"t1"}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"{\"summary\":\"did it\"}"}}',
            '{"type":"turn.completed","usage":{"input_tokens":1200,"cached_input_tokens":300,"output_tokens":45}}',
        ]);
        $captured = [];
        $runner = new CodexRunner('codex', $this->captureFactory($captured, '{"summary":"did it"}', $stdout));

        $result = $runner->runStage(
            prompt: 'p',
            jsonSchema: '{}',
            sandbox: 'read-only',
            cwd: '/tmp',
        );

        $this->assertSame(['summary' => 'did it'], $result['structured_output']);
        $this->assertSame(1200, $result['usage']['input_tokens']);
        $this->assertSame(45, $result['usage']['output_tokens']);
        $this->assertSame(300, $result['usage']['cached_input_tokens']);
    }

    public function test_run_stage_throws_on_non_zero_exit_with_stderr(): void
    {
        $captured = [];
        $runner = new CodexRunner('codex', $this->captureFactory($captured, '', '', 1, 'not logged in'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not logged in/');

        $runner->runStage(prompt: 'p', jsonSchema: '{}', sandbox: 'read-only', cwd: '/tmp');
    }

    public function test_run_stage_surfaces_stream_error_over_stderr_noise(): void
    {
        // The real failure (invalid_json_schema) is a turn.failed event on
        // stdout; stderr carries only unrelated MCP/deprecation noise.
        $stdout = implode("\n", [
            '{"type":"turn.started"}',
            '{"type":"turn.failed","error":{"message":"invalid_json_schema: additionalProperties required"}}',
        ]);
        $captured = [];
        $runner = new CodexRunner('codex', $this->captureFactory($captured, '', $stdout, 1, 'rmcp Asana MCP auth noise'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_json_schema/');

        $runner->runStage(prompt: 'p', jsonSchema: '{}', sandbox: 'read-only', cwd: '/tmp');
    }

    public function test_run_stage_throws_when_no_final_message_written(): void
    {
        // exit 0 but no -o file content (finalMessage '')
        $captured = [];
        $runner = new CodexRunner('codex', $this->captureFactory($captured, '', '{"type":"turn.completed"}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no final message/');

        $runner->runStage(prompt: 'p', jsonSchema: '{}', sandbox: 'read-only', cwd: '/tmp');
    }

    public function test_run_stage_omits_model_flag_when_null(): void
    {
        $captured = [];
        $runner = new CodexRunner('codex', $this->captureFactory($captured, '{"summary":"x"}', ''));

        $runner->runStage(prompt: 'p', jsonSchema: '{}', sandbox: 'read-only', cwd: '/tmp', model: null);

        $this->assertNotContains('-m', $captured['argv']);
    }

    public function test_run_stage_rejects_invalid_model_name(): void
    {
        $captured = [];
        $runner = new CodexRunner('codex', $this->captureFactory($captured, '{"summary":"x"}', ''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid model name/');

        $runner->runStage(prompt: 'p', jsonSchema: '{}', sandbox: 'read-only', cwd: '/tmp', model: 'foo; rm -rf /');
    }
}

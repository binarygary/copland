<?php

namespace App\Services;

use Anthropic\Client;
use App\Config\GlobalConfig;
use App\Data\ExecutionResult;
use App\Data\PlanResult;
use App\Exceptions\PolicyViolationException;
use App\Support\AnthropicCostEstimator;
use App\Support\AnthropicMessageSerializer;
use App\Support\ExecutorPolicy;
use App\Support\ExecutorProgressFormatter;
use App\Support\ExecutorRunState;
use App\Support\FileMutationHelper;
use App\Support\RunProgressSnapshot;
use Symfony\Component\Process\Process;
use Throwable;

class ClaudeExecutorService
{
    private Client $client;

    private string $model;

    public function __construct(private GlobalConfig $config)
    {
        $this->client = new Client(
            apiKey: $this->config->claudeApiKey(),
        );
        $this->model = $this->config->executorModel();
    }

    public function execute(string $workspacePath, PlanResult $plan, ?callable $progressCallback = null): ExecutionResult
    {
        return $this->executeWithPolicy($workspacePath, $plan, new ExecutorPolicy, $progressCallback);
    }

    public function executeWithRepoProfile(string $workspacePath, PlanResult $plan, array $repoProfile, ?callable $progressCallback = null, ?RunProgressSnapshot $snapshot = null): ExecutionResult
    {
        $policy = new ExecutorPolicy(
            blockedPaths: $repoProfile['blocked_paths'] ?? [],
            maxRounds: (int) ($repoProfile['max_executor_rounds'] ?? 12),
        );

        return $this->executeWithPolicy($workspacePath, $plan, $policy, $progressCallback, $snapshot);
    }

    private function executeWithPolicy(string $workspacePath, PlanResult $plan, ExecutorPolicy $policy, ?callable $progressCallback = null, ?RunProgressSnapshot $snapshot = null): ExecutionResult
    {
        $systemPrompt = file_get_contents(base_path('resources/prompts/executor.md'));
        $contractMessage = json_encode([
            'branch_name' => $plan->branchName,
            'files_to_read' => $plan->filesToRead,
            'files_to_change' => $plan->filesToChange,
            'steps' => $plan->steps,
            'commands_to_run' => $plan->commandsToRun,
            'tests_to_update' => $plan->testsToUpdate,
            'success_criteria' => $plan->successCriteria,
            'guardrails' => $plan->guardrails,
        ], JSON_PRETTY_PRINT);

        $tools = $this->buildTools();
        $messages = [['role' => 'user', 'content' => $contractMessage]];
        $toolCallLog = [];
        $runState = new ExecutorRunState($plan->filesToRead);
        $startTime = microtime(true);
        $round = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        while (true) {
            $round++;

            if ($round > $policy->maxRounds()) {
                return new ExecutionResult(
                    success: false,
                    summary: "Executor stopped after {$policy->maxRounds()} rounds without reaching completion",
                    toolCallLog: $toolCallLog,
                    toolCallCount: count($toolCallLog),
                    durationSeconds: microtime(true) - $startTime,
                    usage: AnthropicCostEstimator::forModel($this->model, $totalInputTokens, $totalOutputTokens),
                );
            }

            $this->report(
                $progressCallback,
                ExecutorProgressFormatter::waiting($round, count($toolCallLog), microtime(true) - $startTime)
            );

            $response = $this->client->messages->create(
                model: $this->model,
                maxTokens: 4096,
                system: $systemPrompt,
                tools: $tools,
                messages: $messages,
            );

            if (isset($response->usage)) {
                $totalInputTokens += $response->usage->inputTokens;
                $totalOutputTokens += $response->usage->outputTokens;
                $this->updateSnapshot($snapshot, $startTime, $totalInputTokens, $totalOutputTokens);
            }

            $toolUses = 0;
            foreach ($response->content as $block) {
                if ($block->type === 'tool_use') {
                    $toolUses++;
                }
            }

            $this->report(
                $progressCallback,
                ExecutorProgressFormatter::response($round, $toolUses, microtime(true) - $startTime)
            );

            $messages[] = [
                'role' => 'assistant',
                'content' => AnthropicMessageSerializer::assistantContent($response->content),
            ];

            if ($response->stopReason === 'end_turn') {
                $finalText = '';
                foreach ($response->content as $block) {
                    if ($block->type === 'text') {
                        $finalText .= $block->text;
                    }
                }

                return new ExecutionResult(
                    success: true,
                    summary: $finalText,
                    toolCallLog: $toolCallLog,
                    toolCallCount: count($toolCallLog),
                    durationSeconds: microtime(true) - $startTime,
                    usage: AnthropicCostEstimator::forModel($this->model, $totalInputTokens, $totalOutputTokens),
                );
            }

            $toolResults = [];
            foreach ($response->content as $block) {
                if ($block->type !== 'tool_use') {
                    continue;
                }

                $isError = false;

                try {
                    $outcome = $this->dispatchTool(
                        $block->name,
                        (array) $block->input,
                        $workspacePath,
                        $plan,
                        $policy,
                        $runState
                    );
                } catch (Throwable $e) {
                    $isError = true;
                    $outcome = $this->formatToolError($e);
                    $runState->recordFailedTool($block->name, $outcome);
                }

                $toolCallLog[] = [
                    'tool' => $block->name,
                    'input' => (array) $block->input,
                    'outcome' => substr($outcome, 0, 200),
                    'is_error' => $isError,
                ];

                $this->report(
                    $progressCallback,
                    ExecutorProgressFormatter::tool(
                        count($toolCallLog),
                        $block->name,
                        $this->toolTarget((array) $block->input)
                    )
                );

                if ($isError) {
                    $this->report(
                        $progressCallback,
                        ExecutorProgressFormatter::toolError(count($toolCallLog), $outcome)
                    );
                }

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block->id,
                    'content' => $outcome,
                    'is_error' => $isError,
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];

            $thrashReason = $runState->shouldAbortForThrashing($round);
            if ($thrashReason !== null) {
                return new ExecutionResult(
                    success: false,
                    summary: $thrashReason,
                    toolCallLog: $toolCallLog,
                    toolCallCount: count($toolCallLog),
                    durationSeconds: microtime(true) - $startTime,
                    usage: AnthropicCostEstimator::forModel($this->model, $totalInputTokens, $totalOutputTokens),
                );
            }
        }
    }

    private function updateSnapshot(?RunProgressSnapshot $snapshot, float $startTime, int $totalInputTokens, int $totalOutputTokens): void
    {
        if ($snapshot === null) {
            return;
        }

        $snapshot->executorUsage = AnthropicCostEstimator::forModel($this->model, $totalInputTokens, $totalOutputTokens);
        $snapshot->executorDurationSeconds = microtime(true) - $startTime;
    }

    private function report(?callable $progressCallback, string $message): void
    {
        if ($progressCallback !== null) {
            $progressCallback($message);
        }
    }

    private function toolTarget(array $input): string
    {
        foreach (['path', 'command', 'content'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                return $key === 'content'
                    ? substr($input[$key], 0, 40)
                    : $input[$key];
            }
        }

        return '...';
    }

    private function dispatchTool(string $name, array $input, string $workspacePath, PlanResult $plan, ExecutorPolicy $policy, ExecutorRunState $runState): string
    {
        $outcome = match ($name) {
            'read_file' => $this->readFile($workspacePath, $this->requireString($input, 'path', $name), $policy),
            'write_file' => $this->writeFile(
                $workspacePath,
                $this->requireString($input, 'path', $name),
                $this->requireString($input, 'content', $name),
                $plan,
                $policy
            ),
            'replace_in_file' => $this->replaceInFile(
                $workspacePath,
                $this->requireString($input, 'path', $name),
                $this->requireString($input, 'old', $name),
                $this->requireString($input, 'new', $name),
                $plan,
                $policy
            ),
            'run_command' => $this->runCommand($workspacePath, $this->requireString($input, 'command', $name), $plan, $policy),
            'list_directory' => $this->listDirectory($workspacePath, $this->requireString($input, 'path', $name), $policy, $runState),
            default => "Unknown tool: {$name}",
        };

        $runState->recordSuccessfulTool($name, $input);

        return $outcome;
    }

    private function requireString(array $input, string $key, string $tool): string
    {
        if (! isset($input[$key]) || ! is_string($input[$key]) || $input[$key] === '') {
            throw new PolicyViolationException("Tool '{$tool}' requires a non-empty string '{$key}' field");
        }

        return $input[$key];
    }

    private function formatToolError(Throwable $e): string
    {
        if ($e instanceof PolicyViolationException) {
            return 'Policy violation: '.$e->getMessage();
        }

        return 'Tool execution error: '.$e->getMessage();
    }

    private function readFile(string $workspacePath, string $path, ExecutorPolicy $policy): string
    {
        $normalizedPath = $policy->assertToolPathAllowed($path, 'read_file');
        $fullPath = $workspacePath.'/'.ltrim($normalizedPath, '/');
        if (! file_exists($fullPath)) {
            return "Error: file not found: {$normalizedPath}";
        }

        return file_get_contents($fullPath);
    }

    private function writeFile(string $workspacePath, string $path, string $content, PlanResult $plan, ExecutorPolicy $policy): string
    {
        $normalizedPath = $policy->assertWritePathAllowed($path, $plan->filesToChange);

        // Check against blocked paths (from plan guardrails)
        foreach ($plan->guardrails as $guardrail) {
            if (str_contains(strtolower($guardrail), 'block') && str_contains($guardrail, $normalizedPath)) {
                throw new PolicyViolationException("Write to '{$normalizedPath}' blocked by guardrail: {$guardrail}");
            }
        }

        $fullPath = $workspacePath.'/'.ltrim($normalizedPath, '/');
        $dir = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return "Written: {$normalizedPath}";
    }

    private function replaceInFile(string $workspacePath, string $path, string $old, string $new, PlanResult $plan, ExecutorPolicy $policy): string
    {
        $normalizedPath = $policy->assertWritePathAllowed($path, $plan->filesToChange);
        $fullPath = $workspacePath.'/'.ltrim($normalizedPath, '/');

        if (! file_exists($fullPath)) {
            return "Error: file not found: {$normalizedPath}";
        }

        $existingContent = file_get_contents($fullPath);
        $updatedContent = FileMutationHelper::replaceOnce($existingContent, $old, $new);

        file_put_contents($fullPath, $updatedContent);

        return "Updated: {$normalizedPath}";
    }

    private function runCommand(string $workspacePath, string $command, PlanResult $plan, ExecutorPolicy $policy): string
    {
        $normalizedCommand = $policy->assertCommandAllowed($command, $plan->commandsToRun);

        $process = Process::fromShellCommandline($normalizedCommand, $workspacePath);
        $process->setTimeout(120);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        return $process->isSuccessful()
            ? "Exit 0:\n{$output}"
            : "Exit {$process->getExitCode()}:\n{$output}";
    }

    private function listDirectory(string $workspacePath, string $path, ExecutorPolicy $policy, ExecutorRunState $runState): string
    {
        if (! $runState->canListDirectory()) {
            return 'Error: read planner-provided files first: '.implode(', ', $runState->pendingPlannedReads());
        }

        $normalizedPath = $policy->assertToolPathAllowed($path, 'list_directory');
        $fullPath = $workspacePath.'/'.ltrim($normalizedPath, '/');
        if (! is_dir($fullPath)) {
            return "Error: directory not found: {$normalizedPath}";
        }
        $files = array_diff(scandir($fullPath), ['.', '..']);
        $files = $policy->visibleEntries($normalizedPath, array_values($files));

        return implode("\n", array_values($files));
    }

    private function buildTools(): array
    {
        return [
            [
                'name' => 'read_file',
                'description' => 'Read a file in the workspace',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the file']],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => 'write_file',
                'description' => 'Write or overwrite a file in the workspace',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Relative path to the file'],
                        'content' => ['type' => 'string', 'description' => 'Complete replacement file content', 'minLength' => 1],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
            [
                'name' => 'replace_in_file',
                'description' => 'Replace one exact text block in an existing file',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Relative path to the existing file'],
                        'old' => ['type' => 'string', 'description' => 'Exact existing text to replace', 'minLength' => 1],
                        'new' => ['type' => 'string', 'description' => 'Replacement text'],
                    ],
                    'required' => ['path', 'old', 'new'],
                ],
            ],
            [
                'name' => 'run_command',
                'description' => 'Run a shell command in the workspace directory',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['command' => ['type' => 'string', 'description' => 'The command to run']],
                    'required' => ['command'],
                ],
            ],
            [
                'name' => 'list_directory',
                'description' => 'List files in a directory',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the directory']],
                    'required' => ['path'],
                ],
            ],
        ];
    }
}

<?php

namespace App\Services;

use App\Config\GlobalConfig;
use App\Contracts\LlmClient;
use App\Data\PlanResult;
use App\Exceptions\PolicyViolationException;
use App\Support\AnthropicCostEstimator;
use App\Support\ExecutorPolicy;
use App\Support\IssueFileHintExtractor;
use App\Support\PlanFieldNormalizer;
use RuntimeException;
use Throwable;

class ClaudePlannerService
{
    private string $model;

    public function __construct(
        private GlobalConfig $config,
        private LlmClient $apiClient,
    ) {
        $this->model = $this->config->plannerModel();
    }

    public function planTask(array $repoProfile, array $issue): PlanResult
    {
        $promptTemplate = file_get_contents(base_path('resources/prompts/planner.md'));
        $issueFileHints = IssueFileHintExtractor::extract($issue);

        $prompt = str_replace(
            [
                '{{issue}}',
                '{{issue_file_hints}}',
                '{{repo_summary}}',
                '{{conventions}}',
                '{{allowed_commands}}',
                '{{blocked_paths}}',
            ],
            [
                json_encode($issue, JSON_PRETTY_PRINT),
                $issueFileHints === [] ? 'None' : implode(', ', $issueFileHints),
                $repoProfile['repo_summary'] ?? '',
                $repoProfile['conventions'] ?? '',
                implode(', ', $repoProfile['allowed_commands'] ?? []),
                implode(', ', $repoProfile['blocked_paths'] ?? []),
            ],
            $promptTemplate
        );

        $workspacePath = $repoProfile['workspace_path'] ?? getcwd();
        $maxRounds = (int) ($repoProfile['max_planner_rounds'] ?? 6);
        $policy = new ExecutorPolicy(
            blockedPaths: $repoProfile['blocked_paths'] ?? [],
            maxRounds: $maxRounds,
            readFileMaxLines: (int) ($repoProfile['read_file_max_lines'] ?? 300),
        );

        $tools = $this->buildTools();
        $messages = [['role' => 'user', 'content' => $prompt]];

        $round = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCacheWriteTokens = 0;
        $totalCacheReadTokens = 0;

        while (true) {
            $round++;

            if ($round > $maxRounds) {
                throw new RuntimeException(
                    "Planner exceeded max rounds ({$maxRounds}) without producing a final plan"
                );
            }

            $response = $this->apiClient->complete(
                model: $this->model,
                maxTokens: 4096,
                messages: $messages,
                tools: $tools,
            );

            $totalInputTokens += $response->usage->inputTokens;
            $totalOutputTokens += $response->usage->outputTokens;
            $totalCacheWriteTokens += $response->usage->cacheWriteTokens;
            $totalCacheReadTokens += $response->usage->cacheReadTokens;

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
            ];

            if ($response->stopReason === 'stop') {
                $text = '';
                foreach ($response->content as $block) {
                    if (($block['type'] ?? null) === 'text') {
                        $text .= $block['text'] ?? '';
                    }
                }

                $json = $this->extractJson($text);

                if (! isset($json['decision'])) {
                    throw new RuntimeException("Planner response missing 'decision' field");
                }

                $usage = AnthropicCostEstimator::forModel(
                    $this->model,
                    $totalInputTokens,
                    $totalOutputTokens,
                    $totalCacheWriteTokens,
                    $totalCacheReadTokens,
                );

                $changes = is_array($json['changes'] ?? null) ? $json['changes'] : [];

                return new PlanResult(
                    decision: $json['decision'],
                    branchName: $json['branch_name'] ?? null,
                    filesToRead: PlanFieldNormalizer::list($json['files_to_read'] ?? []),
                    filesToChange: PlanFieldNormalizer::list($json['files_to_change'] ?? []),
                    blockedWritePaths: PlanFieldNormalizer::list($json['blocked_write_paths'] ?? []),
                    steps: PlanFieldNormalizer::list($json['steps'] ?? []),
                    commandsToRun: PlanFieldNormalizer::list($json['commands_to_run'] ?? []),
                    testsToUpdate: PlanFieldNormalizer::list($json['tests_to_update'] ?? []),
                    successCriteria: PlanFieldNormalizer::list($json['success_criteria'] ?? []),
                    guardrails: PlanFieldNormalizer::list($json['guardrails'] ?? []),
                    prTitle: $json['pr_title'] ?? null,
                    prBody: $json['pr_body'] ?? null,
                    maxFilesChanged: $json['max_files_changed'] ?? 3,
                    maxLinesChanged: $json['max_lines_changed'] ?? 250,
                    declineReason: $json['decline_reason'] ?? null,
                    changes: $changes,
                    usage: $usage,
                );
            }

            $toolResults = [];
            foreach ($response->content as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                $isError = false;
                try {
                    $outcome = $this->dispatchTool(
                        $block['name'] ?? '',
                        (array) ($block['input'] ?? []),
                        $workspacePath,
                        $policy,
                    );
                } catch (Throwable $e) {
                    $isError = true;
                    $outcome = $this->formatToolError($e);
                }

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'] ?? '',
                    'content' => $outcome,
                    'is_error' => $isError,
                ];
            }

            if ($toolResults === []) {
                // No tool calls and no stop — bail to avoid an infinite loop.
                throw new RuntimeException(
                    'Planner returned a non-stop response with no tool calls (round '.$round.')'
                );
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }
    }

    private function dispatchTool(string $name, array $input, string $workspacePath, ExecutorPolicy $policy): string
    {
        if ($name !== 'read_file') {
            throw new PolicyViolationException("Planner cannot use tool '{$name}' (read_file only)");
        }

        $path = $input['path'] ?? null;
        if (! is_string($path) || $path === '') {
            throw new PolicyViolationException("Tool 'read_file' requires a non-empty string 'path' field");
        }

        return $this->readFile($workspacePath, $path, $policy);
    }

    private function readFile(string $workspacePath, string $path, ExecutorPolicy $policy): string
    {
        $normalizedPath = $policy->assertToolPathAllowed($path, 'read_file');
        $fullPath = rtrim($workspacePath, '/').'/'.ltrim($normalizedPath, '/');

        if (! file_exists($fullPath)) {
            return "Error: file not found: {$normalizedPath}";
        }

        $content = (string) file_get_contents($fullPath);
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $maxLines = $policy->readFileMaxLines();

        if (count($lines) <= $maxLines) {
            return $content;
        }

        $visibleLines = array_slice($lines, 0, $maxLines);
        $omittedLines = count($lines) - $maxLines;

        return implode("\n", $visibleLines)."\n[truncated after {$maxLines} lines; {$omittedLines} more lines omitted]";
    }

    private function formatToolError(Throwable $e): string
    {
        if ($e instanceof PolicyViolationException) {
            return 'Policy violation: '.$e->getMessage();
        }

        return 'Tool execution error: '.$e->getMessage();
    }

    private function buildTools(): array
    {
        return [
            [
                'name' => 'read_file',
                'description' => 'Read a file in the workspace before emitting exact-text diffs',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the file']],
                    'required' => ['path'],
                ],
            ],
        ];
    }

    private function extractJson(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/^```\s*$/m', '', $text);

        $data = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Planner returned invalid JSON: '.json_last_error_msg());
        }

        return $data;
    }
}

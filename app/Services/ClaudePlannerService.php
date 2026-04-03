<?php

namespace App\Services;

use App\Config\GlobalConfig;
use App\Data\ModelUsage;
use App\Data\PlanResult;
use App\Support\AnthropicApiClient;
use App\Support\AnthropicCostEstimator;
use App\Support\IssueFileHintExtractor;
use App\Support\PlanFieldNormalizer;
use RuntimeException;

class ClaudePlannerService
{
    private string $model;

    public function __construct(
        private GlobalConfig $config,
        private AnthropicApiClient $apiClient,
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

        $response = $this->apiClient->messages(
            model: $this->model,
            maxTokens: 2048,
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
        );

        $text = $response->content[0]->text ?? '';
        $json = $this->extractJson($text);

        if (! isset($json['decision'])) {
            throw new RuntimeException("Planner response missing 'decision' field");
        }

        $usage = $this->usageFromResponse($response);

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
            usage: $usage,
        );
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

    private function usageFromResponse(object $response): ?ModelUsage
    {
        if (! isset($response->usage)) {
            return null;
        }

        return AnthropicCostEstimator::forModel(
            $this->model,
            $response->usage->inputTokens,
            $response->usage->outputTokens,
            $response->usage->cacheCreationInputTokens ?? 0,
            $response->usage->cacheReadInputTokens ?? 0,
        );
    }
}

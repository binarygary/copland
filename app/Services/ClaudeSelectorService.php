<?php

namespace App\Services;

use Anthropic\Client;
use App\Config\GlobalConfig;
use App\Data\ModelUsage;
use App\Data\SelectionResult;
use App\Support\AnthropicCostEstimator;
use RuntimeException;

class ClaudeSelectorService
{
    private Client $client;

    private string $model;

    public function __construct(private GlobalConfig $config)
    {
        $this->client = new Client(
            apiKey: $this->config->claudeApiKey(),
        );
        $this->model = $this->config->selectorModel();
    }

    public function selectTask(array $repoProfile, array $issues): SelectionResult
    {
        $promptTemplate = file_get_contents(base_path('resources/prompts/selector.md'));

        $issuesSummary = array_map(fn ($i) => [
            'number' => $i['number'],
            'title' => $i['title'],
            'body' => substr($i['body'] ?? '', 0, 500),
            'labels' => array_map(fn ($l) => $l['name'], $i['labels'] ?? []),
        ], $issues);

        $prompt = str_replace(
            ['{{issues}}', '{{repo_summary}}'],
            [json_encode($issuesSummary, JSON_PRETTY_PRINT), $repoProfile['repo_summary'] ?? ''],
            $promptTemplate
        );

        $response = $this->client->messages->create(
            model: $this->model,
            maxTokens: 1024,
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
        );

        $text = $response->content[0]->text ?? '';
        $json = $this->extractJson($text);

        if (! isset($json['decision'])) {
            throw new RuntimeException("Selector response missing 'decision' field");
        }

        $usage = $this->usageFromResponse($response);

        return new SelectionResult(
            decision: $json['decision'],
            selectedIssueNumber: $json['selected_issue_number'] ?? null,
            reason: $json['reason'] ?? '',
            rejections: $json['rejections'] ?? [],
            usage: $usage,
        );
    }

    private function extractJson(string $text): array
    {
        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/^```\s*$/m', '', $text);

        $data = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Selector returned invalid JSON: '.json_last_error_msg());
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
        );
    }
}

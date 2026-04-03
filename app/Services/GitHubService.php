<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Symfony\Component\Process\Process;

class GitHubService
{
    public function __construct(
        private ?Client $http = null,
        private $tokenResolver = null,
    ) {
        $this->http ??= new Client([
            'base_uri' => 'https://api.github.com',
        ]);
    }

    private function token(): string
    {
        if ($this->tokenResolver !== null) {
            return ($this->tokenResolver)();
        }

        $process = new Process(['gh', 'auth', 'token']);
        $process->run();

        if (! $process->isSuccessful() || trim($process->getOutput()) === '') {
            throw new RuntimeException('GitHub auth failed. Run: gh auth login');
        }

        return trim($process->getOutput());
    }

    public function ping(): bool
    {
        $this->token();

        return true;
    }

    public function getIssues(string $repo, array $labels): array
    {
        return $this->requestJson('GET', "/repos/{$repo}/issues", [
            'query' => [
                'labels' => implode(',', $labels),
                'state' => 'open',
                'per_page' => 50,
            ],
        ]);
    }

    public function hasOpenLinkedPr(string $repo, int $issueNumber): bool
    {
        try {
            $events = $this->requestJson('GET', "/repos/{$repo}/issues/{$issueNumber}/timeline", [
                'headers' => [
                    'Accept' => 'application/vnd.github.mockingbird-preview+json',
                ],
            ]);
        } catch (RuntimeException) {
            return false;
        }

        foreach ($events as $event) {
            if (
                ($event['event'] ?? '') === 'cross-referenced' &&
                ($event['source']['type'] ?? '') === 'issue' &&
                ($event['source']['issue']['pull_request'] ?? null) !== null &&
                ($event['source']['issue']['state'] ?? '') === 'open'
            ) {
                return true;
            }
        }

        return false;
    }

    public function commentOnIssue(string $repo, int $issueNumber, string $body): void
    {
        $this->requestJson('POST', "/repos/{$repo}/issues/{$issueNumber}/comments", [
            'json' => ['body' => $body],
        ]);
    }

    public function addLabel(string $repo, int $issueNumber, string $label): void
    {
        $this->requestJson('POST', "/repos/{$repo}/issues/{$issueNumber}/labels", [
            'json' => ['labels' => [$label]],
        ]);
    }

    public function removeLabel(string $repo, int $issueNumber, string $label): void
    {
        try {
            $this->requestJson('DELETE', "/repos/{$repo}/issues/{$issueNumber}/labels/{$label}");
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), ' 404 ')) {
                return;
            }

            throw $e;
        }
    }

    public function createDraftPr(string $repo, string $branch, string $title, string $body): array
    {
        return $this->requestJson('POST', "/repos/{$repo}/pulls", [
            'json' => [
                'title' => $title,
                'head' => $branch,
                'base' => 'main',
                'body' => $body,
                'draft' => true,
            ],
        ]);
    }

    private function requestJson(string $method, string $uri, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->token(),
            'Accept' => 'application/vnd.github+json',
            ...($options['headers'] ?? []),
        ];

        try {
            $response = $this->http->request($method, $uri, $options);
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
                ? $e->getResponse()->getStatusCode()
                : 'request failed';
            $body = method_exists($e, 'getResponse') && $e->getResponse() !== null
                ? (string) $e->getResponse()->getBody()
                : $e->getMessage();

            throw new RuntimeException("GitHub API error: {$status} {$body}", previous: $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

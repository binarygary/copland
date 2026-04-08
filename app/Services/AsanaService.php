<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Asana REST API client for task operations.
 *
 * Handles task fetching with client-side filtering, story (comment) creation,
 * and tag removal. Follows the GitHubService constructor pattern with
 * injectable ?Client for testability.
 */
class AsanaService
{
    private Client $http;

    public function __construct(
        private string $token,
        private string $projectGid,
        private array $filters = [],
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://app.asana.com/api/1.0/',
        ]);
    }

    /**
     * Fetch open tasks from the configured project and apply client-side filters.
     * Returns an array in the selector-compatible format used by ClaudeSelectorService.
     */
    public function getOpenTasks(): array
    {
        $response = $this->requestJson('GET', "projects/{$this->projectGid}/tasks", [
            'query' => [
                'completed_since' => 'now',
                'opt_fields'      => 'gid,name,notes,tags,memberships.section.name,completed',
                'limit'           => 100,
            ],
        ]);

        $tasks = $response['data'] ?? [];
        $filtered = $this->applyFilters($tasks);

        return array_map(fn (array $task) => [
            'number' => $task['gid'],
            'title'  => $task['name'],
            'body'   => $task['notes'] ?? '',
            'labels' => array_map(fn ($t) => ['name' => $t['name']], $task['tags'] ?? []),
        ], $filtered);
    }

    /**
     * Post a comment (story) to an Asana task.
     */
    public function addStory(string $taskGid, string $text): void
    {
        $this->requestJson('POST', "tasks/{$taskGid}/stories", [
            'json' => ['data' => ['text' => $text]],
        ]);
    }

    /**
     * Remove a tag from an Asana task by tag name.
     * No-op if the tag is not currently on the task.
     *
     * Fetches the task's current tags to resolve the tag GID — the Asana
     * removeTag endpoint requires a GID, not a name.
     */
    public function removeTag(string $taskGid, string $tagName): void
    {
        $response = $this->requestJson('GET', "tasks/{$taskGid}", [
            'query' => ['opt_fields' => 'tags'],
        ]);

        $tags = $response['data']['tags'] ?? [];
        $tagGid = null;

        foreach ($tags as $tag) {
            if (($tag['name'] ?? '') === $tagName) {
                $tagGid = $tag['gid'];
                break;
            }
        }

        if ($tagGid === null) {
            // Tag not present on task — treat as no-op
            return;
        }

        $this->requestJson('POST', "tasks/{$taskGid}/removeTag", [
            'json' => ['data' => ['tag' => $tagGid]],
        ]);
    }

    /**
     * Apply client-side tag and section filters (D-04, D-05).
     * AND logic: task must satisfy ALL configured filter conditions.
     *
     * Section filter checks project GID membership to avoid false matches
     * when a task belongs to multiple projects.
     */
    private function applyFilters(array $tasks): array
    {
        $requiredTags = $this->filters['tags'] ?? [];
        $requiredSection = $this->filters['section'] ?? null;

        if (empty($requiredTags) && $requiredSection === null) {
            return $tasks;
        }

        return array_values(array_filter($tasks, function (array $task) use ($requiredTags, $requiredSection): bool {
            // Tag filter: task must have ALL required tags
            if (! empty($requiredTags)) {
                $taskTagNames = array_map(fn ($t) => $t['name'] ?? '', $task['tags'] ?? []);
                foreach ($requiredTags as $tag) {
                    if (! in_array($tag, $taskTagNames, true)) {
                        return false;
                    }
                }
            }

            // Section filter: any membership in THIS project with matching section name
            if ($requiredSection !== null) {
                $found = false;
                foreach ($task['memberships'] ?? [] as $membership) {
                    if (
                        ($membership['project']['gid'] ?? '') === $this->projectGid &&
                        ($membership['section']['name'] ?? '') === $requiredSection
                    ) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function requestJson(string $method, string $uri, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->token,
            'Accept'        => 'application/json',
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

            throw new RuntimeException("Asana API error: {$status} {$body}", previous: $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

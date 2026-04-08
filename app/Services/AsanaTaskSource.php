<?php

namespace App\Services;

use App\Contracts\TaskSource;

/**
 * TaskSource implementation for Asana projects.
 *
 * Fetches tasks and handles comments via AsanaService.
 * PR creation always goes through GitHubService — PRs are GitHub-native.
 *
 * Per D-07: AsanaTaskSource holds both AsanaService (Asana operations)
 * and GitHubService (PR creation only).
 */
final class AsanaTaskSource implements TaskSource
{
    public function __construct(
        private AsanaService $asana,
        private GitHubService $github,
    ) {}

    /**
     * Fetch open Asana tasks. The $repo and $tags parameters from the TaskSource
     * interface are ignored — AsanaService uses project GID and filters
     * configured at construction time.
     *
     * @param string[] $tags (ignored for Asana; filters configured in AsanaService)
     */
    public function fetchTasks(string $repo, array $tags): array
    {
        return $this->asana->getOpenTasks();
    }

    public function addComment(string $repo, string|int $taskId, string $body): void
    {
        $this->asana->addStory((string) $taskId, $body);
    }

    public function openDraftPr(string $repo, string $branch, string $title, string $body): array
    {
        return $this->github->createDraftPr($repo, $branch, $title, $body);
    }

    public function removeTag(string $repo, string|int $taskId, string $tag): void
    {
        $this->asana->removeTag((string) $taskId, $tag);
    }
}

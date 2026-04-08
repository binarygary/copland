<?php

namespace App\Services;

use App\Contracts\TaskSource;

final class GitHubTaskSource implements TaskSource
{
    public function __construct(private GitHubService $github) {}

    public function fetchTasks(string $repo, array $tags): array
    {
        return $this->github->getIssues($repo, $tags);
    }

    public function addComment(string $repo, string|int $taskId, string $body): void
    {
        $this->github->commentOnIssue($repo, (int) $taskId, $body);
    }

    public function openDraftPr(string $repo, string $branch, string $title, string $body): array
    {
        return $this->github->createDraftPr($repo, $branch, $title, $body);
    }

    public function removeTag(string $repo, string|int $taskId, string $tag): void
    {
        $this->github->removeLabel($repo, (int) $taskId, $tag);
    }
}

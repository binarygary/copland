<?php

namespace App\Contracts;

interface TaskSource
{
    /** @param string[] $tags */
    public function fetchTasks(string $repo, array $tags): array;

    public function addComment(string $repo, string|int $taskId, string $body): void;

    public function openDraftPr(string $repo, string $branch, string $title, string $body): array;

    public function removeTag(string $repo, string|int $taskId, string $tag): void;
}

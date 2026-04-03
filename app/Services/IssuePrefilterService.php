<?php

namespace App\Services;

use App\Config\RepoConfig;
use App\Data\PrefilterResult;

class IssuePrefilterService
{
    public function __construct(
        private RepoConfig $config,
        private ?GitHubService $github = null,
        private ?string $repo = null,
    ) {}

    public function filter(array $issues): PrefilterResult
    {
        $accepted = [];
        $rejected = [];

        foreach ($issues as $issue) {
            $reason = $this->rejectReason($issue);

            if ($reason !== null) {
                $rejected[] = ['issue' => $issue, 'reason' => $reason];
            } else {
                $accepted[] = $issue;
            }
        }

        return new PrefilterResult($accepted, $rejected);
    }

    private function rejectReason(array $issue): ?string
    {
        if (empty($issue['body'])) {
            return 'missing body';
        }

        $text = strtolower($issue['title'].' '.$issue['body']);

        foreach ($this->config->riskyKeywords() as $keyword) {
            if (str_contains($text, strtolower($keyword))) {
                return "contains risky keyword: {$keyword}";
            }
        }

        $issueLabels = array_map(fn ($l) => $l['name'], $issue['labels'] ?? []);

        foreach ($this->config->blockedLabels() as $blocked) {
            if (in_array($blocked, $issueLabels)) {
                return "has blocked label: {$blocked}";
            }
        }

        if ($this->github !== null && $this->repo !== null && $this->github->hasOpenLinkedPr($this->repo, $issue['number'])) {
            return 'has open linked PR';
        }

        return null;
    }
}

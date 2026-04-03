<?php

namespace App\Services;

use App\Config\RepoConfig;

class WorkspaceService
{
    public function __construct(
        private RepoConfig $config,
        private GitService $git,
    ) {}

    public function create(string $repoPath, string $branch, string $runUuid): string
    {
        $this->git->prepareExecutionBranch($repoPath, $this->config->baseBranch(), $branch);

        return $repoPath;
    }

    public function cleanup(string $repoPath, string $workspacePath): void
    {
        // Intentionally no-op when running directly in the current checkout.
    }
}

<?php

namespace App\Support;

use App\Data\PlanResult;
use RuntimeException;

class PlanArtifactStore
{
    public function save(string $repo, array $issue, PlanResult $plan, array $validationErrors = []): string
    {
        $directory = $this->directoryForRepo($repo);
        $this->ensureDirectoryExists($directory);

        $lastPlanPath = $directory.'/last-plan.json';
        $this->archivePreviousLastPlan($lastPlanPath, (int) ($issue['number'] ?? 0));

        $payload = [
            'saved_at' => date(DATE_ATOM),
            'repo' => $repo,
            'issue' => [
                'number' => $issue['number'] ?? null,
                'title' => $issue['title'] ?? null,
                'url' => $issue['html_url'] ?? null,
            ],
            'plan' => [
                'decision' => $plan->decision,
                'branch_name' => $plan->branchName,
                'files_to_read' => $plan->filesToRead,
                'files_to_change' => $plan->filesToChange,
                'blocked_write_paths' => $plan->blockedWritePaths,
                'steps' => $plan->steps,
                'commands_to_run' => $plan->commandsToRun,
                'tests_to_update' => $plan->testsToUpdate,
                'success_criteria' => $plan->successCriteria,
                'guardrails' => $plan->guardrails,
                'pr_title' => $plan->prTitle,
                'pr_body' => $plan->prBody,
                'max_files_changed' => $plan->maxFilesChanged,
                'max_lines_changed' => $plan->maxLinesChanged,
                'decline_reason' => $plan->declineReason,
            ],
            'validation_errors' => array_values($validationErrors),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode plan artifact as JSON.');
        }

        if (file_put_contents($lastPlanPath, $json.PHP_EOL) === false) {
            throw new RuntimeException("Failed to write plan artifact to {$lastPlanPath}");
        }

        return $lastPlanPath;
    }

    private function archivePreviousLastPlan(string $lastPlanPath, int $newIssueNumber): void
    {
        if (! file_exists($lastPlanPath)) {
            return;
        }

        $existing = json_decode((string) file_get_contents($lastPlanPath), true);
        $existingIssueNumber = (int) ($existing['issue']['number'] ?? 0);

        if ($existingIssueNumber <= 0 || $existingIssueNumber === $newIssueNumber) {
            return;
        }

        $archivePath = dirname($lastPlanPath)."/issue-{$existingIssueNumber}.json";

        if (file_exists($archivePath) && ! unlink($archivePath)) {
            throw new RuntimeException("Failed to replace archived plan artifact at {$archivePath}");
        }

        if (! rename($lastPlanPath, $archivePath)) {
            throw new RuntimeException("Failed to archive previous plan artifact to {$archivePath}");
        }
    }

    private function directoryForRepo(string $repo): string
    {
        return $this->homeDirectory().'/.copland/runs/'.str_replace('/', '__', $repo);
    }

    private function homeDirectory(): string
    {
        return HomeDirectory::resolve();
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create plan artifact directory at {$directory}");
        }
    }
}

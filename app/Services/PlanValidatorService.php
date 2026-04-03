<?php

namespace App\Services;

use App\Data\PlanResult;

class PlanValidatorService
{
    public function validate(PlanResult $plan, array $repoProfile): array
    {
        $errors = [];

        if (empty($plan->branchName)) {
            $errors[] = 'branch_name is missing or empty';
        }

        if (empty($plan->filesToChange)) {
            $errors[] = 'files_to_change is empty';
        }

        $maxFiles = $repoProfile['max_files_changed'] ?? 3;
        if ($plan->maxFilesChanged > $maxFiles) {
            $errors[] = "max_files_changed ({$plan->maxFilesChanged}) exceeds policy limit ({$maxFiles})";
        }

        $maxLines = $repoProfile['max_lines_changed'] ?? 250;
        if ($plan->maxLinesChanged > $maxLines) {
            $errors[] = "max_lines_changed ({$plan->maxLinesChanged}) exceeds policy limit ({$maxLines})";
        }

        $blockedPaths = $repoProfile['blocked_paths'] ?? [];
        foreach ($plan->filesToChange as $file) {
            foreach ($blockedPaths as $blocked) {
                if (str_starts_with($file, $blocked)) {
                    $errors[] = "file '{$file}' is in blocked path '{$blocked}'";
                }
            }
        }

        $allowedCommands = $repoProfile['allowed_commands'] ?? [];
        foreach ($plan->commandsToRun as $command) {
            $allowed = false;
            foreach ($allowedCommands as $prefix) {
                if (str_starts_with($command, $prefix)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                $errors[] = "command '{$command}' is not in allowed_commands";
            }
        }

        if (empty($plan->prTitle)) {
            $errors[] = 'pr_title is missing';
        }

        if (empty($plan->prBody)) {
            $errors[] = 'pr_body is missing';
        }

        return $errors;
    }
}

<?php

namespace App\Services;

use App\Data\ExecutionResult;
use App\Data\PlanResult;
use App\Data\VerificationResult;

class VerificationService
{
    public function __construct(private GitService $git) {}

    public function verify(
        array $repoProfile,
        string $workspacePath,
        PlanResult $plan,
        ExecutionResult $result
    ): VerificationResult {
        $failures = [];

        if (! $result->success) {
            $failures[] = 'Execution did not succeed: '.$result->summary;

            return new VerificationResult(false, $failures);
        }

        $changedFiles = $this->git->changedFiles($workspacePath);
        $fileCount = count($changedFiles);

        if ($fileCount > $plan->maxFilesChanged) {
            $failures[] = "Changed {$fileCount} files, but max is {$plan->maxFilesChanged}";
        }

        $lineCount = $this->git->changedLineCount($workspacePath);
        if ($lineCount > $plan->maxLinesChanged) {
            $failures[] = "Changed {$lineCount} lines, but max is {$plan->maxLinesChanged}";
        }

        $blockedPaths = $repoProfile['blocked_paths'] ?? [];
        foreach ($changedFiles as $file) {
            foreach ($blockedPaths as $blocked) {
                if (str_starts_with($file, $blocked)) {
                    $failures[] = "Changed file '{$file}' is in blocked path '{$blocked}'";
                }
            }
        }

        return new VerificationResult(empty($failures), $failures);
    }
}

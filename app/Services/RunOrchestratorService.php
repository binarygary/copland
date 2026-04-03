<?php

namespace App\Services;

use App\Data\RunResult;
use App\Support\PlanArtifactStore;
use App\Support\RunProgressSnapshot;

class RunOrchestratorService
{
    private array $log = [];

    private $progressCallback = null;

    public function __construct(
        private GitHubService $github,
        private IssuePrefilterService $prefilter,
        private ClaudeSelectorService $selector,
        private ClaudePlannerService $planner,
        private PlanValidatorService $validator,
        private WorkspaceService $workspace,
        private GitService $git,
        private ClaudeExecutorService $executor,
        private VerificationService $verifier,
    ) {}

    public function run(string $repo, array $repoProfile, ?callable $progressCallback = null, ?RunProgressSnapshot $snapshot = null): RunResult
    {
        $this->log = [];
        $this->progressCallback = $progressCallback;

        // Step 1: Fetch and prefilter issues
        $this->pushLog("[1/8] Fetching issues for {$repo}");
        $issues = $this->github->getIssues($repo, $repoProfile['required_labels'] ?? ['agent-ready']);
        $prefiltered = $this->prefilter->filter($issues);
        $this->pushLog('      '.count($prefiltered->accepted).' accepted, '.count($prefiltered->rejected).' rejected after prefilter');

        // Step 2: Claude select
        $this->pushLog('[2/8] Running Claude selector');
        $selection = $this->selector->selectTask($repoProfile, $prefiltered->accepted);
        if ($snapshot !== null) {
            $snapshot->selectorUsage = $selection->usage;
        }
        $this->pushLog("      Selector decision: {$selection->decision} — {$selection->reason}");

        if ($selection->decision === 'skip_all') {
            return new RunResult(
                status: 'skipped',
                prUrl: null,
                prNumber: null,
                selectedIssueTitle: null,
                selectedIssueNumber: null,
                failureReason: $selection->reason,
                log: $this->log,
                selectorUsage: $selection->usage,
            );
        }

        $selectedIssue = null;
        foreach ($prefiltered->accepted as $issue) {
            if ($issue['number'] === $selection->selectedIssueNumber) {
                $selectedIssue = $issue;
                break;
            }
        }

        if ($selectedIssue === null) {
            return new RunResult(
                status: 'failed',
                prUrl: null,
                prNumber: null,
                selectedIssueTitle: null,
                selectedIssueNumber: $selection->selectedIssueNumber,
                failureReason: "Selected issue #{$selection->selectedIssueNumber} not found",
                log: $this->log,
                selectorUsage: $selection->usage,
            );
        }

        $this->pushLog("      Selected issue #{$selectedIssue['number']}: {$selectedIssue['title']}");

        // Step 3: Claude plan
        $this->pushLog('[3/8] Running Claude planner');
        $plan = $this->planner->planTask($repoProfile, $selectedIssue);
        if ($snapshot !== null) {
            $snapshot->plannerUsage = $plan->usage;
        }
        $this->pushLog("      Planner decision: {$plan->decision}");

        if ($plan->decision === 'decline') {
            return new RunResult(
                status: 'skipped',
                prUrl: null,
                prNumber: null,
                selectedIssueTitle: $selectedIssue['title'],
                selectedIssueNumber: $selectedIssue['number'],
                failureReason: $plan->declineReason,
                log: $this->log,
                selectorUsage: $selection->usage,
                plannerUsage: $plan->usage,
            );
        }

        // Step 4: Validate plan
        $this->pushLog('[4/8] Validating plan');
        $validationErrors = $this->validator->validate($plan, $repoProfile);
        $artifactPath = (new PlanArtifactStore)->save($repo, $selectedIssue, $plan, $validationErrors);
        $this->pushLog("      Saved plan artifact to {$artifactPath}");

        if (! empty($validationErrors)) {
            $reason = implode('; ', $validationErrors);
            $this->pushLog("      Plan validation failed: {$reason}");

            return new RunResult(
                status: 'failed',
                prUrl: null,
                prNumber: null,
                selectedIssueTitle: $selectedIssue['title'],
                selectedIssueNumber: $selectedIssue['number'],
                failureReason: $reason,
                log: $this->log,
                selectorUsage: $selection->usage,
                plannerUsage: $plan->usage,
            );
        }

        $this->pushLog('      Plan validated OK');

        // Step 5: Create worktree
        $repoPath = $repoProfile['repo_path'] ?? getcwd();
        $workspacePath = null;

        try {
            $this->pushLog("[5/8] Syncing base branch and switching to {$plan->branchName}");
            $workspacePath = $this->workspace->create($repoPath, $plan->branchName, '');
            $this->pushLog("      Running directly in {$workspacePath}");

            // Step 6: Run executor
            $this->pushLog('[6/8] Running Claude executor');
            $executionResult = $this->executor->executeWithRepoProfile(
                $workspacePath,
                $plan,
                $repoProfile,
                fn (string $entry) => $this->pushLog($entry),
                $snapshot
            );
            $this->pushLog($executionResult->success
                ? "      Execution complete ({$executionResult->toolCallCount} tool calls in {$executionResult->durationSeconds}s)"
                : "      Execution failed: {$executionResult->summary}");

            // Step 7: Verify
            $this->pushLog('[7/8] Running verification');
            $verificationResult = $this->verifier->verify($repoProfile, $workspacePath, $plan, $executionResult);

            if (! $verificationResult->passed) {
                $reason = implode('; ', $verificationResult->failures);
                $this->pushLog("      Verification failed: {$reason}");

                // Step 8: Comment failure on issue
                $this->github->commentOnIssue(
                    $repo,
                    $selectedIssue['number'],
                    "❌ Agent run failed.\n\n**Reason:** {$reason}"
                );

                return new RunResult(
                    status: 'failed',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: $selectedIssue['title'],
                    selectedIssueNumber: $selectedIssue['number'],
                    failureReason: $reason,
                    log: $this->log,
                    selectorUsage: $selection->usage,
                    plannerUsage: $plan->usage,
                    executorUsage: $executionResult->usage,
                    executorDurationSeconds: $executionResult->durationSeconds,
                );
            }

            $this->pushLog('      Verification passed');

            // Step 10: Commit and push
            $this->pushLog('[8/8] Committing, pushing, and opening draft PR');
            $this->git->commit($workspacePath, "agent: implement #{$selectedIssue['number']} {$selectedIssue['title']}");
            $this->git->push($workspacePath, $plan->branchName);
            $this->pushLog("      Pushed branch {$plan->branchName}");

            // Step 11: Create draft PR
            $pr = $this->github->createDraftPr(
                $repo,
                $plan->branchName,
                $plan->prTitle,
                $plan->prBody
            );
            $prUrl = $pr['html_url'];
            $prNumber = $pr['number'];
            $this->pushLog("      Draft PR opened: {$prUrl}");

            foreach ($repoProfile['required_labels'] ?? ['agent-ready'] as $label) {
                $this->github->removeLabel($repo, $selectedIssue['number'], $label);
                $this->pushLog("      Removed issue label {$label}");
            }

            // Step 12: Comment success on issue
            $this->github->commentOnIssue(
                $repo,
                $selectedIssue['number'],
                "✅ Agent run complete.\n\n**PR:** {$prUrl}\n**Branch:** `{$plan->branchName}`\n\n{$executionResult->summary}"
            );

            return new RunResult(
                status: 'succeeded',
                prUrl: $prUrl,
                prNumber: $prNumber,
                selectedIssueTitle: $selectedIssue['title'],
                selectedIssueNumber: $selectedIssue['number'],
                failureReason: null,
                log: $this->log,
                selectorUsage: $selection->usage,
                plannerUsage: $plan->usage,
                executorUsage: $executionResult->usage,
                executorDurationSeconds: $executionResult->durationSeconds,
            );

        } finally {
            if ($workspacePath !== null) {
                try {
                    $this->workspace->cleanup($repoPath, $workspacePath);
                    $this->pushLog('      Run finished in current checkout');
                } catch (\Exception $e) {
                    $this->pushLog("      Warning: cleanup step failed: {$e->getMessage()}");
                }
            }
        }
    }

    private function pushLog(string $entry): void
    {
        $this->log[] = $entry;

        if ($this->progressCallback !== null) {
            ($this->progressCallback)($entry);
        }
    }
}

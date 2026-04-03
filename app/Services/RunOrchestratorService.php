<?php

namespace App\Services;

use App\Data\RunResult;
use App\Support\AnthropicCostEstimator;
use App\Support\PlanArtifactStore;
use App\Support\RunLogStore;
use App\Support\RunProgressSnapshot;
use Throwable;

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
        $startedAt = date(DATE_ATOM);
        $result = null;
        $selectedIssue = null;
        $runLogStore = new RunLogStore;
        $caught = null;

        if ($snapshot !== null) {
            $snapshot->repo = $repo;
        }

        try {
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
                $result = new RunResult(
                    status: 'skipped',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: null,
                    selectedIssueNumber: null,
                    failureReason: $selection->reason,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selection->usage,
                );

                return $result;
            }

            foreach ($prefiltered->accepted as $issue) {
                if ($issue['number'] === $selection->selectedIssueNumber) {
                    $selectedIssue = $issue;
                    break;
                }
            }

            if ($selectedIssue === null) {
                $result = new RunResult(
                    status: 'failed',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: null,
                    selectedIssueNumber: $selection->selectedIssueNumber,
                    failureReason: "Selected issue #{$selection->selectedIssueNumber} not found",
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selection->usage,
                );

                return $result;
            }

            if ($snapshot !== null) {
                $snapshot->selectedIssueTitle = $selectedIssue['title'];
                $snapshot->selectedIssueNumber = $selectedIssue['number'];
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
                $result = new RunResult(
                    status: 'skipped',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: $selectedIssue['title'],
                    selectedIssueNumber: $selectedIssue['number'],
                    failureReason: $plan->declineReason,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selection->usage,
                    plannerUsage: $plan->usage,
                );

                return $result;
            }

            // Step 4: Validate plan
            $this->pushLog('[4/8] Validating plan');
            $validationErrors = $this->validator->validate($plan, $repoProfile);
            $artifactPath = (new PlanArtifactStore)->save($repo, $selectedIssue, $plan, $validationErrors);
            $this->pushLog("      Saved plan artifact to {$artifactPath}");

            if (! empty($validationErrors)) {
                $reason = implode('; ', $validationErrors);
                $this->pushLog("      Plan validation failed: {$reason}");

                $result = new RunResult(
                    status: 'failed',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: $selectedIssue['title'],
                    selectedIssueNumber: $selectedIssue['number'],
                    failureReason: $reason,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selection->usage,
                    plannerUsage: $plan->usage,
                );

                return $result;
            }

            $this->pushLog('      Plan validated OK');

            // Step 5: Create worktree
            $repoPath = $repoProfile['repo_path'] ?? getcwd();
            $workspacePath = null;

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

                $result = new RunResult(
                    status: 'failed',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: $selectedIssue['title'],
                    selectedIssueNumber: $selectedIssue['number'],
                    failureReason: $reason,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selection->usage,
                    plannerUsage: $plan->usage,
                    executorUsage: $executionResult->usage,
                    executorDurationSeconds: $executionResult->durationSeconds,
                );

                return $result;
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

            $result = new RunResult(
                status: 'succeeded',
                prUrl: $prUrl,
                prNumber: $prNumber,
                selectedIssueTitle: $selectedIssue['title'],
                selectedIssueNumber: $selectedIssue['number'],
                failureReason: null,
                log: $this->log,
                startedAt: $startedAt,
                finishedAt: date(DATE_ATOM),
                selectorUsage: $selection->usage,
                plannerUsage: $plan->usage,
                executorUsage: $executionResult->usage,
                executorDurationSeconds: $executionResult->durationSeconds,
            );

            return $result;

        } catch (Throwable $e) {
            $this->pushLog("      Run crashed: {$e->getMessage()}");
            $caught = $e;

            throw $e;
        } finally {
            if (isset($workspacePath) && $workspacePath !== null) {
                try {
                    $this->workspace->cleanup($repoPath, $workspacePath);
                    $this->pushLog('      Run finished in current checkout');
                } catch (\Exception $e) {
                    $this->pushLog("      Warning: cleanup step failed: {$e->getMessage()}");
                }
            }

            try {
                $payload = $result instanceof RunResult
                    ? $this->payloadFromResult($repo, $result)
                    : $this->partialPayload($repo, $selectedIssue, $snapshot, $startedAt, $caught);

                $path = $runLogStore->append($payload);
                $this->pushLog("      Appended run log to {$path}");
            } catch (Throwable $e) {
                $this->pushLog("      Warning: run log write failed: {$e->getMessage()}");
            }
        }
    }

    private function payloadFromResult(string $repo, RunResult $result): array
    {
        return [
            'repo' => $repo,
            'issue' => [
                'number' => $result->selectedIssueNumber,
                'title' => $result->selectedIssueTitle,
            ],
            'status' => $result->status,
            'partial' => false,
            'started_at' => $result->startedAt,
            'finished_at' => $result->finishedAt,
            'failure_reason' => $result->failureReason,
            'pr' => [
                'number' => $result->prNumber,
                'url' => $result->prUrl,
            ],
            'decision_path' => $this->log,
            'usage' => [
                'selector' => $result->selectorUsage,
                'planner' => $result->plannerUsage,
                'executor' => $result->executorUsage,
                'total' => AnthropicCostEstimator::combine(
                    $result->selectorUsage,
                    $result->plannerUsage,
                    $result->executorUsage,
                ),
            ],
            'executor_duration_seconds' => $result->executorDurationSeconds,
        ];
    }

    private function partialPayload(string $repo, ?array $selectedIssue, ?RunProgressSnapshot $snapshot, string $startedAt, ?Throwable $caught): array
    {
        return [
            'repo' => $repo,
            'issue' => [
                'number' => $selectedIssue['number'] ?? $snapshot?->selectedIssueNumber,
                'title' => $selectedIssue['title'] ?? $snapshot?->selectedIssueTitle,
            ],
            'status' => 'crashed',
            'partial' => true,
            'started_at' => $startedAt,
            'finished_at' => date(DATE_ATOM),
            'failure_reason' => $caught?->getMessage(),
            'pr' => [
                'number' => null,
                'url' => null,
            ],
            'decision_path' => $this->log,
            'usage' => [
                'selector' => $snapshot?->selectorUsage,
                'planner' => $snapshot?->plannerUsage,
                'executor' => $snapshot?->executorUsage,
                'total' => AnthropicCostEstimator::combine(
                    $snapshot?->selectorUsage,
                    $snapshot?->plannerUsage,
                    $snapshot?->executorUsage,
                ),
            ],
            'executor_duration_seconds' => $snapshot?->executorDurationSeconds,
        ];
    }

    private function pushLog(string $entry): void
    {
        $this->log[] = $entry;

        if ($this->progressCallback !== null) {
            ($this->progressCallback)($entry);
        }
    }
}

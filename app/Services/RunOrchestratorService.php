<?php

namespace App\Services;

use App\Contracts\TaskSource;
use App\Data\ModelUsage;
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
        private TaskSource $taskSource,
        private IssuePrefilterService $prefilter,
        private ClaudeSelectorService $selector,
        private ClaudePlannerService $planner,
        private PlanValidatorService $validator,
        private WorkspaceService $workspace,
        private GitService $git,
        private ClaudeExecutorService $executor,
        private VerificationService $verifier,
        private ?PlanArtifactStore $planArtifactStore = null,
        private ?RunLogStore $runLogStore = null,
        private ?TaskDirectoryWriterService $taskWriter = null,
    ) {}

    /**
     * @param  ?string  $targetIssueNumber  When set, the Claude selector is bypassed and this
     *                                      specific issue is run, provided it survives the
     *                                      prefilter. Used by the "run now" action in the
     *                                      Godot console to act on a task the user picked.
     */
    public function run(string $repo, array $repoProfile, ?callable $progressCallback = null, ?RunProgressSnapshot $snapshot = null, ?string $targetIssueNumber = null): RunResult
    {
        $this->log = [];
        $this->progressCallback = $progressCallback;
        $startedAt = date(DATE_ATOM);
        $result = null;
        $selectedIssue = null;
        $selectorUsage = null;
        $runLogStore = $this->runLogStore ?? new RunLogStore;
        $caught = null;
        $repoPath = null;
        $writerRepoSlug = null;
        $runId = null;

        if ($snapshot !== null) {
            $snapshot->repo = $repo;
        }

        try {
            // Step 1: Fetch and prefilter issues
            $this->pushLog("[1/8] Fetching issues for {$repo}");
            $issues = $this->taskSource->fetchTasks($repo, $repoProfile['required_labels'] ?? ['agent-ready']);
            $prefiltered = $this->prefilter->filter($issues);
            $this->pushLog('      '.count($prefiltered->accepted).' accepted, '.count($prefiltered->rejected).' rejected after prefilter');

            // Step 2: pick the issue to work on — either a user-requested target
            // (selector bypassed) or Claude's choice across the candidates.
            if ($targetIssueNumber !== null) {
                $this->pushLog("[2/8] Targeted run — bypassing selector for requested task #{$targetIssueNumber}");

                foreach ($prefiltered->accepted as $issue) {
                    if ((string) $issue['number'] === (string) $targetIssueNumber) {
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
                        selectedTaskId: $targetIssueNumber,
                        failureReason: "Requested task #{$targetIssueNumber} is not an accepted candidate (prefiltered out, closed, or not in this repo)",
                        log: $this->log,
                        startedAt: $startedAt,
                        finishedAt: date(DATE_ATOM),
                        selectorUsage: null,
                    );

                    return $result;
                }
            } else {
                $this->pushLog('[2/8] Running Claude selector');
                $selection = $this->selector->selectTask($repoProfile, $prefiltered->accepted);
                $selectorUsage = $selection->usage;
                if ($snapshot !== null) {
                    $snapshot->selectorUsage = $selectorUsage;
                }
                $this->pushLog("      Selector decision: {$selection->decision} — {$selection->reason}");

                if ($selection->decision === 'skip_all') {
                    $result = new RunResult(
                        status: 'skipped',
                        prUrl: null,
                        prNumber: null,
                        selectedIssueTitle: null,
                        selectedTaskId: null,
                        failureReason: $selection->reason,
                        log: $this->log,
                        startedAt: $startedAt,
                        finishedAt: date(DATE_ATOM),
                        selectorUsage: $selectorUsage,
                    );

                    return $result;
                }

                foreach ($prefiltered->accepted as $issue) {
                    if ((string) $issue['number'] === (string) $selection->selectedTaskId) {
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
                        selectedTaskId: $selection->selectedTaskId,
                        failureReason: "Selected task #{$selection->selectedTaskId} not found",
                        log: $this->log,
                        startedAt: $startedAt,
                        finishedAt: date(DATE_ATOM),
                        selectorUsage: $selectorUsage,
                    );

                    return $result;
                }
            }

            if ($snapshot !== null) {
                $snapshot->selectedIssueTitle = $selectedIssue['title'];
                $snapshot->selectedTaskId = $selectedIssue['number'];
            }

            $this->pushLog("      Selected issue #{$selectedIssue['number']}: {$selectedIssue['title']}");

            // Per D-06: Asana sources use basename(repo_path) so the on-disk directory and
            // the frontmatter repo_slug agree (no GH-style slash, no __ collapse needed).
            $writerRepoSlug = $this->taskSource instanceof AsanaTaskSource
                ? basename($repoProfile['repo_path'])
                : $repo;

            // Per D-01/D-02: orchestrator-owned per-run id. ISO-8601 UTC with colons
            // replaced by dashes for POSIX-safe directory naming. Lexicographic sort
            // remains chronological. Generated exactly once per run() call.
            $runId = str_replace(':', '-', gmdate('Y-m-d\TH:i:s\Z'));

            $this->taskWriter?->writeNewTask($writerRepoSlug, $selectedIssue['number'], $selectedIssue['title'] ?? '', $selectedIssue['body'] ?? '', $repoProfile['repo_path'], $selectedIssue['html_url'] ?? '');
            $this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'new');
            $this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'new');
            $this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'selected');
            $this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'selected');

            // Step 3: Claude plan
            $this->pushLog('[3/8] Running Claude planner');
            $this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'planning');
            $this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'planning');
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
                    selectedTaskId: $selectedIssue['number'],
                    failureReason: $plan->declineReason,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selectorUsage,
                    plannerUsage: $plan->usage,
                );

                return $result;
            }

            // Step 4: Validate plan
            $this->pushLog('[4/8] Validating plan');
            $validationErrors = $this->validator->validate($plan, $repoProfile);
            $artifactPath = ($this->planArtifactStore ?? new PlanArtifactStore)->save($repo, $selectedIssue, $plan, $validationErrors);
            $this->pushLog("      Saved plan artifact to {$artifactPath}");

            if (! empty($validationErrors)) {
                $reason = implode('; ', $validationErrors);
                $this->pushLog("      Plan validation failed: {$reason}");

                $result = new RunResult(
                    status: 'failed',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: $selectedIssue['title'],
                    selectedTaskId: $selectedIssue['number'],
                    failureReason: $reason,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selectorUsage,
                    plannerUsage: $plan->usage,
                );

                return $result;
            }

            $this->pushLog('      Plan validated OK');
            $this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'planned');
            $this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'planned');

            // Step 5: Create worktree
            $repoPath = $repoProfile['repo_path'] ?? getcwd();
            $workspacePath = null;

            $this->pushLog("[5/8] Syncing base branch and switching to {$plan->branchName}");
            $workspacePath = $this->workspace->create($repoPath, $plan->branchName, '');
            $this->pushLog("      Running directly in {$workspacePath}");

            // Step 6: Run executor
            $this->pushLog('[6/8] Running Claude executor');
            $this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'executing');
            $this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'executing');
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

            if (! $executionResult->success) {
                $this->taskSource->addComment(
                    $repo,
                    $selectedIssue['number'],
                    "❌ Agent run failed.\n\n**Reason:** {$executionResult->summary}"
                );

                $result = new RunResult(
                    status: 'failed',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: $selectedIssue['title'],
                    selectedTaskId: $selectedIssue['number'],
                    failureReason: $executionResult->summary,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selectorUsage,
                    plannerUsage: $plan->usage,
                    executorUsage: $executionResult->usage,
                    executorDurationSeconds: $executionResult->durationSeconds,
                );

                return $result;
            }

            // Step 7: Verify
            $this->pushLog('[7/8] Running verification');
            $this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'verifying');
            $this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'verifying');
            $verificationResult = $this->verifier->verify($repoProfile, $workspacePath, $plan, $executionResult);

            if (! $verificationResult->passed) {
                $reason = implode('; ', $verificationResult->failures);
                $this->pushLog("      Verification failed: {$reason}");

                // Step 8: Comment failure on issue
                $this->taskSource->addComment(
                    $repo,
                    $selectedIssue['number'],
                    "❌ Agent run failed.\n\n**Reason:** {$reason}"
                );

                $result = new RunResult(
                    status: 'failed',
                    prUrl: null,
                    prNumber: null,
                    selectedIssueTitle: $selectedIssue['title'],
                    selectedTaskId: $selectedIssue['number'],
                    failureReason: $reason,
                    log: $this->log,
                    startedAt: $startedAt,
                    finishedAt: date(DATE_ATOM),
                    selectorUsage: $selectorUsage,
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
            $pr = $this->taskSource->openDraftPr(
                $repo,
                $plan->branchName,
                $plan->prTitle,
                $plan->prBody
            );
            $prUrl = $pr['html_url'];
            $prNumber = $pr['number'];
            $this->pushLog("      Draft PR opened: {$prUrl}");
            $this->taskWriter?->writeStatus($writerRepoSlug, $selectedIssue['number'], 'pr_open');
            $this->taskWriter?->writeRunStatus($writerRepoSlug, $selectedIssue['number'], $runId, 'pr_open');

            foreach ($repoProfile['required_labels'] ?? ['agent-ready'] as $label) {
                $this->taskSource->removeTag($repo, $selectedIssue['number'], $label);
                $this->pushLog("      Removed issue label {$label}");
            }

            // Step 12: Comment success on issue
            $this->taskSource->addComment(
                $repo,
                $selectedIssue['number'],
                "✅ Agent run complete.\n\n**PR:** {$prUrl}\n**Branch:** `{$plan->branchName}`\n\n{$executionResult->summary}"
            );

            $result = new RunResult(
                status: 'succeeded',
                prUrl: $prUrl,
                prNumber: $prNumber,
                selectedIssueTitle: $selectedIssue['title'],
                selectedTaskId: $selectedIssue['number'],
                failureReason: null,
                log: $this->log,
                startedAt: $startedAt,
                finishedAt: date(DATE_ATOM),
                selectorUsage: $selectorUsage,
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
            if (isset($workspacePath)) {
                try {
                    $this->workspace->cleanup($repoPath, $workspacePath);
                    $this->pushLog('      Run finished in current checkout');
                } catch (\Exception $e) {
                    $this->pushLog("      Warning: cleanup step failed: {$e->getMessage()}");
                }
            }

            if ($this->taskWriter !== null && $selectedIssue !== null && $writerRepoSlug !== null) {
                try {
                    $this->taskWriter->writeBlockedIfNotTerminal($writerRepoSlug, $selectedIssue['number']);
                } catch (Throwable $e) {
                    $this->pushLog("      Warning: blocked-state write failed: {$e->getMessage()}");
                }
            }

            // D-08: per-run sibling — same guard order plus $runId !== null. Own try/catch
            // so a per-run blocked-write failure never masks the original exception.
            if ($this->taskWriter !== null && $selectedIssue !== null && $runId !== null && $writerRepoSlug !== null) {
                try {
                    $this->taskWriter->writeRunBlockedIfNotTerminal($writerRepoSlug, $selectedIssue['number'], $runId);
                } catch (Throwable $e) {
                    $this->pushLog("      Warning: per-run blocked-state write failed: {$e->getMessage()}");
                }
            }

            $payload = null;
            try {
                $payload = $result instanceof RunResult
                    ? $this->payloadFromResult($repo, $result)
                    : $this->partialPayload($repo, $selectedIssue, $snapshot, $startedAt, $caught);

                $path = $runLogStore->append($payload);
                $this->pushLog("      Appended run log to {$path}");
            } catch (Throwable $e) {
                $this->pushLog("      Warning: run log write failed: {$e->getMessage()}");
            }

            // D-09/D-10/D-11: outcome.md write — same finally arm, sibling try/catch
            // so writer failure never masks JSONL failure and vice-versa. D-15: the
            // $runLogStore->append() call above is untouched.
            if ($this->taskWriter !== null && $selectedIssue !== null && $runId !== null && $writerRepoSlug !== null && $payload !== null) {
                try {
                    $outcome = $this->outcomePayload($runId, $result, $payload, $startedAt, $caught);
                    $this->taskWriter->writeRunOutcome($writerRepoSlug, $selectedIssue['number'], $runId, $outcome);
                } catch (Throwable $e) {
                    $this->pushLog("      Warning: outcome write failed: {$e->getMessage()}");
                }
            }
        }
    }

    private function payloadFromResult(string $repo, RunResult $result): array
    {
        return [
            'repo' => $repo,
            'issue' => [
                'number' => $result->selectedTaskId,
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
                'number' => $selectedIssue['number'] ?? $snapshot?->selectedTaskId,
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

    private function outcomePayload(string $runId, ?RunResult $result, array $payload, string $startedAt, ?Throwable $caught): array
    {
        // RESEARCH §Outcome.md Mapping Table: succeeded -> pr_open, failed/skipped -> blocked, crashed -> crashed
        $rawStatus = (string) ($payload['status'] ?? 'crashed');
        $status = match ($rawStatus) {
            'succeeded' => 'pr_open',
            'crashed' => 'crashed',
            default => 'blocked', // 'failed' | 'skipped' -> blocked
        };

        // Normalize DATE_ATOM -> Z-form to match the writer's gmdate('Y-m-d\TH:i:s\Z') convention.
        $startedAtZ = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) ($payload['started_at'] ?? $startedAt)));
        $finishedAtZ = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) ($payload['finished_at'] ?? date(DATE_ATOM))));

        $totalUsage = $payload['usage']['total'] ?? null;
        $totalCost = $totalUsage instanceof ModelUsage ? $totalUsage->estimatedCostUsd : 0.0;

        // renderFrontmatter coerces all values via (string) — but bool true casts to '1', not 'true',
        // so emit 'true' / 'false' literals here for readability in the YAML head.
        return [
            'run_id' => $runId,
            'status' => $status,
            'pr_number' => $payload['pr']['number'] ?? '',
            'pr_url' => (string) ($payload['pr']['url'] ?? ''),
            // number_format with explicit '.' decimal separator is locale-safe;
            // sprintf('%.6f', ...) would emit '0,123456' under LC_NUMERIC=de_DE.
            'cost_usd' => number_format($totalCost, 6, '.', ''),
            'started_at' => $startedAtZ,
            'finished_at' => $finishedAtZ,
            'failure_reason' => (string) ($payload['failure_reason'] ?? ''),
            'partial' => ! empty($payload['partial']) ? 'true' : 'false',
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

<?php

namespace App\Commands;

use App\Config\GlobalConfig;
use App\Config\RepoConfig;
use App\Services\ClaudePlannerService;
use App\Services\ClaudeSelectorService;
use App\Services\CurrentRepoGuardService;
use App\Services\GitHubService;
use App\Services\IssuePrefilterService;
use App\Services\PlanValidatorService;
use App\Support\AnthropicCostEstimator;
use App\Support\PlanArtifactStore;
use App\Support\ProgressReporter;
use LaravelZero\Framework\Commands\Command;

class PlanCommand extends Command
{
    protected $signature = 'plan {repo? : GitHub repo in owner/repo format}';

    protected $description = 'Run Claude selector and planner and display the contract';

    public function handle(): void
    {
        $progress = new ProgressReporter(totalSteps: 6);

        $this->line($progress->step('Resolve repository'));
        $repo = (new CurrentRepoGuardService())->resolve($this->argument('repo'));
        $this->line($progress->detail("Using repo {$repo}"));

        $this->line($progress->step('Load configuration'));
        $globalConfig = new GlobalConfig();
        $repoConfig = new RepoConfig(getcwd());

        $repoProfile = [
            'repo_summary' => $repoConfig->repoSummary(),
            'conventions' => $repoConfig->conventions(),
            'allowed_commands' => $repoConfig->allowedCommands(),
            'blocked_paths' => $repoConfig->blockedPaths(),
            'max_files_changed' => $globalConfig->defaultMaxFiles(),
            'max_lines_changed' => $globalConfig->defaultMaxLines(),
        ];

        $this->line($progress->step('Fetch and prefilter issues'));
        $github = new GitHubService();
        $issues = $github->getIssues($repo, $repoConfig->requiredLabels());

        $prefilter = new IssuePrefilterService($repoConfig, $github, $repo);
        $prefiltered = $prefilter->filter($issues);
        $this->line($progress->detail(count($prefiltered->accepted) . ' accepted, ' . count($prefiltered->rejected) . ' rejected'));

        $this->line($progress->step('Run Claude selector'));
        $selector = new ClaudeSelectorService($globalConfig);
        $selection = $selector->selectTask($repoProfile, $prefiltered->accepted);

        $this->line($progress->detail("Selection: {$selection->decision}"));
        $this->line($progress->detail("Reason: {$selection->reason}"));

        if ($selection->decision === 'skip_all') {
            $this->line('No suitable issue found. Exiting.');
            return;
        }

        $selectedIssue = null;
        foreach ($prefiltered->accepted as $issue) {
            if ($issue['number'] === $selection->selectedIssueNumber) {
                $selectedIssue = $issue;
                break;
            }
        }

        if ($selectedIssue === null) {
            $this->error("Selected issue #{$selection->selectedIssueNumber} not found in prefiltered list.");
            return;
        }

        $this->line($progress->detail("Selected issue #{$selectedIssue['number']}: {$selectedIssue['title']}"));
        $this->line($progress->step('Run Claude planner'));
        $planner = new ClaudePlannerService($globalConfig);
        $plan = $planner->planTask($repoProfile, $selectedIssue);

        $this->line('');
        $this->line("Plan decision: {$plan->decision}");

        if ($plan->decision === 'decline') {
            $this->line("Decline reason: {$plan->declineReason}");
            return;
        }

        $this->line("Branch: {$plan->branchName}");
        $this->line('');
        $this->line('Files to change:');
        foreach ($plan->filesToChange as $file) {
            $this->line("  - {$file}");
        }
        $this->line('');
        $this->line('Steps:');
        foreach ($plan->steps as $i => $step) {
            $this->line('  ' . ($i + 1) . '. ' . $step);
        }
        $this->line('');
        $this->line('Commands to run:');
        foreach ($plan->commandsToRun as $cmd) {
            $this->line("  - {$cmd}");
        }

        $this->line($progress->step('Validate plan'));
        $validator = new PlanValidatorService();
        $errors = $validator->validate($plan, $repoProfile);

        $artifactPath = (new PlanArtifactStore())->save($repo, $selectedIssue, $plan, $errors);

        if (! empty($errors)) {
            $this->line('');
            $this->error('Validation errors:');
            foreach ($errors as $err) {
                $this->line("  - {$err}");
            }
        } else {
            $this->line('');
            $this->line('Validation: OK');
        }

        $this->line($progress->detail("Saved plan artifact to {$artifactPath}"));

        $this->line('');
        $this->line('Usage:');
        $this->line('  - Selector: ' . AnthropicCostEstimator::format($selection->usage));
        $this->line('  - Planner: ' . AnthropicCostEstimator::format($plan->usage));
        $total = AnthropicCostEstimator::combine($selection->usage, $plan->usage);
        $this->line('  - Total: ' . AnthropicCostEstimator::format($total));
    }
}

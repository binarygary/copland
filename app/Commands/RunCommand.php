<?php

namespace App\Commands;

use Anthropic\Client;
use App\Config\GlobalConfig;
use App\Config\RepoConfig;
use App\Services\ClaudeExecutorService;
use App\Services\ClaudePlannerService;
use App\Services\ClaudeSelectorService;
use App\Services\CurrentRepoGuardService;
use App\Services\GitHubService;
use App\Services\GitService;
use App\Services\IssuePrefilterService;
use App\Services\PlanValidatorService;
use App\Services\RunOrchestratorService;
use App\Services\VerificationService;
use App\Services\WorkspaceService;
use App\Support\AnthropicApiClient;
use App\Support\AnthropicCostEstimator;
use App\Support\ProgressReporter;
use App\Support\RunProgressSnapshot;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;

class RunCommand extends Command implements SignalableCommandInterface
{
    protected $signature = 'run {repo? : GitHub repo in owner/repo format}';

    protected $description = 'Run the full overnight agent flow for a repo';

    private ?RunProgressSnapshot $snapshot = null;

    public function handle(): int
    {
        $progress = new ProgressReporter(totalSteps: 2);
        $this->snapshot = new RunProgressSnapshot;

        $this->line($progress->step('Resolve repository'));
        $repo = (new CurrentRepoGuardService)->resolve($this->argument('repo'));
        $this->line($progress->detail("Using repo {$repo}"));

        $this->line($progress->step('Load configuration and start run'));
        $globalConfig = new GlobalConfig;
        $apiClient = new AnthropicApiClient(
            client: new Client(apiKey: $globalConfig->claudeApiKey()),
            maxAttempts: $globalConfig->retryMaxAttempts(),
            baseDelaySeconds: $globalConfig->retryBaseDelaySeconds(),
        );
        $repoConfig = new RepoConfig(getcwd());
        $git = new GitService;

        $repoProfile = [
            'repo_summary' => $repoConfig->repoSummary(),
            'conventions' => $repoConfig->conventions(),
            'allowed_commands' => $repoConfig->allowedCommands(),
            'blocked_paths' => $repoConfig->blockedPaths(),
            'required_labels' => $repoConfig->requiredLabels(),
            'max_files_changed' => $globalConfig->defaultMaxFiles(),
            'max_lines_changed' => $globalConfig->defaultMaxLines(),
            'max_executor_rounds' => $repoConfig->maxExecutorRounds(),
            'read_file_max_lines' => $repoConfig->readFileMaxLines(),
            'repo_path' => getcwd(),
        ];

        $orchestrator = new RunOrchestratorService(
            github: new GitHubService,
            prefilter: new IssuePrefilterService($repoConfig, new GitHubService, $repo),
            selector: new ClaudeSelectorService($globalConfig, $apiClient),
            planner: new ClaudePlannerService($globalConfig, $apiClient),
            validator: new PlanValidatorService,
            workspace: new WorkspaceService($repoConfig, $git),
            git: $git,
            executor: new ClaudeExecutorService($globalConfig, $apiClient),
            verifier: new VerificationService($git),
        );

        $result = $orchestrator->run($repo, $repoProfile, fn (string $entry) => $this->line($entry), $this->snapshot);

        $this->line('');
        $this->renderUsage(
            $result->selectorUsage,
            $result->plannerUsage,
            $result->executorUsage,
            $result->executorDurationSeconds,
        );

        match ($result->status) {
            'succeeded' => $this->info("✅ Succeeded — PR: {$result->prUrl}"),
            'skipped' => $this->warn("⏭  Skipped — {$result->failureReason}"),
            'failed' => $this->error("❌ Failed — {$result->failureReason}"),
            default => $this->error("❌ Unknown status: {$result->status}"),
        };

        return $result->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') ? [SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int
    {
        if (! defined('SIGINT') || $signal !== SIGINT) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('Interrupted by user.');

        if ($this->snapshot !== null) {
            $this->renderUsage(
                $this->snapshot->selectorUsage,
                $this->snapshot->plannerUsage,
                $this->snapshot->executorUsage,
                $this->snapshot->executorDurationSeconds,
                'Partial usage:'
            );
        }

        return self::FAILURE;
    }

    private function renderUsage(
        mixed $selectorUsage,
        mixed $plannerUsage,
        mixed $executorUsage,
        ?float $executorDurationSeconds,
        string $heading = 'Usage:'
    ): void {
        $this->line($heading);
        $this->line('  - Selector: '.AnthropicCostEstimator::format($selectorUsage));
        $this->line('  - Planner: '.AnthropicCostEstimator::format($plannerUsage));
        $this->line('  - Executor: '.AnthropicCostEstimator::format($executorUsage));
        $total = AnthropicCostEstimator::combine(
            $selectorUsage,
            $plannerUsage,
            $executorUsage,
        );
        $this->line('  - Total: '.AnthropicCostEstimator::format($total));
        if ($executorDurationSeconds !== null) {
            $this->line('  - Executor elapsed: '.(int) round($executorDurationSeconds).'s');
        }
        $this->line('');
    }
}

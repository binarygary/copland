<?php

namespace App\Commands;

use Anthropic\Client;
use App\Config\GlobalConfig;
use App\Config\RepoConfig;
use App\Data\RunResult;
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
use App\Support\RunLogStore;
use App\Support\RunProgressSnapshot;
use LaravelZero\Framework\Commands\Command;
use Throwable;
use Symfony\Component\Console\Command\SignalableCommandInterface;

class RunCommand extends Command implements SignalableCommandInterface
{
    protected $signature = 'run {repo? : GitHub repo in owner/repo format}';

    protected $description = 'Run the full overnight agent flow for a repo';

    private ?RunProgressSnapshot $snapshot = null;

    public function __construct(
        private ?GlobalConfig $globalConfig = null,
        private ?RunLogStore $runLogStore = null,
        private $repoRunner = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $progress = new ProgressReporter(totalSteps: 2);
        $this->snapshot = new RunProgressSnapshot;
        $globalConfig = $this->globalConfig ?? new GlobalConfig;
        $requestedRepo = $this->argument('repo');
        $originalPath = getcwd() ?: '.';
        $repoRuns = [];

        $this->line($progress->step('Resolve repository targets'));

        if (is_string($requestedRepo) && trim($requestedRepo) !== '') {
            $repo = (new CurrentRepoGuardService)->resolve($requestedRepo);
            $repoRuns[] = ['slug' => $repo, 'path' => $originalPath];
            $this->line($progress->detail("Using repo {$repo}"));
        } else {
            $configuredRepos = $globalConfig->configuredRepos();

            if ($configuredRepos !== []) {
                $repoRuns = $configuredRepos;
                $this->line($progress->detail('Using configured repos from ~/.copland.yml'));
            } else {
                $repo = (new CurrentRepoGuardService)->resolve(null);
                $repoRuns[] = ['slug' => $repo, 'path' => $originalPath];
                $this->line($progress->detail("Using current checkout {$repo}"));
            }
        }

        $this->line($progress->step('Load configuration and start run'));

        $results = [];

        foreach ($repoRuns as $index => $repoRun) {
            $slug = $repoRun['slug'];
            $path = $repoRun['path'];

            $this->newLine();
            $this->line(sprintf('[%d/%d] %s (%s)', $index + 1, count($repoRuns), $slug, $path));

            try {
                $results[$slug] = $this->executeRepo($slug, $path, $globalConfig, $this->snapshot);
            } catch (Throwable $throwable) {
                $this->error("❌ Failed — {$throwable->getMessage()}");
                $results[$slug] = $this->failedResultFromException($throwable);
                $this->appendFailedRunLog($slug, $results[$slug]);
            }
        }

        $this->line('');
        $this->renderUsage(
            self::combineUsageForResults($results, 'selectorUsage'),
            self::combineUsageForResults($results, 'plannerUsage'),
            self::combineUsageForResults($results, 'executorUsage'),
            self::sumExecutorDuration($results),
            count($results) > 1 ? 'Total usage:' : 'Usage:'
        );

        if (count($results) > 1) {
            $this->line('Results:');

            foreach ($results as $repo => $result) {
                $summary = match ($result->status) {
                    'succeeded' => "Succeeded — PR: {$result->prUrl}",
                    'skipped' => "Skipped — {$result->failureReason}",
                    'failed' => "Failed — {$result->failureReason}",
                    default => "Unknown status: {$result->status}",
                };

                $this->line("  - {$repo}: {$summary}");
            }

            $this->line('');
        }

        return self::overallExitCode($results);
    }

    private function executeRepo(
        string $repo,
        string $path,
        GlobalConfig $globalConfig,
        RunProgressSnapshot $snapshot,
    ): RunResult {
        if ($this->repoRunner !== null) {
            return ($this->repoRunner)($repo, $path, $globalConfig, $snapshot);
        }

        return $this->runRepo($repo, $path, $globalConfig, $snapshot);
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
        foreach (self::usageSummaryLines(
            $selectorUsage,
            $plannerUsage,
            $executorUsage,
            $executorDurationSeconds,
            $heading,
        ) as $line) {
            $this->line($line);
        }
    }

    public static function usageSummaryLines(
        mixed $selectorUsage,
        mixed $plannerUsage,
        mixed $executorUsage,
        ?float $executorDurationSeconds,
        string $heading = 'Usage:'
    ): array {
        $lines = [$heading];
        $lines[] = '  - Selector: '.AnthropicCostEstimator::format($selectorUsage);
        $lines[] = '  - Planner: '.AnthropicCostEstimator::format($plannerUsage);
        $lines[] = '  - Executor: '.AnthropicCostEstimator::format($executorUsage);
        $lines[] = '  - Total: '.AnthropicCostEstimator::format(AnthropicCostEstimator::combine(
            $selectorUsage,
            $plannerUsage,
            $executorUsage,
        ));

        if ($executorDurationSeconds !== null) {
            $lines[] = '  - Executor elapsed: '.(int) round($executorDurationSeconds).'s';
        }

        $lines[] = '';

        return $lines;
    }

    private function runRepo(
        string $repo,
        string $path,
        GlobalConfig $globalConfig,
        RunProgressSnapshot $snapshot,
    ): RunResult {
        $originalPath = getcwd() ?: $path;

        if (! is_dir($path)) {
            throw new \RuntimeException("Configured path does not exist: {$path}");
        }

        if (! chdir($path)) {
            throw new \RuntimeException("Failed to switch to repo path: {$path}");
        }

        try {
            $apiClient = new AnthropicApiClient(
                client: new Client(apiKey: $globalConfig->claudeApiKey()),
                maxAttempts: $globalConfig->retryMaxAttempts(),
                baseDelaySeconds: $globalConfig->retryBaseDelaySeconds(),
            );
            $repoConfig = new RepoConfig($path);
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
                'repo_path' => $path,
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

            $result = $orchestrator->run($repo, $repoProfile, fn (string $entry) => $this->line($entry), $snapshot);

            match ($result->status) {
                'succeeded' => $this->info("✅ Succeeded — PR: {$result->prUrl}"),
                'skipped' => $this->warn("⏭  Skipped — {$result->failureReason}"),
                'failed' => $this->error("❌ Failed — {$result->failureReason}"),
                default => $this->error("❌ Unknown status: {$result->status}"),
            };

            return $result;
        } finally {
            chdir($originalPath);
        }
    }

    private function failedResultFromException(Throwable $throwable): RunResult
    {
        $timestamp = now()->toIso8601String();

        return new RunResult(
            status: 'failed',
            prUrl: null,
            prNumber: null,
            selectedIssueTitle: null,
            selectedIssueNumber: null,
            failureReason: $throwable->getMessage(),
            log: [],
            startedAt: $timestamp,
            finishedAt: $timestamp,
        );
    }

    private function appendFailedRunLog(string $repo, RunResult $result): void
    {
        try {
            $path = ($this->runLogStore ?? new RunLogStore)->append($this->runLogPayload($repo, $result));
            $this->line("      Appended run log to {$path}");
        } catch (Throwable $throwable) {
            $this->warn("      Warning: run log write failed: {$throwable->getMessage()}");
        }
    }

    private function runLogPayload(string $repo, RunResult $result): array
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
            'decision_path' => $result->log,
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

    private static function combineUsageForResults(array $results, string $property): mixed
    {
        $usage = null;

        foreach ($results as $result) {
            $usage = AnthropicCostEstimator::combine($usage, $result->{$property});
        }

        return $usage;
    }

    private static function sumExecutorDuration(array $results): ?float
    {
        $seconds = 0.0;
        $found = false;

        foreach ($results as $result) {
            if ($result->executorDurationSeconds === null) {
                continue;
            }

            $seconds += $result->executorDurationSeconds;
            $found = true;
        }

        return $found ? $seconds : null;
    }

    private static function overallExitCode(array $results): int
    {
        foreach ($results as $result) {
            if ($result->status === 'failed') {
                return self::FAILURE;
            }
        }

        foreach ($results as $result) {
            if ($result->status === 'succeeded') {
                return self::SUCCESS;
            }
        }

        return self::FAILURE;
    }
}

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
use App\Support\LlmClientFactory;
use App\Support\OpenAiCompatClient;
use App\Support\PlanArtifactStore;
use App\Support\ProgressReporter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class PlanCommand extends Command
{
    protected $signature = 'plan {repo? : GitHub repo in owner/repo format}';

    protected $description = 'Run the configured selector + planner and display the contract';

    public function handle(): void
    {
        $progress = new ProgressReporter(totalSteps: 6);

        $this->line($progress->step('Resolve repository'));
        $repo = (new CurrentRepoGuardService)->resolve($this->argument('repo'));
        $this->line($progress->detail("Using repo {$repo}"));

        $this->line($progress->step('Load configuration'));
        $globalConfig = new GlobalConfig;
        $repoConfig = new RepoConfig(getcwd());

        // Per-stage LLM client wiring matches RunCommand so `plan` honors the
        // configured provider (codex / claude-code / ollama / anthropic). Without
        // this, the command always 401'd under a no-Anthropic-key setup.
        $selectorClient = LlmClientFactory::forStage('selector', $globalConfig, $repoConfig);
        $plannerClient = LlmClientFactory::forStage('planner', $globalConfig, $repoConfig);

        // Mirror RunCommand's pre-flight checks so non-Anthropic providers
        // fail fast with an actionable message instead of an obscure runtime
        // error inside the selector/planner call. Restricted to the two
        // stages PlanCommand actually invokes — probing executor would warn
        // (or throw on Ollama) for a stage the command never reaches.
        $this->runProviderHealthChecks($globalConfig, $repoConfig);

        $repoProfile = [
            'repo_summary' => $repoConfig->repoSummary(),
            'conventions' => $repoConfig->conventions(),
            'allowed_commands' => $repoConfig->allowedCommands(),
            'blocked_paths' => $repoConfig->blockedPaths(),
            'max_files_changed' => $globalConfig->defaultMaxFiles(),
            'max_lines_changed' => $globalConfig->defaultMaxLines(),
        ];

        $this->line($progress->step('Fetch and prefilter issues'));
        $github = new GitHubService;
        $issues = $github->getIssues($repo, $repoConfig->requiredLabels());

        $prefilter = new IssuePrefilterService($repoConfig, $github, $repo);
        $prefiltered = $prefilter->filter($issues);
        $this->line($progress->detail(count($prefiltered->accepted).' accepted, '.count($prefiltered->rejected).' rejected'));

        $this->line($progress->step('Run selector'));
        $selector = new ClaudeSelectorService($globalConfig, $selectorClient);
        $selection = $selector->selectTask($repoProfile, $prefiltered->accepted);

        $this->line($progress->detail("Selection: {$selection->decision}"));
        $this->line($progress->detail("Reason: {$selection->reason}"));

        if ($selection->decision === 'skip_all') {
            $this->line('No suitable issue found. Exiting.');

            return;
        }

        $selectedIssue = null;
        foreach ($prefiltered->accepted as $issue) {
            if ((string) $issue['number'] === (string) $selection->selectedTaskId) {
                $selectedIssue = $issue;
                break;
            }
        }

        if ($selectedIssue === null) {
            $this->error("Selected task #{$selection->selectedTaskId} not found in prefiltered list.");

            return;
        }

        $this->line($progress->detail("Selected issue #{$selectedIssue['number']}: {$selectedIssue['title']}"));
        $this->line($progress->step('Run planner'));
        $planner = new ClaudePlannerService($globalConfig, $plannerClient);
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
            $this->line('  '.($i + 1).'. '.$step);
        }
        $this->line('');
        $this->line('Commands to run:');
        foreach ($plan->commandsToRun as $cmd) {
            $this->line("  - {$cmd}");
        }

        $this->line($progress->step('Validate plan'));
        $validator = new PlanValidatorService;
        $errors = $validator->validate($plan, $repoProfile);

        $artifactPath = (new PlanArtifactStore)->save($repo, $selectedIssue, $plan, $errors);

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
        $this->line('  - Selector: '.AnthropicCostEstimator::format($selection->usage));
        $this->line('  - Planner: '.AnthropicCostEstimator::format($plan->usage));
        $total = AnthropicCostEstimator::combine($selection->usage, $plan->usage);
        $this->line('  - Total: '.AnthropicCostEstimator::format($total));
    }

    /**
     * Mirrors RunCommand's pre-flight checks for selector/planner stages: Ollama
     * reachability, Ollama model-capability warning, and claude-code/codex
     * binary presence. Failures here surface with actionable messages instead
     * of a vague API/CLI error inside the selector or planner call.
     */
    private function runProviderHealthChecks(GlobalConfig $globalConfig, RepoConfig $repoConfig): void
    {
        // Stages PlanCommand actually invokes — excludes 'executor' so a config
        // that only points the executor at e.g. Ollama doesn't make `plan` throw
        // when Ollama isn't running.
        $planStages = ['selector', 'planner'];

        $ollamaStages = LlmClientFactory::ollamaStageConfigs($globalConfig, $repoConfig, $planStages);
        $probedUrls = [];
        foreach ($ollamaStages as $entry) {
            $url = $entry['base_url'];
            if (! in_array($url, $probedUrls, true)) {
                $this->probeOllama($url);
                $probedUrls[] = $url;
            }
        }

        $warnedModels = [];
        foreach ($ollamaStages as $entry) {
            $model = $entry['model'] ?? '';
            if ($model === '' || in_array($model, $warnedModels, true)) {
                continue;
            }
            $normalized = str_contains($model, ':') ? $model : $model.':latest';
            if (! in_array($model, OpenAiCompatClient::TOOL_CAPABLE_MODELS, true)
                && ! in_array($normalized, OpenAiCompatClient::TOOL_CAPABLE_MODELS, true)) {
                $this->warn("Warning: Ollama model '{$model}' is not on the known tool-capable list. Tool use may fail.");
            }
            $warnedModels[] = $model;
        }

        $checkedBinaries = [];
        $finder = new ExecutableFinder;

        foreach (LlmClientFactory::claudeCodeStageConfigs($globalConfig, $repoConfig, $planStages) as $entry) {
            $binary = $entry['binary_path'] ?? 'claude';
            if (in_array($binary, $checkedBinaries, true)) {
                continue;
            }
            $checkedBinaries[] = $binary;

            if ($finder->find($binary) === null) {
                $this->warn("Warning: Claude Code binary '{$binary}' not found on PATH. The claude-code provider will fail at runtime. Install with `npm install -g @anthropic-ai/claude-code` or set `binary_path` in your llm config.");
            }
        }

        foreach (LlmClientFactory::codexStageConfigs($globalConfig, $repoConfig, $planStages) as $entry) {
            $binary = $entry['binary_path'] ?? 'codex';
            if (in_array($binary, $checkedBinaries, true)) {
                continue;
            }
            $checkedBinaries[] = $binary;

            if ($finder->find($binary) === null) {
                $this->warn("Warning: Codex binary '{$binary}' not found on PATH. The codex provider will fail at runtime. Install the Codex CLI or set `binary_path` in your llm config.");
            }
        }
    }

    private function probeOllama(string $baseUrl): void
    {
        $probeUrl = rtrim(preg_replace('#/v1$#i', '', $baseUrl), '/').'/api/tags';

        $httpClient = new Client(['timeout' => 3]);

        try {
            $httpClient->get($probeUrl);
        } catch (ConnectException) {
            throw new \RuntimeException("Ollama is not reachable at {$baseUrl}. Is it running?");
        } catch (Throwable $e) {
            throw new \RuntimeException("Ollama probe failed at {$baseUrl}: ".$e->getMessage());
        }
    }
}

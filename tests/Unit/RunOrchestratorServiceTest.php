<?php

use App\Data\ExecutionResult;
use App\Data\ModelUsage;
use App\Data\PlanResult;
use App\Data\PrefilterResult;
use App\Data\RunResult;
use App\Data\SelectionResult;
use App\Data\VerificationResult;
use App\Services\ClaudeExecutorService;
use App\Services\ClaudePlannerService;
use App\Services\ClaudeSelectorService;
use App\Services\GitHubService;
use App\Services\GitService;
use App\Services\IssuePrefilterService;
use App\Services\PlanValidatorService;
use App\Services\RunOrchestratorService;
use App\Services\VerificationService;
use App\Services\WorkspaceService;
use App\Support\PlanArtifactStore;
use App\Support\RunLogStore;
use App\Support\RunProgressSnapshot;

afterEach(function () {
    \Mockery::close();
});

it('completes the happy path and opens a draft PR', function () {
    $stores = makeStores();
    $issue = makeIssue();
    $selection = new SelectionResult('accept', 42, 'looks good', [], usage('selector'));
    $plan = makePlan(usage: usage('planner'));
    $execution = executionResult(success: true, summary: 'Implemented successfully');
    $verification = new VerificationResult(true, []);

    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')->once()->andReturn([$issue]);
    $github->shouldReceive('createDraftPr')->once()->with('acme/repo', 'feature/test-branch', 'Test PR', 'PR body')
        ->andReturn(['html_url' => 'https://example.test/pr/1', 'number' => 1]);
    $github->shouldReceive('removeLabel')->once()->with('acme/repo', 42, 'agent-ready');
    $github->shouldReceive('commentOnIssue')->once()->with('acme/repo', 42, \Mockery::type('string'));

    $prefilter = \Mockery::mock(IssuePrefilterService::class);
    $prefilter->shouldReceive('filter')->once()->andReturn(new PrefilterResult([$issue], []));

    $selector = \Mockery::mock(ClaudeSelectorService::class);
    $selector->shouldReceive('selectTask')->once()->andReturn($selection);

    $planner = \Mockery::mock(ClaudePlannerService::class);
    $planner->shouldReceive('planTask')->once()->andReturn($plan);

    $validator = \Mockery::mock(PlanValidatorService::class);
    $validator->shouldReceive('validate')->once()->andReturn([]);

    $workspace = \Mockery::mock(WorkspaceService::class);
    $workspace->shouldReceive('create')->once()->with('/repos/acme', 'feature/test-branch', '')->andReturn('/tmp/worktree');
    $workspace->shouldReceive('cleanup')->once()->with('/repos/acme', '/tmp/worktree');

    $git = \Mockery::mock(GitService::class);
    $git->shouldReceive('commit')->once()->with('/tmp/worktree', 'agent: implement #42 Fix bug');
    $git->shouldReceive('push')->once()->with('/tmp/worktree', 'feature/test-branch');

    $executor = \Mockery::mock(ClaudeExecutorService::class);
    $executor->shouldReceive('executeWithRepoProfile')->once()->andReturn($execution);

    $verifier = \Mockery::mock(VerificationService::class);
    $verifier->shouldReceive('verify')->once()->andReturn($verification);

    $service = makeOrchestrator(
        github: $github,
        prefilter: $prefilter,
        selector: $selector,
        planner: $planner,
        validator: $validator,
        workspace: $workspace,
        git: $git,
        executor: $executor,
        verifier: $verifier,
        planArtifactStore: $stores['plan'],
        runLogStore: $stores['log'],
    );

    $snapshot = new RunProgressSnapshot;
    $result = $service->run('acme/repo', ['repo_path' => '/repos/acme', 'required_labels' => ['agent-ready']], snapshot: $snapshot);

    expect($result->status)->toBe('succeeded');
    expect($result->prUrl)->toBe('https://example.test/pr/1');
    expect($result->prNumber)->toBe(1);
    expect($stores['plan']->saved[0]['validationErrors'])->toBe([]);
    expect($stores['log']->payloads)->toHaveCount(1);
    expect($stores['log']->payloads[0]['status'])->toBe('succeeded');
    expect($snapshot->selectedIssueNumber)->toBe(42);
});

it('returns skipped when the selector skips all issues', function () {
    $stores = makeStores();
    $issue = makeIssue();

    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')->once()->andReturn([$issue]);

    $prefilter = \Mockery::mock(IssuePrefilterService::class);
    $prefilter->shouldReceive('filter')->once()->andReturn(new PrefilterResult([$issue], []));

    $selector = \Mockery::mock(ClaudeSelectorService::class);
    $selector->shouldReceive('selectTask')->once()->andReturn(new SelectionResult('skip_all', null, 'nothing safe', [], usage('selector')));

    $service = makeOrchestrator(
        github: $github,
        prefilter: $prefilter,
        selector: $selector,
        planArtifactStore: $stores['plan'],
        runLogStore: $stores['log'],
    );

    $result = $service->run('acme/repo', []);

    expect($result->status)->toBe('skipped');
    expect($result->failureReason)->toBe('nothing safe');
    expect($stores['plan']->saved)->toBe([]);
    expect($stores['log']->payloads[0]['status'])->toBe('skipped');
});

it('returns skipped when the planner declines the selected issue', function () {
    $stores = makeStores();
    $issue = makeIssue();

    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')->once()->andReturn([$issue]);

    $prefilter = \Mockery::mock(IssuePrefilterService::class);
    $prefilter->shouldReceive('filter')->once()->andReturn(new PrefilterResult([$issue], []));

    $selector = \Mockery::mock(ClaudeSelectorService::class);
    $selector->shouldReceive('selectTask')->once()->andReturn(new SelectionResult('accept', 42, 'ok', [], usage('selector')));

    $planner = \Mockery::mock(ClaudePlannerService::class);
    $planner->shouldReceive('planTask')->once()->andReturn(makePlan(decision: 'decline', declineReason: 'too risky', usage: usage('planner')));

    $service = makeOrchestrator(
        github: $github,
        prefilter: $prefilter,
        selector: $selector,
        planner: $planner,
        planArtifactStore: $stores['plan'],
        runLogStore: $stores['log'],
    );

    $result = $service->run('acme/repo', []);

    expect($result->status)->toBe('skipped');
    expect($result->failureReason)->toBe('too risky');
    expect($stores['plan']->saved)->toBe([]);
    expect($stores['log']->payloads[0]['status'])->toBe('skipped');
});

it('returns failed when validation fails after saving the plan artifact', function () {
    $stores = makeStores();
    $issue = makeIssue();
    $plan = makePlan(usage: usage('planner'));

    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')->once()->andReturn([$issue]);

    $prefilter = \Mockery::mock(IssuePrefilterService::class);
    $prefilter->shouldReceive('filter')->once()->andReturn(new PrefilterResult([$issue], []));

    $selector = \Mockery::mock(ClaudeSelectorService::class);
    $selector->shouldReceive('selectTask')->once()->andReturn(new SelectionResult('accept', 42, 'ok', [], usage('selector')));

    $planner = \Mockery::mock(ClaudePlannerService::class);
    $planner->shouldReceive('planTask')->once()->andReturn($plan);

    $validator = \Mockery::mock(PlanValidatorService::class);
    $validator->shouldReceive('validate')->once()->andReturn(['blocked path']);

    $service = makeOrchestrator(
        github: $github,
        prefilter: $prefilter,
        selector: $selector,
        planner: $planner,
        validator: $validator,
        planArtifactStore: $stores['plan'],
        runLogStore: $stores['log'],
    );

    $result = $service->run('acme/repo', []);

    expect($result->status)->toBe('failed');
    expect($result->failureReason)->toBe('blocked path');
    expect($stores['plan']->saved)->toHaveCount(1);
    expect($stores['log']->payloads[0]['status'])->toBe('failed');
});

it('returns failed immediately when the executor reports failure', function () {
    $stores = makeStores();
    $issue = makeIssue();
    $plan = makePlan(usage: usage('planner'));
    $execution = executionResult(success: false, summary: 'executor blew up');

    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')->once()->andReturn([$issue]);
    $github->shouldReceive('commentOnIssue')->once()->with('acme/repo', 42, \Mockery::on(fn (string $body) => str_contains($body, 'executor blew up')));

    $prefilter = \Mockery::mock(IssuePrefilterService::class);
    $prefilter->shouldReceive('filter')->once()->andReturn(new PrefilterResult([$issue], []));

    $selector = \Mockery::mock(ClaudeSelectorService::class);
    $selector->shouldReceive('selectTask')->once()->andReturn(new SelectionResult('accept', 42, 'ok', [], usage('selector')));

    $planner = \Mockery::mock(ClaudePlannerService::class);
    $planner->shouldReceive('planTask')->once()->andReturn($plan);

    $validator = \Mockery::mock(PlanValidatorService::class);
    $validator->shouldReceive('validate')->once()->andReturn([]);

    $workspace = \Mockery::mock(WorkspaceService::class);
    $workspace->shouldReceive('create')->once()->andReturn('/tmp/worktree');
    $workspace->shouldReceive('cleanup')->once()->with(\Mockery::any(), '/tmp/worktree');

    $executor = \Mockery::mock(ClaudeExecutorService::class);
    $executor->shouldReceive('executeWithRepoProfile')->once()->andReturn($execution);

    $verifier = \Mockery::mock(VerificationService::class);
    $verifier->shouldNotReceive('verify');

    $git = \Mockery::mock(GitService::class);
    $git->shouldNotReceive('commit');
    $git->shouldNotReceive('push');

    $service = makeOrchestrator(
        github: $github,
        prefilter: $prefilter,
        selector: $selector,
        planner: $planner,
        validator: $validator,
        workspace: $workspace,
        git: $git,
        executor: $executor,
        verifier: $verifier,
        planArtifactStore: $stores['plan'],
        runLogStore: $stores['log'],
    );

    $result = $service->run('acme/repo', []);

    expect($result->status)->toBe('failed');
    expect($result->failureReason)->toBe('executor blew up');
    expect($stores['log']->payloads[0]['status'])->toBe('failed');
});

it('returns failed when verification fails after execution', function () {
    $stores = makeStores();
    $issue = makeIssue();
    $plan = makePlan(usage: usage('planner'));
    $execution = executionResult(success: true, summary: 'implemented');

    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')->once()->andReturn([$issue]);
    $github->shouldReceive('commentOnIssue')->once()->with('acme/repo', 42, \Mockery::on(fn (string $body) => str_contains($body, 'verification failed')));

    $prefilter = \Mockery::mock(IssuePrefilterService::class);
    $prefilter->shouldReceive('filter')->once()->andReturn(new PrefilterResult([$issue], []));

    $selector = \Mockery::mock(ClaudeSelectorService::class);
    $selector->shouldReceive('selectTask')->once()->andReturn(new SelectionResult('accept', 42, 'ok', [], usage('selector')));

    $planner = \Mockery::mock(ClaudePlannerService::class);
    $planner->shouldReceive('planTask')->once()->andReturn($plan);

    $validator = \Mockery::mock(PlanValidatorService::class);
    $validator->shouldReceive('validate')->once()->andReturn([]);

    $workspace = \Mockery::mock(WorkspaceService::class);
    $workspace->shouldReceive('create')->once()->andReturn('/tmp/worktree');
    $workspace->shouldReceive('cleanup')->once()->with(\Mockery::any(), '/tmp/worktree');

    $executor = \Mockery::mock(ClaudeExecutorService::class);
    $executor->shouldReceive('executeWithRepoProfile')->once()->andReturn($execution);

    $verifier = \Mockery::mock(VerificationService::class);
    $verifier->shouldReceive('verify')->once()->andReturn(new VerificationResult(false, ['verification failed']));

    $service = makeOrchestrator(
        github: $github,
        prefilter: $prefilter,
        selector: $selector,
        planner: $planner,
        validator: $validator,
        workspace: $workspace,
        executor: $executor,
        verifier: $verifier,
        planArtifactStore: $stores['plan'],
        runLogStore: $stores['log'],
    );

    $result = $service->run('acme/repo', []);

    expect($result->status)->toBe('failed');
    expect($result->failureReason)->toBe('verification failed');
    expect($stores['log']->payloads[0]['status'])->toBe('failed');
});

it('cleans up the workspace and writes a partial run log when the executor throws', function () {
    $stores = makeStores();
    $issue = makeIssue();
    $plan = makePlan(usage: usage('planner'));
    $snapshot = new RunProgressSnapshot;

    $github = \Mockery::mock(GitHubService::class);
    $github->shouldReceive('getIssues')->once()->andReturn([$issue]);

    $prefilter = \Mockery::mock(IssuePrefilterService::class);
    $prefilter->shouldReceive('filter')->once()->andReturn(new PrefilterResult([$issue], []));

    $selector = \Mockery::mock(ClaudeSelectorService::class);
    $selector->shouldReceive('selectTask')->once()->andReturn(new SelectionResult('accept', 42, 'ok', [], usage('selector')));

    $planner = \Mockery::mock(ClaudePlannerService::class);
    $planner->shouldReceive('planTask')->once()->andReturn($plan);

    $validator = \Mockery::mock(PlanValidatorService::class);
    $validator->shouldReceive('validate')->once()->andReturn([]);

    $workspace = \Mockery::mock(WorkspaceService::class);
    $workspace->shouldReceive('create')->once()->andReturn('/tmp/worktree');
    $workspace->shouldReceive('cleanup')->once()->with(\Mockery::any(), '/tmp/worktree');

    $executor = \Mockery::mock(ClaudeExecutorService::class);
    $executor->shouldReceive('executeWithRepoProfile')->once()->andThrow(new \RuntimeException('kaboom'));

    $service = makeOrchestrator(
        github: $github,
        prefilter: $prefilter,
        selector: $selector,
        planner: $planner,
        validator: $validator,
        workspace: $workspace,
        executor: $executor,
        planArtifactStore: $stores['plan'],
        runLogStore: $stores['log'],
    );

    expect(fn () => $service->run('acme/repo', [], snapshot: $snapshot))
        ->toThrow(\RuntimeException::class, 'kaboom');

    expect($stores['log']->payloads)->toHaveCount(1);
    expect($stores['log']->payloads[0]['status'])->toBe('crashed');
    expect($stores['log']->payloads[0]['partial'])->toBeTrue();
    expect($stores['log']->payloads[0]['issue']['number'])->toBe(42);
});

function makeOrchestrator(
    ?GitHubService $github = null,
    ?IssuePrefilterService $prefilter = null,
    ?ClaudeSelectorService $selector = null,
    ?ClaudePlannerService $planner = null,
    ?PlanValidatorService $validator = null,
    ?WorkspaceService $workspace = null,
    ?GitService $git = null,
    ?ClaudeExecutorService $executor = null,
    ?VerificationService $verifier = null,
    ?PlanArtifactStore $planArtifactStore = null,
    ?RunLogStore $runLogStore = null,
): RunOrchestratorService {
    return new RunOrchestratorService(
        $github ?? \Mockery::mock(GitHubService::class),
        $prefilter ?? \Mockery::mock(IssuePrefilterService::class),
        $selector ?? \Mockery::mock(ClaudeSelectorService::class),
        $planner ?? \Mockery::mock(ClaudePlannerService::class),
        $validator ?? \Mockery::mock(PlanValidatorService::class),
        $workspace ?? \Mockery::mock(WorkspaceService::class),
        $git ?? \Mockery::mock(GitService::class),
        $executor ?? \Mockery::mock(ClaudeExecutorService::class),
        $verifier ?? \Mockery::mock(VerificationService::class),
        $planArtifactStore,
        $runLogStore,
    );
}

function makeStores(): array
{
    $planStore = new class extends PlanArtifactStore
    {
        public array $saved = [];

        public function save(string $repo, array $issue, PlanResult $plan, array $validationErrors = []): string
        {
            $this->saved[] = [
                'repo' => $repo,
                'issue' => $issue,
                'plan' => $plan,
                'validationErrors' => $validationErrors,
            ];

            return '/tmp/last-plan.json';
        }
    };

    $runLogStore = new class extends RunLogStore
    {
        public array $payloads = [];

        public function append(array $payload): string
        {
            $this->payloads[] = $payload;

            return '/tmp/runs.jsonl';
        }
    };

    return ['plan' => $planStore, 'log' => $runLogStore];
}

function makeIssue(): array
{
    return [
        'number' => 42,
        'title' => 'Fix bug',
        'html_url' => 'https://example.test/issues/42',
    ];
}

function makePlan(
    string $decision = 'accept',
    ?string $declineReason = null,
    ?ModelUsage $usage = null,
): PlanResult {
    return new PlanResult(
        decision: $decision,
        branchName: 'feature/test-branch',
        filesToRead: [],
        filesToChange: ['src/file.php'],
        blockedWritePaths: [],
        steps: ['Implement fix'],
        commandsToRun: ['./vendor/bin/pest'],
        testsToUpdate: ['tests/Unit/RunOrchestratorServiceTest.php'],
        successCriteria: ['All tests pass'],
        guardrails: [],
        prTitle: 'Test PR',
        prBody: 'PR body',
        maxFilesChanged: 3,
        maxLinesChanged: 250,
        declineReason: $declineReason,
        usage: $usage,
    );
}

function executionResult(bool $success, string $summary): ExecutionResult
{
    return new ExecutionResult(
        success: $success,
        summary: $summary,
        toolCallLog: [],
        toolCallCount: 0,
        durationSeconds: 1.2,
        usage: usage('executor'),
    );
}

function usage(string $model): ModelUsage
{
    return new ModelUsage($model, 10, 5, 0.123456, 0, 0);
}

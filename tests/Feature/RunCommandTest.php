<?php

use App\Commands\RunCommand;
use App\Config\GlobalConfig;
use App\Data\ModelUsage;
use App\Data\RunResult;
use App\Support\RunLogStore;
use Symfony\Component\Console\Tester\CommandTester;

it('formats the visible usage summary lines for run output', function () {
    $lines = RunCommand::usageSummaryLines(
        new ModelUsage('claude-haiku', 1_200, 100, 0.0037),
        new ModelUsage('claude-sonnet', 4_000, 900, 0.0255),
        new ModelUsage('claude-sonnet', 10_000, 2_000, 0.06),
        12.4,
    );

    expect($lines)->toContain('Usage:');
    expect($lines)->toContain('  - Selector: 1,200 input, 100 output, $0.0037 est.');
    expect($lines)->toContain('  - Planner: 4,000 input, 900 output, $0.0255 est.');
    expect($lines)->toContain('  - Executor: 10,000 input, 2,000 output, $0.0600 est.');
    expect($lines)->toContain('  - Total: 15,200 input, 3,000 output, $0.0892 est.');
    expect($lines)->toContain('  - Executor elapsed: 12s');
});

it('formats total usage summaries with cache token details', function () {
    $lines = RunCommand::usageSummaryLines(
        new ModelUsage('claude-haiku', 1_200, 100, 0.0037),
        new ModelUsage('claude-sonnet', 4_000, 900, 0.0255, 800, 2_500),
        null,
        null,
        'Total usage:'
    );

    expect($lines)->toContain('Total usage:');
    expect($lines)->toContain('  - Planner: 4,000 input (+800 write, 2,500 read), 900 output, $0.0255 est.');
    expect($lines)->toContain('  - Total: 5,200 input (+800 write, 2,500 read), 1,000 output, $0.0292 est.');
});

it('logs pre-orchestrator repo failures and continues to later repos', function () {
    $executedRepos = [];
    $loggedPayloads = [];

    $globalConfig = new class extends GlobalConfig
    {
        public function __construct() {}

        public function configuredRepos(): array
        {
            return [
                ['slug' => 'acme/failing-repo', 'path' => '/tmp/failing-repo'],
                ['slug' => 'acme/success-repo', 'path' => '/tmp/success-repo'],
            ];
        }
    };

    $runLogStore = new class($loggedPayloads) extends RunLogStore
    {
        public function __construct(private array &$payloads) {}

        public function append(array $payload): string
        {
            $this->payloads[] = $payload;

            return '/tmp/copland-test-home/.copland/logs/runs.jsonl';
        }
    };

    $command = new RunCommand(
        globalConfig: $globalConfig,
        runLogStore: $runLogStore,
        repoRunner: function (string $repo) use (&$executedRepos): RunResult {
            $executedRepos[] = $repo;

            if ($repo === 'acme/failing-repo') {
                throw new RuntimeException('Configured path does not exist: /tmp/failing-repo');
            }

            return new RunResult(
                status: 'succeeded',
                prUrl: 'https://example.test/pr/123',
                prNumber: 123,
                selectedIssueTitle: 'Ship the change',
                selectedTaskId: 55,
                failureReason: null,
                log: ['[8/8] done'],
                startedAt: '2026-04-03T20:00:00+00:00',
                finishedAt: '2026-04-03T20:01:00+00:00',
            );
        },
    );
    $command->setLaravel($this->app);

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);
    $display = $tester->getDisplay();

    expect($exitCode)->toBe(1);
    expect($executedRepos)->toBe(['acme/failing-repo', 'acme/success-repo']);
    expect($loggedPayloads)->toHaveCount(1);
    expect($loggedPayloads[0]['repo'])->toBe('acme/failing-repo');
    expect($loggedPayloads[0]['status'])->toBe('failed');
    expect($loggedPayloads[0]['partial'])->toBeFalse();
    expect($loggedPayloads[0]['failure_reason'])->toBe('Configured path does not exist: /tmp/failing-repo');
    expect($loggedPayloads[0]['issue'])->toBe(['number' => null, 'title' => null]);
    expect($display)->toContain('Appended run log to /tmp/copland-test-home/.copland/logs/runs.jsonl');
    expect($display)->toContain('acme/success-repo: Succeeded — PR: https://example.test/pr/123');
});

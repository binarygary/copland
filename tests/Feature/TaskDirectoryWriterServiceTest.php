<?php

use App\Services\TaskDirectoryWriterService;

it('writes task.md and status.md under a temporary HOME for a GitHub-shaped task', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-task-writer-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T08:14:01Z');

    $writer->writeNewTask(
        'binarygary/copland',
        42,
        'Add console launcher',
        'Body of the issue.',
        '/Users/gary/projects/copland',
        'https://github.com/binarygary/copland/issues/42',
    );

    $taskPath = $home.'/.copland/tasks/binarygary__copland/42/task.md';
    expect(file_exists($taskPath))->toBeTrue();

    $taskContent = file_get_contents($taskPath);
    expect($taskContent)->toContain('id: "42"');
    expect($taskContent)->toContain('title: "Add console launcher"');
    expect($taskContent)->toContain('repo_slug: "binarygary/copland"');
    expect($taskContent)->toContain('repo_path: "/Users/gary/projects/copland"');
    expect($taskContent)->toContain('source_url: "https://github.com/binarygary/copland/issues/42"');
    expect($taskContent)->toContain('created_at: "2026-05-27T08:14:01Z"');
    expect($taskContent)->toContain('Body of the issue.');

    $writer->writeStatus('binarygary/copland', 42, 'new');
    $writer->writeStatus('binarygary/copland', 42, 'planning');

    $statusPath = $home.'/.copland/tasks/binarygary__copland/42/status.md';
    expect(file_exists($statusPath))->toBeTrue();

    $statusContent = file_get_contents($statusPath);
    expect($statusContent)->toContain('state: "planning"');
    expect($statusContent)->toContain('updated_at: "2026-05-27T08:14:01Z"');
    expect($statusContent)->toContain('| 2026-05-27T08:14:01Z | new |');
    expect($statusContent)->toContain('| 2026-05-27T08:14:01Z | planning |');

    $writer->writeBlockedIfNotTerminal('binarygary/copland', 42);

    $statusContent = file_get_contents($statusPath);
    expect($statusContent)->toContain('state: "blocked"');
    expect($statusContent)->toContain('| 2026-05-27T08:14:01Z | blocked |');

    $_SERVER['HOME'] = $originalHome;
});

it('writes task.md for an Asana-shaped task with empty source_url and string GID', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-asana-task-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T09:00:00Z');

    $writer->writeNewTask(
        'copland',
        '1209876543210',
        'Asana title',
        'Asana body',
        '/tmp/copland',
        null,
    );

    $taskPath = $home.'/.copland/tasks/copland/1209876543210/task.md';
    expect(file_exists($taskPath))->toBeTrue();

    $taskContent = file_get_contents($taskPath);
    expect($taskContent)->toContain('id: "1209876543210"');
    expect($taskContent)->toContain('source_url: ""');
    expect($taskContent)->toContain('repo_slug: "copland"');
    expect($taskContent)->toContain('Asana body');

    $_SERVER['HOME'] = $originalHome;
});

it('writes task.md frontmatter with all 7 keys matching the TaskLoader contract', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-task-keys-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T10:00:00Z');

    $writer->writeNewTask(
        'owner/repo',
        7,
        'Title',
        'Body text here.',
        '/path/to/repo',
        'https://example.com/7',
    );

    $taskPath = $home.'/.copland/tasks/owner__repo/7/task.md';
    $taskContent = file_get_contents($taskPath);

    expect($taskContent)->toContain('id: "7"');
    expect($taskContent)->toContain('title: "Title"');
    expect($taskContent)->toContain('repo_slug: "owner/repo"');
    expect($taskContent)->toContain('repo_path: "/path/to/repo"');
    expect($taskContent)->toContain('source_url: "https://example.com/7"');
    expect($taskContent)->toContain('created_at: "2026-05-27T10:00:00Z"');
    expect($taskContent)->toContain('Body text here.');

    $_SERVER['HOME'] = $originalHome;
});

it('writeStatus produces an 8-row transitions table across the full lifecycle', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-status-lifecycle-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $counter = 0;
    $clock = function () use (&$counter) {
        $counter++;

        return sprintf('2026-05-27T11:%02d:00Z', $counter);
    };

    $writer = new TaskDirectoryWriterService(clock: $clock);

    $states = ['new', 'selected', 'planning', 'planned', 'executing', 'verifying', 'pr_open', 'blocked'];
    foreach ($states as $state) {
        $writer->writeStatus('owner/repo', 99, $state);
    }

    $statusPath = $home.'/.copland/tasks/owner__repo/99/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "blocked"');

    // Count rows in the transitions table by counting state-row matches.
    $rowCount = 0;
    foreach ($states as $state) {
        $rowCount += substr_count($statusContent, "| {$state} |");
    }
    expect($rowCount)->toBe(8);

    $_SERVER['HOME'] = $originalHome;
});

it('writeBlockedIfNotTerminal is a no-op after pr_open', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-no-op-pr-open-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T12:00:00Z');

    $writer->writeStatus('owner/repo', 1, 'pr_open');
    $writer->writeBlockedIfNotTerminal('owner/repo', 1);

    $statusPath = $home.'/.copland/tasks/owner__repo/1/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "pr_open"');
    expect(substr_count($statusContent, '| pr_open |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(0);

    $_SERVER['HOME'] = $originalHome;
});

it('writeBlockedIfNotTerminal is a no-op after blocked', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-no-op-blocked-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T12:30:00Z');

    $writer->writeStatus('owner/repo', 2, 'blocked');
    $writer->writeBlockedIfNotTerminal('owner/repo', 2);

    $statusPath = $home.'/.copland/tasks/owner__repo/2/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "blocked"');
    expect(substr_count($statusContent, '| blocked |'))->toBe(1);

    $_SERVER['HOME'] = $originalHome;
});

it('writeBlockedIfNotTerminal transitions executing -> blocked', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-exec-to-blocked-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T13:00:00Z');

    $writer->writeStatus('owner/repo', 3, 'executing');
    $writer->writeBlockedIfNotTerminal('owner/repo', 3);

    $statusPath = $home.'/.copland/tasks/owner__repo/3/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "blocked"');
    expect(substr_count($statusContent, '| executing |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(1);

    $_SERVER['HOME'] = $originalHome;
});

it('writeRunStatus produces a per-run transitions table for the full lifecycle', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-run-lifecycle-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $counter = 0;
    $clock = function () use (&$counter) {
        $counter++;

        return sprintf('2026-05-27T14:%02d:00Z', $counter);
    };

    $writer = new TaskDirectoryWriterService(clock: $clock);

    $runId = '2026-05-27T19-15-22Z';
    $states = ['new', 'selected', 'planning', 'planned', 'executing', 'verifying', 'pr_open', 'blocked'];
    foreach ($states as $state) {
        $writer->writeRunStatus('owner/repo', 50, $runId, $state);
    }

    $statusPath = $home.'/.copland/tasks/owner__repo/50/runs/'.$runId.'/status.md';
    expect(file_exists($statusPath))->toBeTrue();

    $statusContent = file_get_contents($statusPath);
    expect($statusContent)->toContain('state: "blocked"');

    $rowCount = 0;
    foreach ($states as $state) {
        $rowCount += substr_count($statusContent, "| {$state} |");
    }
    expect($rowCount)->toBe(8);

    $_SERVER['HOME'] = $originalHome;
});

it('writeRunStatus accepts a 13-digit string task id and a Z-form run id', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-run-asana-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T15:00:00Z');

    $taskId = '1209876543210';
    $runId = '2026-05-27T19-15-22Z';

    $writer->writeRunStatus('copland', $taskId, $runId, 'planning');

    $statusPath = $home.'/.copland/tasks/copland/'.$taskId.'/runs/'.$runId.'/status.md';
    expect(file_exists($statusPath))->toBeTrue();

    $statusContent = file_get_contents($statusPath);
    expect($statusContent)->toContain('state: "planning"');
    expect($statusContent)->toContain('updated_at: "2026-05-27T15:00:00Z"');
    expect($statusContent)->toContain('| 2026-05-27T15:00:00Z | planning |');

    $_SERVER['HOME'] = $originalHome;
});

it('writeRunBlockedIfNotTerminal respects per-run pr_open as terminal', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-run-no-op-pr-open-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T16:00:00Z');

    $runId = '2026-05-27T19-15-22Z';

    $writer->writeRunStatus('owner/repo', 11, $runId, 'pr_open');
    $writer->writeRunBlockedIfNotTerminal('owner/repo', 11, $runId);

    $statusPath = $home.'/.copland/tasks/owner__repo/11/runs/'.$runId.'/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "pr_open"');
    expect(substr_count($statusContent, '| pr_open |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(0);

    $_SERVER['HOME'] = $originalHome;
});

it('writeRunBlockedIfNotTerminal transitions verifying -> blocked', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-run-verifying-to-blocked-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T16:30:00Z');

    $runId = '2026-05-27T19-15-22Z';

    $writer->writeRunStatus('owner/repo', 12, $runId, 'verifying');
    $writer->writeRunBlockedIfNotTerminal('owner/repo', 12, $runId);

    $statusPath = $home.'/.copland/tasks/owner__repo/12/runs/'.$runId.'/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "blocked"');
    expect(substr_count($statusContent, '| verifying |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(1);

    $_SERVER['HOME'] = $originalHome;
});

it('writeRunOutcome emits all 9 D-05 frontmatter keys', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-outcome-keys-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T17:00:00Z');

    $runId = '2026-05-27T19-15-22Z';
    $outcome = [
        'run_id' => $runId,
        'status' => 'pr_open',
        'pr_number' => '123',
        'pr_url' => 'https://github.com/owner/repo/pull/123',
        'cost_usd' => '0.0042',
        'started_at' => '2026-05-27T19:15:22Z',
        'finished_at' => '2026-05-27T19:18:45Z',
        'failure_reason' => '',
        'partial' => 'false',
    ];

    $writer->writeRunOutcome('owner/repo', 20, $runId, $outcome);

    $outcomePath = $home.'/.copland/tasks/owner__repo/20/runs/'.$runId.'/outcome.md';
    expect(file_exists($outcomePath))->toBeTrue();

    $outcomeContent = file_get_contents($outcomePath);

    expect($outcomeContent)->toContain('run_id: "2026-05-27T19-15-22Z"');
    expect($outcomeContent)->toContain('status: "pr_open"');
    expect($outcomeContent)->toContain('pr_number: "123"');
    expect($outcomeContent)->toContain('pr_url: "https://github.com/owner/repo/pull/123"');
    expect($outcomeContent)->toContain('cost_usd: "0.0042"');
    expect($outcomeContent)->toContain('started_at: "2026-05-27T19:15:22Z"');
    expect($outcomeContent)->toContain('finished_at: "2026-05-27T19:18:45Z"');
    expect($outcomeContent)->toContain('failure_reason: ""');
    expect($outcomeContent)->toContain('partial: "false"');

    // Multiline regex coverage: each key appears at the start of its own line.
    foreach (array_keys($outcome) as $key) {
        expect((bool) preg_match("/^{$key}: /m", $outcomeContent))->toBeTrue();
    }

    $_SERVER['HOME'] = $originalHome;
});

it('writeRunOutcome accepts an optional body with a per-stage usage table', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-outcome-body-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T17:30:00Z');

    $runId = '2026-05-27T19-15-22Z';
    $body = "## Usage\n\n| Stage | Model |\n|-------|-------|\n| selector | claude-haiku |\n";
    $outcome = [
        'run_id' => $runId,
        'status' => 'pr_open',
        'pr_number' => '',
        'pr_url' => '',
        'cost_usd' => '0',
        'started_at' => '2026-05-27T19:15:22Z',
        'finished_at' => '2026-05-27T19:18:45Z',
        'failure_reason' => '',
        'partial' => 'false',
        '_body' => $body,
    ];

    $writer->writeRunOutcome('owner/repo', 21, $runId, $outcome);

    $outcomePath = $home.'/.copland/tasks/owner__repo/21/runs/'.$runId.'/outcome.md';
    $outcomeContent = file_get_contents($outcomePath);

    expect($outcomeContent)->toContain('## Usage');
    expect($outcomeContent)->toContain('| selector | claude-haiku |');
    // _body must NOT appear as a frontmatter key.
    expect($outcomeContent)->not->toContain('_body:');

    // Body must appear after the closing `---` of the frontmatter.
    $closingPos = strpos($outcomeContent, "\n---\n");
    expect($closingPos)->not->toBeFalse();
    $afterFrontmatter = substr($outcomeContent, $closingPos + 5);
    expect($afterFrontmatter)->toContain('## Usage');

    $_SERVER['HOME'] = $originalHome;
});

it('atomic write leaves no .tmp residue after a successful write', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-atomic-write-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T18:00:00Z');

    $runId = '2026-05-27T19-15-22Z';
    $writer->writeStatus('owner/repo', 30, 'new');
    $writer->writeRunStatus('owner/repo', 30, $runId, 'planning');
    $writer->writeRunOutcome('owner/repo', 30, $runId, [
        'run_id' => $runId,
        'status' => 'pr_open',
        'pr_number' => '',
        'pr_url' => '',
        'cost_usd' => '0',
        'started_at' => '2026-05-27T19:15:22Z',
        'finished_at' => '2026-05-27T19:18:45Z',
        'failure_reason' => '',
        'partial' => 'false',
    ]);

    $taskDir = $home.'/.copland/tasks/owner__repo/30';
    $runDir = $taskDir.'/runs/'.$runId;

    expect(glob($taskDir.'/*.tmp'))->toBe([]);
    expect(glob($runDir.'/*.tmp'))->toBe([]);

    $_SERVER['HOME'] = $originalHome;
});

it('writing twice into the same task/run dir is idempotent', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-idempotent-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T18:30:00Z');

    $runId = '2026-05-27T19-15-22Z';

    $writer->writeRunStatus('owner/repo', 40, $runId, 'new');
    $writer->writeRunStatus('owner/repo', 40, $runId, 'new');

    $statusPath = $home.'/.copland/tasks/owner__repo/40/runs/'.$runId.'/status.md';
    expect(file_exists($statusPath))->toBeTrue();

    $statusContent = file_get_contents($statusPath);
    expect(substr_count($statusContent, '| new |'))->toBe(2);

    $_SERVER['HOME'] = $originalHome;
});

it('three sequential writeStatus calls produce a 3-row table not an overwrite', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-3-row-table-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $counter = 0;
    $clock = function () use (&$counter) {
        $counter++;

        return sprintf('2026-05-27T19:%02d:00Z', $counter);
    };

    $writer = new TaskDirectoryWriterService(clock: $clock);

    $writer->writeStatus('owner/repo', 60, 'new');
    $writer->writeStatus('owner/repo', 60, 'selected');
    $writer->writeStatus('owner/repo', 60, 'planning');

    $statusPath = $home.'/.copland/tasks/owner__repo/60/status.md';
    $statusContent = file_get_contents($statusPath);

    expect(substr_count($statusContent, '| new |'))->toBe(1);
    expect(substr_count($statusContent, '| selected |'))->toBe(1);
    expect(substr_count($statusContent, '| planning |'))->toBe(1);
    expect($statusContent)->toContain('state: "planning"');

    $_SERVER['HOME'] = $originalHome;
});

it('lastState map keeps task-level and per-run tuples isolated', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-tuple-isolation-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T20:00:00Z');

    $runId = '2026-05-27T19-15-22Z';

    // Task-level reaches a terminal state.
    $writer->writeStatus('owner/repo', 42, 'pr_open');
    // Per-run tuple was never told a state, so writeRunBlockedIfNotTerminal must be a no-op.
    $writer->writeRunBlockedIfNotTerminal('owner/repo', 42, $runId);

    // Task-level status is untouched and terminal.
    $taskStatusPath = $home.'/.copland/tasks/owner__repo/42/status.md';
    $taskStatusContent = file_get_contents($taskStatusPath);
    expect($taskStatusContent)->toContain('state: "pr_open"');
    expect(substr_count($taskStatusContent, '| pr_open |'))->toBe(1);
    expect(substr_count($taskStatusContent, '| blocked |'))->toBe(0);

    // Per-run status.md must NOT exist — D-15 invariant (never invents state).
    $runStatusPath = $home.'/.copland/tasks/owner__repo/42/runs/'.$runId.'/status.md';
    expect(file_exists($runStatusPath))->toBeFalse();

    $_SERVER['HOME'] = $originalHome;
});

it('writeStatus and writeRunStatus on the same task do not cross-pollute lastState', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-cross-pollute-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T20:30:00Z');

    $runId = '2026-05-27T19-15-22Z';

    $writer->writeStatus('owner/repo', 42, 'executing');
    $writer->writeRunStatus('owner/repo', 42, $runId, 'planning');
    // Task-level executing -> blocked is a valid non-terminal transition.
    $writer->writeBlockedIfNotTerminal('owner/repo', 42);

    // Task-level final state must be 'blocked'.
    $taskStatusPath = $home.'/.copland/tasks/owner__repo/42/status.md';
    $taskStatusContent = file_get_contents($taskStatusPath);
    expect($taskStatusContent)->toContain('state: "blocked"');

    // Per-run state must remain 'planning' — writeBlockedIfNotTerminal must not touch per-run state.
    $runStatusPath = $home.'/.copland/tasks/owner__repo/42/runs/'.$runId.'/status.md';
    $runStatusContent = file_get_contents($runStatusPath);
    expect($runStatusContent)->toContain('state: "planning"');
    expect(substr_count($runStatusContent, '| planning |'))->toBe(1);
    expect(substr_count($runStatusContent, '| blocked |'))->toBe(0);

    $_SERVER['HOME'] = $originalHome;
});

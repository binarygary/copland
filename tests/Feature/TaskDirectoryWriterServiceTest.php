<?php

use App\Services\TaskDirectoryWriterService;

function createTaskWriterTempHome(): string
{
    $home = sys_get_temp_dir().'/copland-'.bin2hex(random_bytes(8));
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;
    $GLOBALS['taskWriterCurrentTempHome'] = $home;

    return $home;
}

function deleteTaskWriterTempHome(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path.DIRECTORY_SEPARATOR.$item;
        if (is_dir($itemPath) && ! is_link($itemPath)) {
            deleteTaskWriterTempHome($itemPath);

            continue;
        }

        unlink($itemPath);
    }

    rmdir($path);
}

function restoreTaskWriterHome(): void
{
    if ($GLOBALS['taskWriterOriginalHomeWasSet']) {
        $_SERVER['HOME'] = $GLOBALS['taskWriterOriginalHome'];

        return;
    }

    unset($_SERVER['HOME']);
}

function cleanupTaskWriterTempHome(): void
{
    $home = $GLOBALS['taskWriterCurrentTempHome'] ?? null;
    if ($home !== null) {
        deleteTaskWriterTempHome($home);
        $GLOBALS['taskWriterCurrentTempHome'] = null;
    }

    restoreTaskWriterHome();
}

beforeEach(function () {
    $GLOBALS['taskWriterOriginalHomeWasSet'] = array_key_exists('HOME', $_SERVER);
    $GLOBALS['taskWriterOriginalHome'] = $_SERVER['HOME'] ?? null;
    $GLOBALS['taskWriterCurrentTempHome'] = null;
});

afterEach(function () {
    cleanupTaskWriterTempHome();
});

it('cleans the tracked temporary HOME and restores HOME after an exception', function () {
    $home = createTaskWriterTempHome();

    try {
        throw new RuntimeException('expected temp HOME cleanup failure path');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('expected temp HOME cleanup failure path');
        cleanupTaskWriterTempHome();
    }

    expect(is_dir($home))->toBeFalse();

    if ($GLOBALS['taskWriterOriginalHomeWasSet']) {
        expect($_SERVER['HOME'])->toBe($GLOBALS['taskWriterOriginalHome']);
    } else {
        expect(array_key_exists('HOME', $_SERVER))->toBeFalse();
    }
});

it('writes task.md and status.md under a temporary HOME for a GitHub-shaped task', function () {
    $home = createTaskWriterTempHome();

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

});

it('writes task.md for an Asana-shaped task with empty source_url and string GID', function () {
    $home = createTaskWriterTempHome();

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

});

it('writes task.md frontmatter with all 7 keys matching the TaskLoader contract', function () {
    $home = createTaskWriterTempHome();

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

});

it('writeStatus produces a 7-row transitions table across the happy-path lifecycle', function () {
    $home = createTaskWriterTempHome();

    $counter = 0;
    $clock = function () use (&$counter) {
        $counter++;

        return sprintf('2026-05-27T11:%02d:00Z', $counter);
    };

    $writer = new TaskDirectoryWriterService(clock: $clock);

    // pr_open is the success terminal — writeStatus rejects further forward
    // transitions past it (see the forward-only guard test below).
    $states = ['new', 'selected', 'planning', 'planned', 'executing', 'verifying', 'pr_open'];
    foreach ($states as $state) {
        $writer->writeStatus('owner/repo', 99, $state);
    }

    $statusPath = $home.'/.copland/tasks/owner__repo/99/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "pr_open"');

    // Count rows in the transitions table by counting state-row matches.
    $rowCount = 0;
    foreach ($states as $state) {
        $rowCount += substr_count($statusContent, "| {$state} |");
    }
    expect($rowCount)->toBe(7);

});

it('writeStatus resets a malformed status.md instead of doubling its content', function () {
    $home = createTaskWriterTempHome();

    // Seed a status.md with no frontmatter delimiters at all. Before the fix,
    // extractBody returned the entire malformed file, so the next writeStatus call
    // would re-embed the whole garbage payload (plus a fresh frontmatter block in
    // front of it). Each subsequent call then doubled the trapped content.
    $statusPath = $home.'/.copland/tasks/owner__repo/200/status.md';
    mkdir(dirname($statusPath), 0700, true);
    $garbage = str_repeat("garbage line with no --- delimiter at all\n", 50);
    file_put_contents($statusPath, $garbage);

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T20:00:00Z');

    // Same state twice: frontmatter length stays identical so the file-size delta
    // is exactly one transitions row. Non-terminal writeStatus calls are not
    // idempotent — each emits a new row — so this still exercises the append path.
    $writer->writeStatus('owner/repo', 200, 'new');
    $sizeAfterFirst = filesize($statusPath);

    $writer->writeStatus('owner/repo', 200, 'new');
    // PHP caches stat() results — clearstatcache so the second filesize()
    // doesn't return the cached pre-second-write value (Copilot inline finding).
    clearstatcache(true, $statusPath);
    $sizeAfterSecond = filesize($statusPath);

    $rowOverhead = strlen("| 2026-05-27T20:00:00Z | new |\n");

    // The garbage payload must be fully discarded — file size grows by one row only.
    $contents = (string) file_get_contents($statusPath);
    expect($contents)->not->toContain('garbage line');
    expect($sizeAfterSecond - $sizeAfterFirst)->toBe($rowOverhead);
});

it('writeRunStatus resets a malformed run status.md instead of doubling its content', function () {
    $home = createTaskWriterTempHome();

    $runId = '2026-05-27T19-15-22Z';
    // extractBody is shared between writeStatus and writeRunStatus, so the same
    // reset behavior must hold on the per-run path — without this, a malformed
    // run status.md (a partial write under ~/.copland/tasks/.../runs/) would
    // compound its garbage on every subsequent writeRunStatus call.
    $statusPath = $home.'/.copland/tasks/owner__repo/201/runs/'.$runId.'/status.md';
    mkdir(dirname($statusPath), 0700, true);
    $garbage = str_repeat("malformed run status line with no --- delimiter\n", 50);
    file_put_contents($statusPath, $garbage);

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T21:00:00Z');

    $writer->writeRunStatus('owner/repo', 201, $runId, 'new');
    $sizeAfterFirst = filesize($statusPath);

    $writer->writeRunStatus('owner/repo', 201, $runId, 'new');
    clearstatcache(true, $statusPath);
    $sizeAfterSecond = filesize($statusPath);

    $rowOverhead = strlen("| 2026-05-27T21:00:00Z | new |\n");

    $contents = (string) file_get_contents($statusPath);
    expect($contents)->not->toContain('malformed run status line');
    expect($sizeAfterSecond - $sizeAfterFirst)->toBe($rowOverhead);
});

it('writeStatus throws when transitioning out of the pr_open terminal state', function () {
    createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T15:00:00Z');

    $writer->writeStatus('owner/repo', 70, 'pr_open');

    expect(fn () => $writer->writeStatus('owner/repo', 70, 'new'))
        ->toThrow(RuntimeException::class, 'terminal');
});

it('writeStatus throws when transitioning out of the blocked terminal state', function () {
    createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T15:30:00Z');

    $writer->writeStatus('owner/repo', 71, 'blocked');

    expect(fn () => $writer->writeStatus('owner/repo', 71, 'executing'))
        ->toThrow(RuntimeException::class, 'terminal');
});

it('terminal guard survives a fresh writer process by reading state from status.md', function () {
    // Scenario claude/codex/gemini caught: RunCommand instantiates a fresh
    // TaskDirectoryWriterService per cron tick, so the in-memory $lastState
    // is empty when the next tick fires. Without disk hydration, the guard
    // silently lets writeStatus(new) overwrite an existing pr_open status.md.
    $home = createTaskWriterTempHome();

    $first = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T17:00:00Z');
    $first->writeStatus('owner/repo', 73, 'pr_open');

    // Brand new instance — empty $lastState. Must hydrate from disk before
    // the terminal check, otherwise the test passes vacuously.
    $second = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T17:30:00Z');

    expect(fn () => $second->writeStatus('owner/repo', 73, 'new'))
        ->toThrow(RuntimeException::class, 'terminal');

    // status.md on disk must still say pr_open — the failed transition must
    // not have written anything.
    $statusContent = file_get_contents($home.'/.copland/tasks/owner__repo/73/status.md');
    expect($statusContent)->toContain('state: "pr_open"');
});

it('writeBlockedIfNotTerminal respects a persisted pr_open across processes', function () {
    // writeBlockedIfNotTerminal short-circuits when $lastState says pr_open/blocked.
    // Same in-memory limitation: a fresh writer used to treat null lastState as
    // "no prior state" and (in writeBlockedIfNotTerminal's case) early-return —
    // but the finally block of the orchestrator only writes blocked AFTER an
    // earlier writeStatus call sets lastState. The cross-process risk is
    // writeStatus itself, which we just covered. Confirm writeBlockedIfNotTerminal
    // now also short-circuits when status.md on disk is already pr_open even if
    // lastState is empty.
    $home = createTaskWriterTempHome();

    $first = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T18:00:00Z');
    $first->writeStatus('owner/repo', 74, 'pr_open');

    $second = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T18:30:00Z');
    $second->writeBlockedIfNotTerminal('owner/repo', 74);

    $statusContent = file_get_contents($home.'/.copland/tasks/owner__repo/74/status.md');
    expect($statusContent)->toContain('state: "pr_open"');
    expect(substr_count($statusContent, '| pr_open |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(0);
});

it('writeStatus is idempotent when re-emitted with the same terminal state (pr_open)', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T16:00:00Z');

    $writer->writeStatus('owner/repo', 72, 'pr_open');
    $writer->writeStatus('owner/repo', 72, 'pr_open');

    $statusContent = file_get_contents($home.'/.copland/tasks/owner__repo/72/status.md');

    expect($statusContent)->toContain('state: "pr_open"');
    // No second row written; the repeat call is a no-op.
    expect(substr_count($statusContent, '| pr_open |'))->toBe(1);
});

it('writeBlockedIfNotTerminal is a no-op after pr_open', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T12:00:00Z');

    $writer->writeStatus('owner/repo', 1, 'pr_open');
    $writer->writeBlockedIfNotTerminal('owner/repo', 1);

    $statusPath = $home.'/.copland/tasks/owner__repo/1/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "pr_open"');
    expect(substr_count($statusContent, '| pr_open |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(0);

});

it('writeBlockedIfNotTerminal is a no-op after blocked', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T12:30:00Z');

    $writer->writeStatus('owner/repo', 2, 'blocked');
    $writer->writeBlockedIfNotTerminal('owner/repo', 2);

    $statusPath = $home.'/.copland/tasks/owner__repo/2/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "blocked"');
    expect(substr_count($statusContent, '| blocked |'))->toBe(1);

});

it('writeBlockedIfNotTerminal transitions executing -> blocked', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T13:00:00Z');

    $writer->writeStatus('owner/repo', 3, 'executing');
    $writer->writeBlockedIfNotTerminal('owner/repo', 3);

    $statusPath = $home.'/.copland/tasks/owner__repo/3/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "blocked"');
    expect(substr_count($statusContent, '| executing |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(1);

});

it('writeRunStatus produces a 7-row per-run transitions table across the happy-path lifecycle', function () {
    $home = createTaskWriterTempHome();

    $counter = 0;
    $clock = function () use (&$counter) {
        $counter++;

        return sprintf('2026-05-27T14:%02d:00Z', $counter);
    };

    $writer = new TaskDirectoryWriterService(clock: $clock);

    $runId = '2026-05-27T19-15-22Z';
    // pr_open is the success terminal for per-run state too now — writeRunStatus
    // applies the same forward-only guard writeStatus does.
    $states = ['new', 'selected', 'planning', 'planned', 'executing', 'verifying', 'pr_open'];
    foreach ($states as $state) {
        $writer->writeRunStatus('owner/repo', 50, $runId, $state);
    }

    $statusPath = $home.'/.copland/tasks/owner__repo/50/runs/'.$runId.'/status.md';
    expect(file_exists($statusPath))->toBeTrue();

    $statusContent = file_get_contents($statusPath);
    expect($statusContent)->toContain('state: "pr_open"');

    $rowCount = 0;
    foreach ($states as $state) {
        $rowCount += substr_count($statusContent, "| {$state} |");
    }
    expect($rowCount)->toBe(7);
});

it('writeRunStatus throws when transitioning out of a per-run pr_open terminal state', function () {
    createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T22:00:00Z');

    $writer->writeRunStatus('owner/repo', 80, 'run-1', 'pr_open');

    expect(fn () => $writer->writeRunStatus('owner/repo', 80, 'run-1', 'new'))
        ->toThrow(RuntimeException::class, 'terminal');
});

it('writeRunStatus terminal guard survives a fresh writer process', function () {
    // Mirrors the writeStatus test: a fresh writer with empty $lastState must
    // still refuse to revert a persisted per-run pr_open. claude+copilot's
    // critical finding was that the guard was half-applied (writeStatus only).
    $home = createTaskWriterTempHome();

    $first = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T22:30:00Z');
    $first->writeRunStatus('owner/repo', 81, 'run-1', 'pr_open');

    $second = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T23:00:00Z');

    expect(fn () => $second->writeRunStatus('owner/repo', 81, 'run-1', 'new'))
        ->toThrow(RuntimeException::class, 'terminal');

    $statusContent = file_get_contents($home.'/.copland/tasks/owner__repo/81/runs/run-1/status.md');
    expect($statusContent)->toContain('state: "pr_open"');
});

it('writeRunStatus accepts a 13-digit string task id and a Z-form run id', function () {
    $home = createTaskWriterTempHome();

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

});

it('writeRunBlockedIfNotTerminal respects per-run pr_open as terminal', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T16:00:00Z');

    $runId = '2026-05-27T19-15-22Z';

    $writer->writeRunStatus('owner/repo', 11, $runId, 'pr_open');
    $writer->writeRunBlockedIfNotTerminal('owner/repo', 11, $runId);

    $statusPath = $home.'/.copland/tasks/owner__repo/11/runs/'.$runId.'/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "pr_open"');
    expect(substr_count($statusContent, '| pr_open |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(0);

});

it('writeRunBlockedIfNotTerminal transitions verifying -> blocked', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T16:30:00Z');

    $runId = '2026-05-27T19-15-22Z';

    $writer->writeRunStatus('owner/repo', 12, $runId, 'verifying');
    $writer->writeRunBlockedIfNotTerminal('owner/repo', 12, $runId);

    $statusPath = $home.'/.copland/tasks/owner__repo/12/runs/'.$runId.'/status.md';
    $statusContent = file_get_contents($statusPath);

    expect($statusContent)->toContain('state: "blocked"');
    expect(substr_count($statusContent, '| verifying |'))->toBe(1);
    expect(substr_count($statusContent, '| blocked |'))->toBe(1);

});

it('writeRunOutcome emits all 9 D-05 frontmatter keys', function () {
    $home = createTaskWriterTempHome();

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

});

it('writeRunOutcome accepts an optional body with a per-stage usage table', function () {
    $home = createTaskWriterTempHome();

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

});

it('atomic write leaves no .tmp residue after a successful write', function () {
    $home = createTaskWriterTempHome();

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

});

it('writing twice into the same task/run dir is idempotent', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-27T18:30:00Z');

    $runId = '2026-05-27T19-15-22Z';

    $writer->writeRunStatus('owner/repo', 40, $runId, 'new');
    $writer->writeRunStatus('owner/repo', 40, $runId, 'new');

    $statusPath = $home.'/.copland/tasks/owner__repo/40/runs/'.$runId.'/status.md';
    expect(file_exists($statusPath))->toBeTrue();

    $statusContent = file_get_contents($statusPath);
    expect(substr_count($statusContent, '| new |'))->toBe(2);

});

it('three sequential writeStatus calls produce a 3-row table not an overwrite', function () {
    $home = createTaskWriterTempHome();

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

});

it('lastState map keeps task-level and per-run tuples isolated', function () {
    $home = createTaskWriterTempHome();

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

});

it('writeStatus and writeRunStatus on the same task do not cross-pollute lastState', function () {
    $home = createTaskWriterTempHome();

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

});

it('escapes newlines in frontmatter so outcome.md stays parseable on multi-line failure_reason (BL-01)', function () {
    $home = createTaskWriterTempHome();

    $writer = new TaskDirectoryWriterService(clock: fn () => '2026-05-28T00:00:00Z');

    $multiline = "verifier failed: missing assertion\nstderr: PHPStan reports 3 errors\n  - line 42 of foo.php\n  - line 88 of bar.php\nbacktrace: ...";

    $writer->writeRunOutcome('owner/repo', 99, '2026-05-28T00-00-00Z', [
        'run_id' => '2026-05-28T00-00-00Z',
        'status' => 'blocked',
        'pr_number' => null,
        'pr_url' => null,
        'cost_usd' => '0.012',
        'started_at' => '2026-05-28T00:00:00Z',
        'finished_at' => '2026-05-28T00:01:30Z',
        'failure_reason' => $multiline,
        'partial' => false,
    ]);

    $path = $home.'/.copland/tasks/owner__repo/99/runs/2026-05-28T00-00-00Z/outcome.md';
    $content = file_get_contents($path);

    // The frontmatter region (between the first two `---` lines) must contain NO
    // literal newlines inside any value — embedded newlines must be escaped to \n.
    $matches = [];
    preg_match('/^---\n(.*?)\n---/s', $content, $matches);
    expect($matches)->not->toBeEmpty('outcome.md must have a proper closing --- delimiter');

    $frontmatterRegion = $matches[1];

    // Each line in the frontmatter region must be a `key: "value"` pair — no leaked content lines.
    foreach (explode("\n", $frontmatterRegion) as $line) {
        expect($line)->toMatch('/^[a-z_]+: ".*"$/', "Leaked content in frontmatter: {$line}");
    }

    // The failure_reason value must contain the literal escape sequence "\n", not a real newline.
    expect($frontmatterRegion)->toContain('failure_reason: "verifier failed: missing assertion\\nstderr: PHPStan reports 3 errors\\n  - line 42 of foo.php\\n  - line 88 of bar.php\\nbacktrace: ..."');

    // All 9 D-05 keys must survive the frontmatter — in particular `partial` which sits after failure_reason.
    foreach (['run_id', 'status', 'pr_number', 'pr_url', 'cost_usd', 'started_at', 'finished_at', 'failure_reason', 'partial'] as $key) {
        expect($content)->toMatch('/^'.$key.': "/m', "Missing key {$key} after newline-escape — frontmatter parse would break");
    }

    // Quote-escape symmetry: a value containing a double-quote should be escaped.
    $writer->writeRunOutcome('owner/repo', 99, '2026-05-28T00-01-00Z', [
        'run_id' => '2026-05-28T00-01-00Z',
        'status' => 'pr_open',
        'pr_number' => '7',
        'pr_url' => 'https://x/y',
        'cost_usd' => '0.001',
        'started_at' => '2026-05-28T00:01:00Z',
        'finished_at' => '2026-05-28T00:01:01Z',
        'failure_reason' => 'said "hello" then crashed',
        'partial' => false,
    ]);
    $path2 = $home.'/.copland/tasks/owner__repo/99/runs/2026-05-28T00-01-00Z/outcome.md';
    $content2 = file_get_contents($path2);
    expect($content2)->toContain('failure_reason: "said \\"hello\\" then crashed"');

    // Backslash-escape symmetry: a value containing a backslash should be escaped to \\.
    $writer->writeRunOutcome('owner/repo', 99, '2026-05-28T00-02-00Z', [
        'run_id' => '2026-05-28T00-02-00Z',
        'status' => 'blocked',
        'pr_number' => null,
        'pr_url' => null,
        'cost_usd' => '0',
        'started_at' => '2026-05-28T00:02:00Z',
        'finished_at' => '2026-05-28T00:02:01Z',
        'failure_reason' => 'path: C:\\Users\\foo',
        'partial' => false,
    ]);
    $path3 = $home.'/.copland/tasks/owner__repo/99/runs/2026-05-28T00-02-00Z/outcome.md';
    $content3 = file_get_contents($path3);
    expect($content3)->toContain('failure_reason: "path: C:\\\\Users\\\\foo"');

});

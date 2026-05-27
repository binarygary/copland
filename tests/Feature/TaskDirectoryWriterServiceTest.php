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

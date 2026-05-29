<?php

use App\Config\GlobalConfig;
use App\Services\TaskListService;

/**
 * Build an isolated tmp HOME with a global config and return [$home, $cleanup].
 */
function setupTaskListHome(string $slug, string $repoRelPath): array
{
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-tasklist-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $repoPath = $home.'/'.$repoRelPath;
    mkdir($repoPath, 0755, true);

    file_put_contents($home.'/.copland.yml', <<<YML
        repos:
          - slug: {$slug}
            path: {$repoPath}
        YML);

    $cleanup = function () use ($home, $originalHome): void {
        if (is_dir($home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $node) {
                $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
            }
            rmdir($home);
        }
        $_SERVER['HOME'] = $originalHome;
    };

    return [$home, $repoPath, $cleanup];
}

it('serves cached issues and overlays run-store state without hitting GitHub', function () {
    $slug = 'binarygary/copland';
    [$home, $repoPath, $cleanup] = setupTaskListHome($slug, 'repo');

    try {
        $now = 1_700_000_000;

        // Pre-seed a *fresh* issue cache so classify() (GitHub) is never called.
        mkdir($home.'/.copland', 0700, true);
        file_put_contents($home.'/.copland/issues-cache.json', json_encode([
            $slug => [
                'generated_at' => $now,
                'accepted' => [
                    ['number' => 42, 'title' => 'Wire footer to live usage', 'body' => 'Plumb tracker.', 'created_at' => '2026-05-14T21:08:00Z', 'html_url' => 'https://x/42'],
                ],
                'rejected' => [
                    ['issue' => ['number' => 99, 'title' => 'Risky migration', 'body' => 'x', 'created_at' => '2026-05-10T00:00:00Z'], 'reason' => 'matched risky keyword: migration'],
                ],
            ],
        ]));

        // Run-store entry for issue 42 → should override state to executing.
        $taskDir = $home.'/.copland/tasks/binarygary__copland/42';
        mkdir($taskDir.'/runs/run-1', 0700, true);
        file_put_contents($taskDir.'/task.md', "---\nid: '42'\ntitle: 'Wire footer to live usage'\nrepo_slug: '{$slug}'\ncreated_at: '2026-05-14 21:08'\n---\n\nbody\n");
        file_put_contents($taskDir.'/status.md', "state: 'executing'\nupdated_at: '2026-05-16 09:30'\n");
        // Latest run's outcome carries a failure reason → must surface on the row.
        file_put_contents($taskDir.'/runs/run-1/outcome.md', "---\nrun_id: 'run-1'\nstatus: 'crashed'\nfailure_reason: 'claude-code: process exited with status 1'\n---\n");

        $service = new TaskListService(
            config: new GlobalConfig,
            githubFactory: function () {
                throw new RuntimeException('GitHub must not be called when cache is fresh');
            },
            cacheTtlSeconds: 120,
            clock: fn (): int => $now,
        );

        $tasks = $service->build();

        expect($tasks)->toHaveCount(2);

        $byId = collect($tasks)->keyBy('id');

        // Accepted + worked issue: state overlaid from the run-store.
        expect($byId['#42']['state'])->toBe('executing');
        expect($byId['#42']['is_real'])->toBeTrue();
        expect($byId['#42']['runs_count'])->toBe(1);
        expect($byId['#42']['task_dir'])->toBe($taskDir);
        expect($byId['#42']['updated'])->toBe('2026-05-16 09:30');
        expect($byId['#42']['failure_reason'])->toBe('claude-code: process exited with status 1');

        // Rejected issue: blocked, with the prefilter reason as the summary.
        expect($byId['#99']['state'])->toBe('blocked');
        expect($byId['#99']['summary'])->toBe('matched risky keyword: migration');
        expect($byId['#99']['task_dir'])->toBe('');
    } finally {
        $cleanup();
    }
});

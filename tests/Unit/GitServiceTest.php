<?php

use App\Services\GitService;

it('prepares a new execution branch in the current checkout', function () {
    $calls = [];

    $git = new GitService(function (array $command, string $cwd) use (&$calls): array {
        $calls[] = $command;

        return match ($command) {
            ['git', 'status', '--porcelain'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'fetch', 'origin'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'switch', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'pull', '--ff-only', 'origin', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'rev-parse', '--verify', 'agent/test-branch'] => ['stdout' => '', 'stderr' => 'fatal: Needed a single revision', 'exitCode' => 128],
            ['git', 'switch', '-c', 'agent/test-branch'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    $git->prepareExecutionBranch('/tmp/repo', 'main', 'agent/test-branch');

    expect($calls)->toBe([
        ['git', 'status', '--porcelain'],
        ['git', 'fetch', 'origin'],
        ['git', 'switch', 'main'],
        ['git', 'pull', '--ff-only', 'origin', 'main'],
        ['git', 'rev-parse', '--verify', 'agent/test-branch'],
        ['git', 'switch', '-c', 'agent/test-branch'],
    ]);
});

it('switches to an existing execution branch after syncing base', function () {
    $calls = [];

    $git = new GitService(function (array $command, string $cwd) use (&$calls): array {
        $calls[] = $command;

        return match ($command) {
            ['git', 'status', '--porcelain'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'fetch', 'origin'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'switch', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'pull', '--ff-only', 'origin', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'rev-parse', '--verify', 'agent/test-branch'] => ['stdout' => 'abc123', 'stderr' => '', 'exitCode' => 0],
            ['git', 'switch', 'agent/test-branch'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    $git->prepareExecutionBranch('/tmp/repo', 'main', 'agent/test-branch');

    expect($calls)->toBe([
        ['git', 'status', '--porcelain'],
        ['git', 'fetch', 'origin'],
        ['git', 'switch', 'main'],
        ['git', 'pull', '--ff-only', 'origin', 'main'],
        ['git', 'rev-parse', '--verify', 'agent/test-branch'],
        ['git', 'switch', 'agent/test-branch'],
    ]);
});

it('refuses to prepare an execution branch from a dirty checkout', function () {
    $calls = [];

    $git = new GitService(function (array $command, string $cwd) use (&$calls): array {
        $calls[] = $command;

        return match ($command) {
            ['git', 'status', '--porcelain'] => ['stdout' => " M app/Example.php\n", 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    expect(fn () => $git->prepareExecutionBranch('/tmp/repo', 'main', 'agent/test-branch'))
        ->toThrow(RuntimeException::class, 'Working tree is dirty');

    expect($calls)->toBe([
        ['git', 'status', '--porcelain'],
    ]);
});

it('surfaces stdout when commit fails with stderr empty', function () {
    $calls = [];
    $git = new GitService(function (array $command, string $cwd) use (&$calls): array {
        $calls[] = $command;

        return match ($command) {
            ['git', 'commit', '-m', 'no changes here'] => [
                'stdout' => "nothing to commit, working tree clean\n",
                'stderr' => '',
                'exitCode' => 1,
            ],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    expect(fn () => $git->commit('/tmp/repo', 'no changes here'))
        ->toThrow(RuntimeException::class, 'nothing to commit');

    // commit() no longer stages — callers run stageAll() separately. Locks in
    // the stage/commit split so a regression that re-merged them is caught.
    expect($calls)->toBe([['git', 'commit', '-m', 'no changes here']]);
});

it('still surfaces stderr when present', function () {
    $git = new GitService(function (array $command, string $cwd): array {
        return match ($command) {
            ['git', 'commit', '-m', 'broken repo'] => [
                'stdout' => '',
                'stderr' => "fatal: not a git repository\n",
                'exitCode' => 128,
            ],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    expect(fn () => $git->commit('/tmp/repo', 'broken repo'))
        ->toThrow(RuntimeException::class, 'fatal: not a git repository');
});

it('stageAll runs git add -A and nothing else', function () {
    $calls = [];
    $git = new GitService(function (array $command, string $cwd) use (&$calls): array {
        $calls[] = $command;

        return match ($command) {
            ['git', 'add', '-A'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    $git->stageAll('/tmp/repo');

    expect($calls)->toBe([['git', 'add', '-A']]);
});

it('hasStagedDiffVsBase returns false when the staged tree matches base', function () {
    $git = new GitService(fn (array $command, string $cwd): array => match ($command) {
        ['git', 'diff', '--cached', '--quiet', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
        default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
    });

    expect($git->hasStagedDiffVsBase('/tmp/repo', 'main'))->toBeFalse();
});

it('hasStagedDiffVsBase returns true when the staged tree differs from base', function () {
    $git = new GitService(fn (array $command, string $cwd): array => match ($command) {
        ['git', 'diff', '--cached', '--quiet', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 1],
        default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
    });

    expect($git->hasStagedDiffVsBase('/tmp/repo', 'main'))->toBeTrue();
});

it('hasStagedDiffVsBase throws on unexpected git exit codes instead of silently skipping', function () {
    // Caught by claude/codex/copilot: exit 128 (unknown base ref, etc.) used
    // to map to "no diff" → orchestrator would untag the issue, delete the
    // branch, and comment "Executor produced no changes" while the real bug
    // was a typo in base_branch.
    $git = new GitService(fn (array $command, string $cwd): array => match ($command) {
        ['git', 'diff', '--cached', '--quiet', 'no-such-base'] => [
            'stdout' => '',
            'stderr' => "fatal: bad revision 'no-such-base'\n",
            'exitCode' => 128,
        ],
        default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
    });

    expect(fn () => $git->hasStagedDiffVsBase('/tmp/repo', 'no-such-base'))
        ->toThrow(RuntimeException::class, "bad revision 'no-such-base'");
});

it('ignores a repo-local .copland.yml file when checking for dirtiness', function () {
    $calls = [];

    $git = new GitService(function (array $command, string $cwd) use (&$calls): array {
        $calls[] = $command;

        return match ($command) {
            ['git', 'status', '--porcelain'] => ['stdout' => "?? .copland.yml\n", 'stderr' => '', 'exitCode' => 0],
            ['git', 'fetch', 'origin'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'switch', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'pull', '--ff-only', 'origin', 'main'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            ['git', 'rev-parse', '--verify', 'agent/test-branch'] => ['stdout' => '', 'stderr' => 'fatal: Needed a single revision', 'exitCode' => 128],
            ['git', 'switch', '-c', 'agent/test-branch'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    $git->prepareExecutionBranch('/tmp/repo', 'main', 'agent/test-branch');

    expect($calls)->toBe([
        ['git', 'status', '--porcelain'],
        ['git', 'fetch', 'origin'],
        ['git', 'switch', 'main'],
        ['git', 'pull', '--ff-only', 'origin', 'main'],
        ['git', 'rev-parse', '--verify', 'agent/test-branch'],
        ['git', 'switch', '-c', 'agent/test-branch'],
    ]);
});

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

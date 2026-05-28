<?php

use App\Data\PlanResult;
use App\Services\PlanValidatorService;

function makeValidatorPlan(array $overrides = []): PlanResult
{
    $defaults = [
        'decision' => 'plan',
        'branchName' => 'agent/issue-1-test',
        'filesToRead' => [],
        'filesToChange' => ['src/file.txt'],
        'blockedWritePaths' => [],
        'steps' => ['do the thing'],
        'commandsToRun' => [],
        'testsToUpdate' => [],
        'successCriteria' => ['tests pass'],
        'guardrails' => [],
        'prTitle' => 'Fix issue',
        'prBody' => 'Body',
        'maxFilesChanged' => 3,
        'maxLinesChanged' => 250,
        'declineReason' => null,
        'changes' => [],
    ];

    $args = array_merge($defaults, $overrides);

    return new PlanResult(
        decision: $args['decision'],
        branchName: $args['branchName'],
        filesToRead: $args['filesToRead'],
        filesToChange: $args['filesToChange'],
        blockedWritePaths: $args['blockedWritePaths'],
        steps: $args['steps'],
        commandsToRun: $args['commandsToRun'],
        testsToUpdate: $args['testsToUpdate'],
        successCriteria: $args['successCriteria'],
        guardrails: $args['guardrails'],
        prTitle: $args['prTitle'],
        prBody: $args['prBody'],
        maxFilesChanged: $args['maxFilesChanged'],
        maxLinesChanged: $args['maxLinesChanged'],
        declineReason: $args['declineReason'],
        changes: $args['changes'],
    );
}

function defaultValidatorProfile(): array
{
    return [
        'max_files_changed' => 3,
        'max_lines_changed' => 250,
        'allowed_commands' => [],
        'blocked_paths' => [],
    ];
}

it('reports no changes-related errors when changes array is empty', function () {
    $plan = makeValidatorPlan(['changes' => []]);

    $errors = (new PlanValidatorService)->validate($plan, defaultValidatorProfile());

    $changesErrors = array_filter(
        $errors,
        fn (string $error): bool => str_starts_with($error, 'changes['),
    );

    expect($changesErrors)->toBe([]);
});

it('accepts a well-formed changes entry whose file is in files_to_change', function () {
    $plan = makeValidatorPlan([
        'filesToChange' => ['src/file.txt'],
        'changes' => [
            [
                'file' => 'src/file.txt',
                'old' => 'foo',
                'new' => 'bar',
                'reason' => 'rename foo to bar',
            ],
        ],
    ]);

    $errors = (new PlanValidatorService)->validate($plan, defaultValidatorProfile());

    $changesErrors = array_filter(
        $errors,
        fn (string $error): bool => str_starts_with($error, 'changes['),
    );

    expect($changesErrors)->toBe([]);
});

it('rejects a changes entry whose file is not in files_to_change', function () {
    $plan = makeValidatorPlan([
        'filesToChange' => ['src/file.txt'],
        'changes' => [
            [
                'file' => 'src/other.txt',
                'old' => 'foo',
                'new' => 'bar',
                'reason' => 'rename foo to bar',
            ],
        ],
    ]);

    $errors = (new PlanValidatorService)->validate($plan, defaultValidatorProfile());

    expect($errors)->toContain("changes[0] file 'src/other.txt' not in files_to_change");
});

it('rejects a changes entry whose old text is empty', function () {
    $plan = makeValidatorPlan([
        'filesToChange' => ['src/file.txt'],
        'changes' => [
            [
                'file' => 'src/file.txt',
                'old' => '',
                'new' => 'bar',
                'reason' => 'rename foo to bar',
            ],
        ],
    ]);

    $errors = (new PlanValidatorService)->validate($plan, defaultValidatorProfile());

    expect($errors)->toContain("changes[0] has empty 'old' text");
});

it('rejects a changes entry missing a reason', function () {
    $plan = makeValidatorPlan([
        'filesToChange' => ['src/file.txt'],
        'changes' => [
            [
                'file' => 'src/file.txt',
                'old' => 'foo',
                'new' => 'bar',
                'reason' => '',
            ],
        ],
    ]);

    $errors = (new PlanValidatorService)->validate($plan, defaultValidatorProfile());

    expect($errors)->toContain("changes[0] is missing 'reason'");
});

it('rejects a changes entry whose old and new are identical (no-op)', function () {
    $plan = makeValidatorPlan([
        'filesToChange' => ['src/file.txt'],
        'changes' => [
            [
                'file' => 'src/file.txt',
                'old' => 'foo',
                'new' => 'foo',
                'reason' => 'wastes a round',
            ],
        ],
    ]);

    $errors = (new PlanValidatorService)->validate($plan, defaultValidatorProfile());

    expect($errors)->toContain("changes[0] has identical 'old' and 'new' (no-op)");
});

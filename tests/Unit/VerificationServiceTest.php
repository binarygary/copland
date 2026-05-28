<?php

use App\Data\ExecutionResult;
use App\Data\PlanResult;
use App\Services\GitService;
use App\Services\VerificationService;

function makeVerifyPlan(): PlanResult
{
    return new PlanResult(
        decision: 'accept',
        branchName: 'agent/test',
        filesToRead: [],
        filesToChange: [],
        blockedWritePaths: [],
        steps: [],
        commandsToRun: [],
        testsToUpdate: [],
        successCriteria: [],
        guardrails: [],
        prTitle: null,
        prBody: null,
        maxFilesChanged: 3,
        maxLinesChanged: 250,
        declineReason: null,
    );
}

function makeVerifyExecutionResult(bool $success = true, string $summary = 'ok'): ExecutionResult
{
    return new ExecutionResult(
        success: $success,
        summary: $summary,
        toolCallLog: [],
        toolCallCount: 0,
        durationSeconds: 0.0,
    );
}

it('fails when the executor produces no file changes', function () {
    $git = new GitService(function (array $command, string $cwd): array {
        return match ($command) {
            ['git', 'status', '--porcelain'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    $verifier = new VerificationService($git);
    $result = $verifier->verify([], '/tmp/repo', makeVerifyPlan(), makeVerifyExecutionResult());

    expect($result->passed)->toBeFalse();
    expect($result->failures)->not->toBeEmpty();

    $joined = implode("\n", $result->failures);
    expect($joined)->toContain('Executor produced no file changes');
});

it('passes when the executor produces in-bound file changes', function () {
    $git = new GitService(function (array $command, string $cwd): array {
        return match ($command) {
            ['git', 'status', '--porcelain'] => ['stdout' => " M app/Foo.php\n", 'stderr' => '', 'exitCode' => 0],
            ['git', 'diff', '--stat', 'HEAD'] => ['stdout' => " app/Foo.php | 2 +-\n 1 file changed, 1 insertion(+), 1 deletion(-)\n", 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    $verifier = new VerificationService($git);
    $result = $verifier->verify([], '/tmp/repo', makeVerifyPlan(), makeVerifyExecutionResult());

    expect($result->passed)->toBeTrue();
    expect($result->failures)->toBe([]);
});

it('counts untracked files as changes (executor adds new files via writeFile)', function () {
    $git = new GitService(function (array $command, string $cwd): array {
        return match ($command) {
            ['git', 'status', '--porcelain'] => ['stdout' => "?? app/New.php\n", 'stderr' => '', 'exitCode' => 0],
            ['git', 'diff', '--stat', 'HEAD'] => ['stdout' => '', 'stderr' => '', 'exitCode' => 0],
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
        };
    });

    $verifier = new VerificationService($git);
    $result = $verifier->verify([], '/tmp/repo', makeVerifyPlan(), makeVerifyExecutionResult());

    expect($result->passed)->toBeTrue();
    expect($result->failures)->toBe([]);
});

it('short-circuits when execution itself failed', function () {
    $git = new GitService(function (array $command, string $cwd): array {
        throw new RuntimeException('Runner should not be invoked when execution failed: '.implode(' ', $command));
    });

    $verifier = new VerificationService($git);
    $result = $verifier->verify([], '/tmp/repo', makeVerifyPlan(), makeVerifyExecutionResult(success: false, summary: 'boom'));

    expect($result->passed)->toBeFalse();
    expect($result->failures)->toHaveCount(1);
    expect($result->failures[0])->toStartWith('Execution did not succeed');
});

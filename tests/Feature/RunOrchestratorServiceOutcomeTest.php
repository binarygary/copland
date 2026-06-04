<?php

use App\Data\ModelUsage;
use App\Services\RunOrchestratorService;

function invokeOutcomePayload(array $payload, string $startedAt = '2025-01-01T00:00:00+00:00'): array
{
    $service = Mockery::mock(RunOrchestratorService::class)->makePartial();

    $method = new ReflectionMethod(RunOrchestratorService::class, 'outcomePayload');
    $method->setAccessible(true);

    return $method->invoke($service, 'run-123', null, $payload, $startedAt, null);
}

function callOutcomePayload(float $cost): array
{
    return invokeOutcomePayload([
        'status' => 'succeeded',
        'pr' => ['number' => 42, 'url' => 'https://github.com/example/repo/pull/42'],
        'started_at' => '2025-01-01T00:00:00+00:00',
        'finished_at' => '2025-01-01T00:01:00+00:00',
        'usage' => [
            'total' => new ModelUsage('claude-sonnet', 100, 50, $cost),
        ],
    ]);
}

it('formats a very small cost without E-notation', function () {
    $outcome = callOutcomePayload(1.0e-8);

    expect($outcome['cost_usd'])->toBe('0.000000');
});

it('formats a normal cost with six decimal places', function () {
    $outcome = callOutcomePayload(0.0042);

    expect($outcome['cost_usd'])->toBe('0.004200');
});

it('falls back to current UTC and flags partial when started_at is garbled', function () {
    $before = gmdate('Y-m-d\TH:i:s\Z');
    $outcome = invokeOutcomePayload([
        'status' => 'succeeded',
        'pr' => ['number' => 1, 'url' => 'https://example.test/pr/1'],
        'started_at' => 'not-a-date',
        'finished_at' => '2025-01-01T00:01:00+00:00',
    ]);
    $after = gmdate('Y-m-d\TH:i:s\Z');

    // Bounded by now: the fallback timestamp must lie between just-before and just-after this call,
    // so a bug that silently records 1970-01-01T00:00:00Z would fail this assertion.
    expect(strcmp($outcome['started_at'], $before))->toBeGreaterThanOrEqual(0);
    expect(strcmp($outcome['started_at'], $after))->toBeLessThanOrEqual(0);
    expect($outcome['partial'])->toBe('true');
});

it('falls back to current UTC and flags partial when finished_at is garbled', function () {
    $before = gmdate('Y-m-d\TH:i:s\Z');
    $outcome = invokeOutcomePayload([
        'status' => 'succeeded',
        'pr' => ['number' => 1, 'url' => 'https://example.test/pr/1'],
        'started_at' => '2025-01-01T00:00:00+00:00',
        'finished_at' => 'also-not-a-date',
    ]);
    $after = gmdate('Y-m-d\TH:i:s\Z');

    // Same bounded check as the started_at test: a regression that returned some
    // wrong-but-not-epoch timestamp would have slipped through the loose "!= epoch"
    // assertion this replaces.
    expect(strcmp($outcome['finished_at'], $before))->toBeGreaterThanOrEqual(0);
    expect(strcmp($outcome['finished_at'], $after))->toBeLessThanOrEqual(0);
    expect($outcome['partial'])->toBe('true');
});

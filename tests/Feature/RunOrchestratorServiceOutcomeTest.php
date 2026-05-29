<?php

use App\Data\ModelUsage;
use App\Services\RunOrchestratorService;

function callOutcomePayload(float $cost): array
{
    $service = Mockery::mock(RunOrchestratorService::class)->makePartial();

    $method = new ReflectionMethod(RunOrchestratorService::class, 'outcomePayload');
    $method->setAccessible(true);

    $payload = [
        'status' => 'succeeded',
        'pr' => ['number' => 42, 'url' => 'https://github.com/example/repo/pull/42'],
        'started_at' => '2025-01-01T00:00:00+00:00',
        'finished_at' => '2025-01-01T00:01:00+00:00',
        'usage' => [
            'total' => new ModelUsage('claude-sonnet', 100, 50, $cost),
        ],
    ];

    return $method->invoke($service, 'run-123', null, $payload, '2025-01-01T00:00:00+00:00', null);
}

it('formats a very small cost without E-notation', function () {
    $outcome = callOutcomePayload(1.0e-8);

    expect($outcome['cost_usd'])->toBe('0.000000');
});

it('formats a normal cost with six decimal places', function () {
    $outcome = callOutcomePayload(0.0042);

    expect($outcome['cost_usd'])->toBe('0.004200');
});

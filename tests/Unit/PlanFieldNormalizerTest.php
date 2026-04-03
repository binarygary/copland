<?php

use App\Support\PlanFieldNormalizer;

it('normalizes mixed plan list items into strings', function () {
    $normalized = PlanFieldNormalizer::list([
        'resources/js/repo.ts',
        ['path' => 'resources/js/issues.ts'],
        ['command' => 'npm test'],
        ['step' => 'Update the toggle logic'],
        ['description' => 'Add a regression test'],
        ['unexpected' => 'value'],
    ]);

    expect($normalized)->toBe([
        'resources/js/repo.ts',
        'resources/js/issues.ts',
        'npm test',
        'Update the toggle logic',
        'Add a regression test',
        '{"unexpected":"value"}',
    ]);
});

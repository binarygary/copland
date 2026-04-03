<?php

use App\Support\ProgressReporter;

it('formats numbered progress steps and details', function () {
    $reporter = new ProgressReporter(totalSteps: 3);

    expect($reporter->step('Resolve repository'))->toBe('[1/3] Resolve repository');
    expect($reporter->step('Load configuration'))->toBe('[2/3] Load configuration');
    expect($reporter->detail('Using inferred repo Lone-Rock-Point/lrpbot'))->toBe('      Using inferred repo Lone-Rock-Point/lrpbot');
});

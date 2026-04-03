<?php

use App\Commands\RunCommand;
use App\Data\ModelUsage;

it('formats the visible usage summary lines for run output', function () {
    $lines = RunCommand::usageSummaryLines(
        new ModelUsage('claude-haiku', 1_200, 100, 0.0037),
        new ModelUsage('claude-sonnet', 4_000, 900, 0.0255),
        new ModelUsage('claude-sonnet', 10_000, 2_000, 0.06),
        12.4,
    );

    expect($lines)->toContain('Usage:');
    expect($lines)->toContain('  - Selector: 1,200 input, 100 output, $0.0037 est.');
    expect($lines)->toContain('  - Planner: 4,000 input, 900 output, $0.0255 est.');
    expect($lines)->toContain('  - Executor: 10,000 input, 2,000 output, $0.0600 est.');
    expect($lines)->toContain('  - Total: 15,200 input, 3,000 output, $0.0892 est.');
    expect($lines)->toContain('  - Executor elapsed: 12s');
});

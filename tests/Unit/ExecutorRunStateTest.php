<?php

use App\Support\ExecutorRunState;

it('requires planned files to be attempted before directory exploration', function () {
    $state = new ExecutorRunState(['resources/js/Pages/Repos/Index.vue']);

    expect($state->canListDirectory())->toBeFalse();

    $state->recordSuccessfulTool('read_file', ['path' => 'resources/js/Pages/Repos/Index.vue']);

    expect($state->canListDirectory())->toBeTrue();
});

it('detects no-progress thrashing after several rounds', function () {
    $state = new ExecutorRunState([]);

    expect($state->shouldAbortForThrashing(4))->toBeNull();
    expect($state->shouldAbortForThrashing(5))
        ->toBe('Executor made no implementation progress after 5 rounds (no file writes or planned commands)');
});

it('tracks directory exploration budget separately from implementation progress', function () {
    $state = new ExecutorRunState([]);

    for ($i = 0; $i < 7; $i++) {
        $state->recordSuccessfulTool('list_directory', ['path' => 'resources']);
    }

    expect($state->shouldAbortForThrashing(2))
        ->toBe('Executor exceeded directory exploration budget (7 list_directory calls)');
});

it('does not report no-progress thrashing after a write or planned command', function () {
    $state = new ExecutorRunState([]);
    $state->recordSuccessfulTool('write_file', ['path' => 'resources/js/Pages/Repos/Index.vue']);

    expect($state->shouldAbortForThrashing(6))->toBeNull();
});

it('aborts repeated malformed write_file attempts quickly', function () {
    $state = new ExecutorRunState([]);

    $state->recordFailedTool('write_file', "Policy violation: Tool 'write_file' requires a non-empty string 'content' field");
    expect($state->shouldAbortForThrashing(2))->toBeNull();

    $state->recordFailedTool('write_file', "Policy violation: Tool 'write_file' requires a non-empty string 'content' field");
    expect($state->shouldAbortForThrashing(3))
        ->toBe('Executor repeated malformed write_file calls 2 times without content');
});

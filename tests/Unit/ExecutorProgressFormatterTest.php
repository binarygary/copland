<?php

use App\Support\ExecutorProgressFormatter;

it('formats detailed executor heartbeat messages', function () {
    expect(ExecutorProgressFormatter::waiting(round: 2, toolCalls: 5, elapsedSeconds: 17.4))
        ->toBe('      Claude round 2: waiting for response (5 tool calls so far, 17s elapsed)');

    expect(ExecutorProgressFormatter::response(round: 2, toolUses: 3, elapsedSeconds: 19.2))
        ->toBe('      Claude round 2: received response with 3 tool call(s) after 19s');

    expect(ExecutorProgressFormatter::tool(toolNumber: 6, name: 'read_file', target: 'resources/js/app.js'))
        ->toBe('      Tool 6: read_file(resources/js/app.js)');
});

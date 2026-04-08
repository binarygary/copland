<?php

use App\Support\LlmResponseNormalizer;

it('normalizes end_turn to stop', function () {
    expect(LlmResponseNormalizer::normalize('end_turn'))->toBe('stop');
});

it('normalizes tool_use to tool_calls', function () {
    expect(LlmResponseNormalizer::normalize('tool_use'))->toBe('tool_calls');
});

it('passes stop through unchanged', function () {
    expect(LlmResponseNormalizer::normalize('stop'))->toBe('stop');
});

it('passes tool_calls through unchanged', function () {
    expect(LlmResponseNormalizer::normalize('tool_calls'))->toBe('tool_calls');
});

it('passes max_tokens through unchanged', function () {
    expect(LlmResponseNormalizer::normalize('max_tokens'))->toBe('max_tokens');
});

it('passes unknown values through unchanged', function () {
    expect(LlmResponseNormalizer::normalize('some_unknown_value'))->toBe('some_unknown_value');
});

it('passes empty string through unchanged', function () {
    expect(LlmResponseNormalizer::normalize(''))->toBe('');
});

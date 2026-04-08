<?php

use App\Support\ToolSchemaTranslator;

it('translates a full Anthropic tool to OpenAI function format', function () {
    $tool = [
        'name' => 'read_file',
        'description' => 'Read a file in the workspace',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the file']],
            'required' => ['path'],
        ],
    ];

    $result = ToolSchemaTranslator::translate($tool);

    expect($result)->toBe([
        'type' => 'function',
        'function' => [
            'name' => 'read_file',
            'description' => 'Read a file in the workspace',
            'parameters' => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the file']],
                'required' => ['path'],
            ],
        ],
    ]);
});

it('omits description from output when absent in input', function () {
    $tool = [
        'name' => 'write_file',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']],
            'required' => ['path', 'content'],
        ],
    ];

    $result = ToolSchemaTranslator::translate($tool);

    expect($result)->toBe([
        'type' => 'function',
        'function' => [
            'name' => 'write_file',
            'parameters' => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']],
                'required' => ['path', 'content'],
            ],
        ],
    ]);
    expect(isset($result['function']['description']))->toBeFalse();
});

it('uses empty object schema when input_schema is absent', function () {
    $tool = [
        'name' => 'no_schema_tool',
    ];

    $result = ToolSchemaTranslator::translate($tool);

    expect($result['type'])->toBe('function');
    expect($result['function']['name'])->toBe('no_schema_tool');
    expect($result['function']['parameters'])->toBe(['type' => 'object', 'properties' => []]);
});

it('translates multiple tools via translateAll', function () {
    $tools = [
        [
            'name' => 'read_file',
            'description' => 'Read a file',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string']],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'write_file',
            'description' => 'Write a file',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']],
                'required' => ['path', 'content'],
            ],
        ],
    ];

    $results = ToolSchemaTranslator::translateAll($tools);

    expect($results)->toHaveCount(2);
    expect($results[0]['type'])->toBe('function');
    expect($results[0]['function']['name'])->toBe('read_file');
    expect($results[0]['function']['parameters'])->toHaveKey('type');
    expect($results[1]['function']['name'])->toBe('write_file');
    expect($results[1]['function']['parameters']['required'])->toBe(['path', 'content']);
});

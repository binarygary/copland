<?php

use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use App\Support\AnthropicMessageSerializer;

it('serializes assistant content blocks into valid input shapes', function () {
    $text = TextBlock::with(citations: null, text: 'Done');
    $text->parsed = ['summary' => 'should not be sent back'];

    $toolUse = ToolUseBlock::with(
        id: 'toolu_123',
        input: ['path' => 'app/Foo.php'],
        name: 'read_file',
    );

    expect(AnthropicMessageSerializer::assistantContent([$text, $toolUse]))->toBe([
        [
            'type' => 'text',
            'text' => 'Done',
            'citations' => null,
        ],
        [
            'type' => 'tool_use',
            'id' => 'toolu_123',
            'name' => 'read_file',
            'input' => ['path' => 'app/Foo.php'],
        ],
    ]);
});

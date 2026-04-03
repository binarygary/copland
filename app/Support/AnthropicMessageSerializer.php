<?php

namespace App\Support;

class AnthropicMessageSerializer
{
    public static function assistantContent(array $blocks): array
    {
        return array_map(
            fn (object $block): array => self::assistantBlock($block),
            $blocks,
        );
    }

    private static function assistantBlock(object $block): array
    {
        return match ($block->type) {
            'text' => [
                'type' => 'text',
                'text' => $block->text,
                'citations' => $block->citations ?? null,
            ],
            'tool_use' => [
                'type' => 'tool_use',
                'id' => $block->id,
                'name' => $block->name,
                'input' => (array) $block->input,
            ],
            'thinking' => [
                'type' => 'thinking',
                'thinking' => $block->thinking,
                'signature' => $block->signature,
            ],
            'redacted_thinking' => [
                'type' => 'redacted_thinking',
                'data' => $block->data,
            ],
            default => (array) $block,
        };
    }
}

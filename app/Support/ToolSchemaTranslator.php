<?php

namespace App\Support;

/**
 * Translates tool schema definitions from Anthropic format to OpenAI function format.
 *
 * Anthropic uses 'input_schema' as the parameter key; OpenAI-compatible providers
 * expect 'parameters' wrapped inside a 'function' object with type 'function'.
 * The schema contents (type, properties, required) pass through unchanged.
 */
final class ToolSchemaTranslator
{
    public static function translate(array $tool): array
    {
        $function = ['name' => $tool['name']];

        if (isset($tool['description'])) {
            $function['description'] = $tool['description'];
        }

        $function['parameters'] = $tool['input_schema'] ?? ['type' => 'object', 'properties' => []];

        return ['type' => 'function', 'function' => $function];
    }

    public static function translateAll(array $tools): array
    {
        return array_map([self::class, 'translate'], $tools);
    }
}

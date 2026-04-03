<?php

namespace App\Support;

class PlanFieldNormalizer
{
    public static function list(array $items): array
    {
        return array_map(
            fn (mixed $item): string => self::item($item),
            $items,
        );
    }

    public static function item(mixed $item): string
    {
        if (is_string($item) || is_numeric($item)) {
            return (string) $item;
        }

        if (! is_array($item)) {
            return json_encode($item) ?: '';
        }

        foreach (['path', 'file', 'command', 'step', 'description', 'title', 'name'] as $key) {
            if (isset($item[$key]) && (is_string($item[$key]) || is_numeric($item[$key]))) {
                return (string) $item[$key];
            }
        }

        return json_encode($item, JSON_UNESCAPED_SLASHES) ?: '';
    }
}

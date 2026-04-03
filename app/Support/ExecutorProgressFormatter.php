<?php

namespace App\Support;

class ExecutorProgressFormatter
{
    public static function waiting(int $round, int $toolCalls, float $elapsedSeconds): string
    {
        return sprintf(
            '      Claude round %d: waiting for response (%d tool calls so far, %ds elapsed)',
            $round,
            $toolCalls,
            (int) round($elapsedSeconds)
        );
    }

    public static function response(int $round, int $toolUses, float $elapsedSeconds): string
    {
        return sprintf(
            '      Claude round %d: received response with %d tool call(s) after %ds',
            $round,
            $toolUses,
            (int) round($elapsedSeconds)
        );
    }

    public static function tool(int $toolNumber, string $name, string $target): string
    {
        return sprintf('      Tool %d: %s(%s)', $toolNumber, $name, $target);
    }

    public static function toolError(int $toolNumber, string $message): string
    {
        return sprintf('      Tool %d error: %s', $toolNumber, $message);
    }
}

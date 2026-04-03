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

    public static function response(int $round, int $toolUses, float $elapsedSeconds, int $cacheWrite = 0, int $cacheRead = 0): string
    {
        $cacheInfo = '';
        if ($cacheWrite > 0 || $cacheRead > 0) {
            $cacheInfo = sprintf(' [cache: +%d, %d]', $cacheWrite, $cacheRead);
        }

        return sprintf(
            '      Claude round %d: received response with %d tool call(s) after %ds%s',
            $round,
            $toolUses,
            (int) round($elapsedSeconds),
            $cacheInfo
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

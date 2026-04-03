<?php

namespace App\Support;

use App\Data\ModelUsage;

class AnthropicCostEstimator
{
    public static function forModel(string $model, int $inputTokens, int $outputTokens, int $cacheWrite = 0, int $cacheRead = 0): ModelUsage
    {
        [$inputRate, $outputRate] = self::ratesForModel($model);

        // inputTokens includes cacheRead, but excludes cacheWrite
        $uncachedInput = $inputTokens - $cacheRead;
        $inputCost = ($uncachedInput / 1_000_000) * $inputRate;
        $writeCost = ($cacheWrite / 1_000_000) * ($inputRate * 1.25);
        $readCost = ($cacheRead / 1_000_000) * ($inputRate * 0.1);
        $outputCost = ($outputTokens / 1_000_000) * $outputRate;

        return new ModelUsage(
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            estimatedCostUsd: round($inputCost + $writeCost + $readCost + $outputCost, 6),
            cacheWriteTokens: $cacheWrite,
            cacheReadTokens: $cacheRead,
        );
    }

    public static function format(?ModelUsage $usage): string
    {
        if ($usage === null) {
            return 'n/a';
        }

        $cacheInfo = '';
        if ($usage->cacheWriteTokens > 0 || $usage->cacheReadTokens > 0) {
            $cacheInfo = sprintf(
                ' (+%s write, %s read)',
                number_format($usage->cacheWriteTokens),
                number_format($usage->cacheReadTokens)
            );
        }

        return sprintf(
            '%s input%s, %s output, $%0.4f est.',
            number_format($usage->inputTokens),
            $cacheInfo,
            number_format($usage->outputTokens),
            $usage->estimatedCostUsd
        );
    }

    public static function combine(?ModelUsage ...$usages): ?ModelUsage
    {
        $total = null;

        foreach ($usages as $usage) {
            if ($usage === null) {
                continue;
            }

            $total = $total === null ? $usage : $total->add($usage);
        }

        return $total;
    }

    private static function ratesForModel(string $model): array
    {
        $normalized = strtolower($model);

        if (str_contains($normalized, 'haiku-4-5')) {
            return [1.0, 5.0];
        }

        if (str_contains($normalized, 'haiku')) {
            return [0.8, 4.0];
        }

        if (str_contains($normalized, 'sonnet')) {
            return [3.0, 15.0];
        }

        if (str_contains($normalized, 'opus')) {
            return [15.0, 75.0];
        }

        return [3.0, 15.0];
    }
}

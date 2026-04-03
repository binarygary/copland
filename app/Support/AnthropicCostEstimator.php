<?php

namespace App\Support;

use App\Data\ModelUsage;

class AnthropicCostEstimator
{
    public static function forModel(string $model, int $inputTokens, int $outputTokens): ModelUsage
    {
        [$inputRate, $outputRate] = self::ratesForModel($model);

        $inputCost = ($inputTokens / 1_000_000) * $inputRate;
        $outputCost = ($outputTokens / 1_000_000) * $outputRate;

        return new ModelUsage(
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            estimatedCostUsd: round($inputCost + $outputCost, 6),
        );
    }

    public static function format(?ModelUsage $usage): string
    {
        if ($usage === null) {
            return 'n/a';
        }

        return sprintf(
            '%s input, %s output, $%0.4f est.',
            number_format($usage->inputTokens),
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

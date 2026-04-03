<?php

namespace App\Support;

use App\Data\ModelUsage;
use RuntimeException;

class RunLogStore
{
    public function append(array $payload): string
    {
        $path = $this->path();
        $this->ensureDirectoryExists(dirname($path));

        $json = json_encode($this->normalizeValue($payload), JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode run log payload as JSON.');
        }

        if (file_put_contents($path, $json.PHP_EOL, FILE_APPEND) === false) {
            throw new RuntimeException("Failed to append run log to {$path}");
        }

        return $path;
    }

    private function path(): string
    {
        return HomeDirectory::resolve().'/.copland/logs/runs.jsonl';
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create run log directory at {$directory}");
        }
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof ModelUsage) {
            return [
                'model' => $value->model,
                'input_tokens' => $value->inputTokens,
                'output_tokens' => $value->outputTokens,
                'estimated_cost_usd' => $value->estimatedCostUsd,
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeValue($item);
        }

        return $normalized;
    }
}

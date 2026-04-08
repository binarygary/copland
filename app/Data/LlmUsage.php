<?php

namespace App\Data;

final class LlmUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}
}

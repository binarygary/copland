<?php

namespace App\Contracts;

use App\Data\LlmResponse;
use App\Data\SystemBlock;

interface LlmClient
{
    /**
     * @param  array<array<string, mixed>>  $messages
     * @param  array<array<string, mixed>>  $tools
     * @param  SystemBlock[]  $systemBlocks
     */
    public function complete(
        string $model,
        int $maxTokens,
        array $messages,
        array $tools = [],
        array $systemBlocks = [],
    ): LlmResponse;
}

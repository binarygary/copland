<?php

namespace App\Data;

final class LlmResponse
{
    /**
     * @param  array<array<string, mixed>>  $content  Plain assoc arrays, e.g.
     *   ['type' => 'text', 'text' => '...'] or
     *   ['type' => 'tool_use', 'name' => '...', 'id' => '...', 'input' => [...]]
     */
    public function __construct(
        public readonly array $content,
        public readonly string $stopReason,
        public readonly LlmUsage $usage,
    ) {}
}

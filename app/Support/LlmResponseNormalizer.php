<?php

namespace App\Support;

/**
 * Normalizes LLM stop reason values to canonical form.
 *
 * Different providers use different stop reason strings. This class maps
 * provider-specific values to the canonical values used throughout Copland:
 * - 'stop' (end of response)
 * - 'tool_calls' (model wants to call a tool)
 *
 * Canonical values pass through unchanged so this is safe to call on already-normalized data.
 */
final class LlmResponseNormalizer
{
    public static function normalize(string $stopReason): string
    {
        return match ($stopReason) {
            'end_turn' => 'stop',
            'tool_use'  => 'tool_calls',
            default     => $stopReason,
        };
    }
}

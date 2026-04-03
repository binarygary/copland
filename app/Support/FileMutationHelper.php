<?php

namespace App\Support;

use App\Exceptions\PolicyViolationException;

class FileMutationHelper
{
    public static function replaceOnce(string $content, string $old, string $new): string
    {
        if ($old === '') {
            throw new PolicyViolationException("Tool 'replace_in_file' requires a non-empty string 'old' field");
        }

        $occurrences = substr_count($content, $old);

        if ($occurrences === 0) {
            throw new PolicyViolationException('replace_in_file could not find the requested old text');
        }

        if ($occurrences > 1) {
            throw new PolicyViolationException("replace_in_file matched {$occurrences} occurrences; provide a more specific old text");
        }

        return preg_replace('/'.preg_quote($old, '/').'/', $new, $content, 1) ?? $content;
    }
}

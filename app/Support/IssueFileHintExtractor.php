<?php

namespace App\Support;

class IssueFileHintExtractor
{
    public static function extract(array $issue): array
    {
        $text = trim(($issue['title'] ?? '')."\n".($issue['body'] ?? ''));

        if ($text === '') {
            return [];
        }

        preg_match_all('/(?<![A-Za-z0-9._-])([A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)+\.[A-Za-z0-9._-]+)(?![A-Za-z0-9._-])/', $text, $matches);

        $paths = array_values(array_unique(array_filter($matches[1], function (string $path): bool {
            return ! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://');
        })));

        return $paths;
    }
}

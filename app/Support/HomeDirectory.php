<?php

namespace App\Support;

use RuntimeException;

class HomeDirectory
{
    /**
     * Resolve the home directory using a fallback chain:
     * 1. $_SERVER['HOME']
     * 2. getenv('HOME')
     * 3. posix_getpwuid(posix_geteuid())['dir']
     *
     * @throws RuntimeException if all methods fail
     */
    public static function resolve(): string
    {
        $home = $_SERVER['HOME'] ?? null;
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pwinfo = posix_getpwuid(posix_geteuid());
            if (is_array($pwinfo) && isset($pwinfo['dir']) && $pwinfo['dir'] !== '') {
                return rtrim($pwinfo['dir'], '/');
            }
        }

        throw new RuntimeException(
            'Could not resolve HOME directory. Set $HOME or ensure posix extension is available.'
        );
    }
}

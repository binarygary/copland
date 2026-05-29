<?php

namespace App\Support;

/**
 * Resolve the preferred / legacy global config path WITHOUT instantiating
 * `App\Config\GlobalConfig` (whose ctor auto-creates a default file via
 * ensureExists()). Used by every Phase 24+ write subcommand for the
 * "file-missing" preflight check.
 */
class GlobalConfigPath
{
    /**
     * @return array{preferred:string, legacy:string}
     */
    public static function candidates(): array
    {
        $home = HomeDirectory::resolve();

        return [
            'preferred' => $home.'/.copland.yml',
            'legacy' => $home.'/.copland/config.yml',
        ];
    }

    /**
     * The path the user is actually writing — preferred wins; legacy used
     * only when it exists on disk and preferred does not.
     */
    public static function activePath(): string
    {
        $paths = self::candidates();

        if (file_exists($paths['preferred'])) {
            return $paths['preferred'];
        }

        if (file_exists($paths['legacy'])) {
            return $paths['legacy'];
        }

        return $paths['preferred'];
    }

    public static function exists(): bool
    {
        $paths = self::candidates();

        return file_exists($paths['preferred']) || file_exists($paths['legacy']);
    }
}

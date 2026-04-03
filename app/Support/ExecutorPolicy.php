<?php

namespace App\Support;

use App\Exceptions\PolicyViolationException;

class ExecutorPolicy
{
    public function __construct(
        private array $blockedPaths = [],
        private int $maxRounds = 12,
        private int $readFileMaxLines = 300,
    ) {}

    public function maxRounds(): int
    {
        return $this->maxRounds;
    }

    public function readFileMaxLines(): int
    {
        return $this->readFileMaxLines;
    }

    public function assertToolPathAllowed(string $path, string $tool): string
    {
        $normalized = $this->normalizePath($path);

        if ($this->isBlockedPath($normalized)) {
            throw new PolicyViolationException("Tool '{$tool}' cannot access blocked path '{$normalized}'");
        }

        return $normalized;
    }

    public function assertWritePathAllowed(string $path, array $allowedFilesToChange): string
    {
        $normalized = $this->assertToolPathAllowed($path, 'write_file');

        if (! in_array($normalized, $allowedFilesToChange, true)) {
            throw new PolicyViolationException("Write to '{$normalized}' not listed in files_to_change");
        }

        return $normalized;
    }

    public function assertWritePathNotBlocked(string $path, array $blockedWritePaths): string
    {
        $normalized = $this->normalizePath($path);
        $normalizedBlockedPaths = array_map(
            fn (string $blockedPath): string => $this->normalizePath($blockedPath),
            $blockedWritePaths
        );

        foreach ($normalizedBlockedPaths as $blockedPath) {
            if ($normalized === $blockedPath || str_starts_with($normalized, $blockedPath.'/')) {
                throw new PolicyViolationException("Write to '{$normalized}' blocked by blocked_write_paths");
            }
        }

        return $normalized;
    }

    public function assertCommandAllowed(string $command, array $allowedCommands): string
    {
        $normalized = trim($command);
        $normalizedAllowed = array_map(static fn (string $allowed): string => trim($allowed), $allowedCommands);

        if (! in_array($normalized, $normalizedAllowed, true)) {
            throw new PolicyViolationException("Command '{$normalized}' not in allowed list");
        }

        return $normalized;
    }

    public function visibleEntries(string $directory, array $entries): array
    {
        $normalizedDirectory = $this->normalizePath($directory);

        return array_values(array_filter($entries, function (string $entry) use ($normalizedDirectory): bool {
            $candidate = $normalizedDirectory === '.'
                ? $entry
                : $normalizedDirectory.'/'.$entry;

            return ! $this->isBlockedPath($candidate);
        }));
    }

    private function isBlockedPath(string $path): bool
    {
        foreach ($this->allBlockedPaths() as $blockedPath) {
            if ($path === $blockedPath || str_starts_with($path, $blockedPath.'/')) {
                return true;
            }
        }

        return false;
    }

    private function allBlockedPaths(): array
    {
        $blocked = ['.git'];

        foreach ($this->blockedPaths as $path) {
            $blocked[] = $this->normalizePath($path);
        }

        return array_values(array_unique($blocked));
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '.') {
            return '.';
        }

        if (str_starts_with($path, '/')) {
            throw new PolicyViolationException("Absolute paths are not allowed: '{$path}'");
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new PolicyViolationException("Path escapes workspace: '{$path}'");
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? '.' : implode('/', $segments);
    }
}

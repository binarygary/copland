<?php

namespace App\Support;

class ExecutorRunState
{
    private array $pendingPlannedReads;

    private int $listDirectoryCalls = 0;

    private bool $hasWrite = false;

    private bool $hasCommand = false;

    private int $malformedWriteCalls = 0;

    public function __construct(array $filesToRead)
    {
        $this->pendingPlannedReads = array_values(array_unique(array_filter($filesToRead, 'is_string')));
    }

    public function canListDirectory(): bool
    {
        return $this->pendingPlannedReads === [];
    }

    public function pendingPlannedReads(): array
    {
        return $this->pendingPlannedReads;
    }

    public function recordSuccessfulTool(string $name, array $input): void
    {
        if ($name === 'read_file' && isset($input['path']) && is_string($input['path'])) {
            $this->pendingPlannedReads = array_values(array_filter(
                $this->pendingPlannedReads,
                static fn (string $path): bool => $path !== $input['path']
            ));
        }

        if ($name === 'list_directory') {
            $this->listDirectoryCalls++;
        }

        if ($name === 'write_file') {
            $this->hasWrite = true;
        }

        if ($name === 'replace_in_file') {
            $this->hasWrite = true;
        }

        if ($name === 'run_command') {
            $this->hasCommand = true;
        }
    }

    public function recordFailedTool(string $name, string $error): void
    {
        if (
            $name === 'write_file' &&
            str_contains($error, "Tool 'write_file' requires a non-empty string 'content' field")
        ) {
            $this->malformedWriteCalls++;
        }
    }

    public function shouldAbortForThrashing(int $round): ?string
    {
        if ($this->malformedWriteCalls >= 2) {
            return "Executor repeated malformed write_file calls {$this->malformedWriteCalls} times without content";
        }

        if (! $this->hasWrite && ! $this->hasCommand && $round >= 5) {
            return 'Executor made no implementation progress after 5 rounds (no file writes or planned commands)';
        }

        if ($this->listDirectoryCalls > 6) {
            return "Executor exceeded directory exploration budget ({$this->listDirectoryCalls} list_directory calls)";
        }

        return null;
    }
}

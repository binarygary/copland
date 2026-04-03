<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class CurrentRepoGuardService
{
    public function __construct(
        private $originResolver = null,
    ) {}

    public function assertMatches(string $expectedRepo): void
    {
        $currentRepo = $this->currentRepo();

        if ($currentRepo !== $expectedRepo) {
            throw new RuntimeException(
                "Current checkout is {$currentRepo}, but you requested {$expectedRepo}. ".
                'Run this command from a local checkout of the requested repository.'
            );
        }
    }

    public function resolve(?string $requestedRepo): string
    {
        if ($requestedRepo === null || trim($requestedRepo) === '') {
            return $this->currentRepo();
        }

        $this->assertMatches($requestedRepo);

        return $requestedRepo;
    }

    private function currentRepo(): string
    {
        try {
            $origin = $this->originUrl();
            $repo = $this->parseRepo($origin);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                'Could not determine the current git remote. Run this command from a local git checkout with an origin remote.',
                previous: $e,
            );
        }

        if ($repo === null) {
            throw new RuntimeException(
                'Could not determine the current git remote. Run this command from a local git checkout with an origin remote.'
            );
        }

        return $repo;
    }

    private function originUrl(): string
    {
        if ($this->originResolver !== null) {
            return ($this->originResolver)();
        }

        $process = new Process(['git', 'remote', 'get-url', 'origin']);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'git remote get-url origin failed');
        }

        return trim($process->getOutput());
    }

    private function parseRepo(string $origin): ?string
    {
        $normalized = preg_replace('#\.git$#', '', trim($origin));

        if (preg_match('#github\.com[:/](?<owner>[^/]+)/(?<repo>[^/]+)$#', $normalized, $matches) === 1) {
            return $matches['owner'] . '/' . $matches['repo'];
        }

        return null;
    }
}

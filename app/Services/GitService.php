<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class GitService
{
    public function __construct(private $runner = null) {}

    public function fetch(string $repoPath): void
    {
        $this->run(['git', 'fetch', 'origin'], $repoPath, 'git fetch failed');
    }

    public function prepareExecutionBranch(string $repoPath, string $baseBranch, string $branch): void
    {
        if ($this->hasUncommittedChanges($repoPath)) {
            throw new RuntimeException('Working tree is dirty. Commit, stash, or discard local changes before running copland.');
        }

        $this->fetch($repoPath);
        $this->run(['git', 'switch', $baseBranch], $repoPath, "git switch failed for base branch '{$baseBranch}'");
        $this->run(['git', 'pull', '--ff-only', 'origin', $baseBranch], $repoPath, "git pull failed for base branch '{$baseBranch}'");

        if ($this->branchExists($repoPath, $branch)) {
            $this->run(['git', 'switch', $branch], $repoPath, "git switch failed for branch '{$branch}'");

            return;
        }

        $this->run(['git', 'switch', '-c', $branch], $repoPath, "git switch -c failed for branch '{$branch}'");
    }

    public function changedFiles(string $workspacePath): array
    {
        $output = $this->output(['git', 'status', '--porcelain'], $workspacePath, 'git status failed');

        $files = [];
        foreach (explode("\n", $output) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\?\?\s+\.copland\.yml$/', $line)) {
                continue;
            }
            $path = substr($line, 3);
            if (str_contains($path, ' -> ')) {
                [, $path] = explode(' -> ', $path, 2);
            }
            $files[] = $path;
        }

        return $files;
    }

    public function changedLineCount(string $workspacePath): int
    {
        $output = $this->output(['git', 'diff', '--stat', 'HEAD'], $workspacePath, 'git diff --stat failed');

        preg_match_all('/(\d+) insertion|(\d+) deletion/', $output, $matches);

        $total = 0;
        foreach ($matches[0] as $match) {
            preg_match('/(\d+)/', $match, $num);
            $total += (int) $num[1];
        }

        return $total;
    }

    public function commit(string $workspacePath, string $message): void
    {
        $this->run(['git', 'add', '-A'], $workspacePath, 'git add failed');
        $this->run(['git', 'commit', '-m', $message], $workspacePath, 'git commit failed');
    }

    public function push(string $workspacePath, string $branch): void
    {
        $this->run(
            ['git', 'push', 'origin', $branch],
            $workspacePath,
            "git push failed for branch '{$branch}'"
        );
    }

    public function hasCommitsAheadOfBase(string $workspacePath, string $baseBranch): bool
    {
        $output = $this->output(
            ['git', 'rev-list', '--count', "{$baseBranch}..HEAD"],
            $workspacePath,
            "git rev-list failed against base '{$baseBranch}'"
        );

        return (int) trim($output) > 0;
    }

    public function switchBranch(string $workspacePath, string $branch): void
    {
        $this->run(['git', 'switch', $branch], $workspacePath, "git switch failed for branch '{$branch}'");
    }

    public function deleteLocalBranch(string $workspacePath, string $branch): void
    {
        $this->run(['git', 'branch', '-D', $branch], $workspacePath, "git branch -D failed for branch '{$branch}'");
    }

    private function hasUncommittedChanges(string $repoPath): bool
    {
        $output = $this->output(['git', 'status', '--porcelain'], $repoPath, 'git status failed');

        $lines = array_filter(
            array_map('trim', explode("\n", $output)),
            fn (string $line): bool => $line !== ''
        );

        $meaningfulChanges = array_filter(
            $lines,
            fn (string $line): bool => ! preg_match('/^\?\?\s+\.copland\.yml$/', $line)
        );

        return ! empty($meaningfulChanges);
    }

    private function branchExists(string $repoPath, string $branch): bool
    {
        $result = $this->execute(['git', 'rev-parse', '--verify', $branch], $repoPath);

        return $result['exitCode'] === 0;
    }

    private function run(array $command, string $cwd, string $errorMessage): void
    {
        $result = $this->execute($command, $cwd);

        if ($result['exitCode'] !== 0) {
            $stderr = trim($result['stderr']);
            $detail = $stderr !== '' ? $stderr : trim($result['stdout']);

            throw new RuntimeException("{$errorMessage}: ".$detail);
        }
    }

    private function output(array $command, string $cwd, string $errorMessage): string
    {
        $result = $this->execute($command, $cwd);

        if ($result['exitCode'] !== 0) {
            throw new RuntimeException("{$errorMessage}: ".$result['stderr']);
        }

        return $result['stdout'];
    }

    private function execute(array $command, string $cwd): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($command, $cwd);
        }

        $process = new Process($command, $cwd);
        $process->run();

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? 1,
        ];
    }
}

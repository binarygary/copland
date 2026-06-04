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

    public function stageAll(string $workspacePath): void
    {
        // Exclude pathspec keeps `.copland.yml` out of staged content the same way
        // hasUncommittedChanges()/changedFiles() ignore it. Without this, a
        // repo-local `.copland.yml` that's currently untracked would slip into
        // the agent's PR via `git add -A` even though the dirty-tree guard let
        // the run proceed. (claude #58)
        $this->run(['git', 'add', '-A', '--', '.', ':(exclude).copland.yml'], $workspacePath, 'git add failed');
    }

    public function commit(string $workspacePath, string $message): void
    {
        // Caller is responsible for staging via stageAll() first. Splitting these
        // lets the orchestrator stage, then ask hasStagedDiffVsBase, then either
        // commit or skip cleanly — without ever calling `git commit` on an empty
        // index (which exits non-zero and used to be reported as a crash).
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

    public function hasStagedDiffVsBase(string $workspacePath, string $baseBranch): bool
    {
        // `git diff --cached --quiet <base>` exits 0 when the index matches base's
        // tree and 1 when there's a diff. We compare content, not commit count —
        // rev-list-based checks fire green even when the committed tree happens
        // to be identical to base (revert chain, --allow-empty, normalization
        // wiped the diff), letting the PR API reject the push with 422.
        $result = $this->execute(
            ['git', 'diff', '--cached', '--quiet', $baseBranch],
            $workspacePath,
        );

        // 0 == match, 1 == diff. Anything else (128 for "unknown revision",
        // 129 for unknown flag, etc.) is a real git error. Treating those as
        // "no diff" would have the orchestrator silently strip the agent-ready
        // label, delete the local branch, and post a misleading "Executor
        // produced no changes" comment — masking a typo in base_branch or a
        // base ref that isn't checked out locally.
        if ($result['exitCode'] !== 0 && $result['exitCode'] !== 1) {
            $stderr = trim($result['stderr']);
            $detail = $stderr !== '' ? $stderr : trim($result['stdout']);

            throw new RuntimeException(
                "git diff --cached --quiet '{$baseBranch}' failed (exit {$result['exitCode']}): {$detail}"
            );
        }

        return $result['exitCode'] === 1;
    }

    public function resetHard(string $workspacePath): void
    {
        $this->run(['git', 'reset', '--hard', 'HEAD'], $workspacePath, 'git reset --hard failed');
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

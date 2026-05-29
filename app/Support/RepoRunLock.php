<?php

namespace App\Support;

/**
 * Per-repo run lock. Because Copland runs directly in the repo's live checkout
 * (no isolated worktree), only one run may touch a given repo at a time —
 * otherwise concurrent branch switches would clobber each other. This serializes
 * runs per repo using an OS-level advisory lock (flock), so a crashed run
 * releases automatically and can never wedge a repo permanently.
 */
class RepoRunLock
{
    /** @var resource|null Held for the lifetime of the lock; closing it releases. */
    private $handle = null;

    public function __construct(private ?string $homeOverride = null) {}

    /**
     * Try to take the lock for $repoSlug without blocking. Returns true when the
     * caller now owns it (until release()), false when another live run holds it.
     */
    public function acquire(string $repoSlug): bool
    {
        if ($this->handle !== null) {
            return true; // already held by this instance
        }

        $path = $this->lockPath($repoSlug);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // Record the holder for anyone inspecting the lock file by hand.
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf("pid: %d\nacquired_at: %s\n", getmypid(), gmdate('c')));
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    /**
     * Release a previously acquired lock. Safe to call when nothing is held.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    private function lockPath(string $repoSlug): string
    {
        $home = $this->homeOverride ?? HomeDirectory::resolve();
        $name = str_replace('/', '__', $repoSlug);

        return "{$home}/.copland/locks/{$name}.lock";
    }
}

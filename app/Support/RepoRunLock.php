<?php

namespace App\Support;

use LogicException;
use RuntimeException;

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

    private ?string $lockedSlug = null;

    public function __construct(private ?string $homeOverride = null) {}

    /**
     * Try to take the lock for $repoSlug without blocking.
     *
     * Returns true when the caller now owns it (until release()), false ONLY
     * when another live run already holds it (genuine contention). A failure to
     * create or open the lock file is an environmental error, not contention, so
     * it throws — otherwise every run would silently skip with a misleading
     * "already in progress" and mask the real problem (e.g. unwritable home).
     */
    public function acquire(string $repoSlug): bool
    {
        if ($this->handle !== null) {
            if ($repoSlug === $this->lockedSlug) {
                return true; // idempotent re-acquire of the same repo
            }

            throw new LogicException(
                "RepoRunLock already holds '{$this->lockedSlug}'; cannot acquire '{$repoSlug}' on the same instance."
            );
        }

        $path = $this->lockPath($repoSlug);
        $dir = dirname($path);
        // Tolerate a concurrent mkdir (another run creating the dir) by
        // re-checking is_dir after a failed mkdir.
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create lock directory: {$dir}");
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Could not open lock file: {$path}");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false; // genuinely held by another run
        }

        // Record the holder for anyone inspecting the lock file by hand.
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf("pid: %d\nacquired_at: %s\n", getmypid(), gmdate('c')));
        fflush($handle);

        $this->handle = $handle;
        $this->lockedSlug = $repoSlug;

        return true;
    }

    /**
     * Release a previously acquired lock. Safe to call when nothing is held.
     *
     * The lock file is intentionally left on disk: unlinking on release races
     * with other processes that may already hold the inode open, which would
     * break mutual exclusion. flock works fine on a reused file.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        $this->lockedSlug = null;
    }

    private function lockPath(string $repoSlug): string
    {
        $home = $this->homeOverride ?? HomeDirectory::resolve();
        $name = str_replace('/', '__', $repoSlug);

        return "{$home}/.copland/locks/{$name}.lock";
    }
}

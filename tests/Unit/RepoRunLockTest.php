<?php

use App\Support\RepoRunLock;

function repoLockRmTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path.'/'.$entry;
        is_dir($child) ? repoLockRmTree($child) : unlink($child);
    }

    rmdir($path);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/copland-lock-*') ?: [] as $dir) {
        repoLockRmTree($dir);
    }
});

it('grants the lock to the first holder and denies a concurrent second holder', function () {
    $home = sys_get_temp_dir().'/copland-lock-'.uniqid();
    mkdir($home, 0700, true);

    $a = new RepoRunLock($home);
    $b = new RepoRunLock($home);

    expect($a->acquire('acme/repo'))->toBeTrue();
    expect($b->acquire('acme/repo'))->toBeFalse();

    // Once the first holder releases, the lock is free to take.
    $a->release();
    expect($b->acquire('acme/repo'))->toBeTrue();

    $b->release();
});

it('throws on a real filesystem error instead of reporting false contention', function () {
    // Point HOME at a path under a regular file, so mkdir of the locks dir fails
    // — a genuine setup error, which must surface rather than look like a busy repo.
    $file = sys_get_temp_dir().'/copland-lock-notadir-'.uniqid();
    file_put_contents($file, 'x');

    // Swallow the native mkdir "Not a directory" warning (production suppresses
    // it with @, but PHPUnit's error handler still surfaces it) so we assert the
    // thrown exception cleanly.
    set_error_handler(fn (): bool => true);

    try {
        $lock = new RepoRunLock($file); // $file/.copland/locks/... — parent is a file
        expect(fn () => $lock->acquire('acme/repo'))->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
        @unlink($file);
    }
});

it('throws when the same instance is re-acquired for a different repo', function () {
    $home = sys_get_temp_dir().'/copland-lock-'.uniqid();
    mkdir($home, 0700, true);

    $lock = new RepoRunLock($home);
    expect($lock->acquire('acme/repo'))->toBeTrue();
    expect($lock->acquire('acme/repo'))->toBeTrue();         // same slug → idempotent
    expect(fn () => $lock->acquire('acme/other'))->toThrow(LogicException::class);

    $lock->release();
});

it('isolates locks per repo slug', function () {
    $home = sys_get_temp_dir().'/copland-lock-'.uniqid();
    mkdir($home, 0700, true);

    $one = new RepoRunLock($home);
    $two = new RepoRunLock($home);

    // Different repos do not contend.
    expect($one->acquire('acme/repo'))->toBeTrue();
    expect($two->acquire('acme/other'))->toBeTrue();

    $one->release();
    $two->release();
});

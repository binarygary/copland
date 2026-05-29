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

<?php

use App\Exceptions\PolicyViolationException;
use App\Support\ExecutorPolicy;

it('blocks git metadata access and path traversal', function () {
    $policy = new ExecutorPolicy();

    expect(fn () => $policy->assertToolPathAllowed('.git/HEAD', 'read_file'))
        ->toThrow(PolicyViolationException::class);

    expect(fn () => $policy->assertToolPathAllowed('../.env', 'read_file'))
        ->toThrow(PolicyViolationException::class);
});

it('requires write targets to be listed in files_to_change', function () {
    $policy = new ExecutorPolicy();

    expect($policy->assertWritePathAllowed('resources/views/example.blade.php', [
        'resources/views/example.blade.php',
    ]))->toBe('resources/views/example.blade.php');

    expect(fn () => $policy->assertWritePathAllowed('resources/views/other.blade.php', [
        'resources/views/example.blade.php',
    ]))->toThrow(PolicyViolationException::class);
});

it('requires exact command matches against the plan', function () {
    $policy = new ExecutorPolicy();

    expect($policy->assertCommandAllowed('./vendor/bin/pest', [
        './vendor/bin/pest',
    ]))->toBe('./vendor/bin/pest');

    expect(fn () => $policy->assertCommandAllowed('git log --oneline -10', [
        'git log',
    ]))->toThrow(PolicyViolationException::class);
});

it('filters blocked entries from directory listings', function () {
    $policy = new ExecutorPolicy(blockedPaths: ['database/migrations']);

    expect($policy->visibleEntries('.', ['.git', 'app', 'database']))
        ->toBe(['app', 'database']);

    expect($policy->visibleEntries('database', ['migrations', 'seeders']))
        ->toBe(['seeders']);
});

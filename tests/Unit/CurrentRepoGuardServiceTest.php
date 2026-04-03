<?php

use App\Services\CurrentRepoGuardService;

it('accepts matching github https remotes', function () {
    $guard = new CurrentRepoGuardService(
        fn (): string => 'https://github.com/Lone-Rock-Point/lrpbot.git',
    );

    expect(fn () => $guard->assertMatches('Lone-Rock-Point/lrpbot'))->not->toThrow(RuntimeException::class);
    expect($guard->resolve(null))->toBe('Lone-Rock-Point/lrpbot');
});

it('accepts matching github ssh remotes', function () {
    $guard = new CurrentRepoGuardService(
        fn (): string => 'git@github.com:Lone-Rock-Point/lrpbot.git',
    );

    expect(fn () => $guard->assertMatches('Lone-Rock-Point/lrpbot'))->not->toThrow(RuntimeException::class);
});

it('fails when the current checkout does not match the requested repo', function () {
    $guard = new CurrentRepoGuardService(
        fn (): string => 'git@github.com:binarygary/copland.git',
    );

    expect(fn () => $guard->assertMatches('Lone-Rock-Point/lrpbot'))
        ->toThrow(RuntimeException::class, 'Current checkout is binarygary/copland, but you requested Lone-Rock-Point/lrpbot.');
});

it('returns the explicit repo when it matches the current checkout', function () {
    $guard = new CurrentRepoGuardService(
        fn (): string => 'git@github.com:Lone-Rock-Point/lrpbot.git',
    );

    expect($guard->resolve('Lone-Rock-Point/lrpbot'))->toBe('Lone-Rock-Point/lrpbot');
});

it('fails with a clear message when origin cannot be resolved', function () {
    $guard = new CurrentRepoGuardService(
        function (): string {
            throw new RuntimeException('git remote get-url origin failed');
        },
    );

    expect(fn () => $guard->assertMatches('Lone-Rock-Point/lrpbot'))
        ->toThrow(RuntimeException::class, 'Could not determine the current git remote.');
});

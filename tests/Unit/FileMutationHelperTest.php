<?php

use App\Exceptions\PolicyViolationException;
use App\Support\FileMutationHelper;

it('replaces a single exact match once', function () {
    $updated = FileMutationHelper::replaceOnce(
        "<div>\n  old\n</div>\n",
        "  old\n",
        "  new\n"
    );

    expect($updated)->toBe("<div>\n  new\n</div>\n");
});

it('fails when the target text is missing', function () {
    expect(fn () => FileMutationHelper::replaceOnce('hello', 'missing', 'new'))
        ->toThrow(PolicyViolationException::class, 'could not find');
});

it('fails when the target text is ambiguous', function () {
    expect(fn () => FileMutationHelper::replaceOnce("x\nx\n", "x\n", "y\n"))
        ->toThrow(PolicyViolationException::class, 'matched 2 occurrences');
});

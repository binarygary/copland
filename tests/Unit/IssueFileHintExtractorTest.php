<?php

use App\Support\IssueFileHintExtractor;

it('extracts repo file paths from issue title and body without relying on a fixed label', function () {
    $paths = IssueFileHintExtractor::extract([
        'title' => 'Fix toggle in resources/views/components/repos/table.blade.php',
        'body' => <<<TEXT
Bug occurs at /repos view

Affected component:
resources/views/components/repos/table.blade.php

Also inspect tests/Feature/Livewire/Repos/TableTest.php
TEXT,
    ]);

    expect($paths)->toBe([
        'resources/views/components/repos/table.blade.php',
        'tests/Feature/Livewire/Repos/TableTest.php',
    ]);
});

it('ignores plain routes and urls that are not repo file paths', function () {
    $paths = IssueFileHintExtractor::extract([
        'title' => 'Bug on /repos',
        'body' => 'See https://example.com/repos and `/repos`.',
    ]);

    expect($paths)->toBe([]);
});

<?php

use App\Support\YamlBlockEditor;

/**
 * Unit-level guarantees for the scoped-block YAML editor that backs all
 * Phase 24 (and forward Phases 25 / 26) write subcommands.
 *
 * Each test creates its own tmp file under sys_get_temp_dir() and cleans up
 * after itself via afterEach() — no shared fixture state.
 */
function yamlBlockEditorTmpFile(string $contents): string
{
    $path = sys_get_temp_dir().'/copland-yamlblock-'.uniqid().'.yml';
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/copland-yamlblock-*.yml') as $stale) {
        @unlink($stale);
    }
});

it('Test 1: round-trips a repos block with no comments', function () {
    $original = "claude_api_key: \"\"\nrepos:\n  - slug: owner/a\n    path: /tmp/a\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $parsed = $editor->readBlock('repos');
    expect($parsed)->toBe([['slug' => 'owner/a', 'path' => '/tmp/a']]);

    $editor->writeBlock('repos', $parsed);
    $after = file_get_contents($path);

    // Round-trip should still parse to the same value.
    $editor2 = new YamlBlockEditor($path);
    expect($editor2->readBlock('repos'))->toBe($parsed);
    // The non-repos prefix must survive byte-for-byte.
    expect(str_starts_with($after, "claude_api_key: \"\"\n"))->toBeTrue();
});

it('Test 2: preserves out-of-block comment regions byte-for-byte', function () {
    $original = "# comment-A\n\nrepos:\n  - slug: a/b\n    path: /x\n\n# comment-B\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $editor->writeBlock('repos', [['slug' => 'c/d', 'path' => '/y']]);

    $result = file_get_contents($path);

    // Substring guarantees from the plan.
    expect(str_contains($result, "# comment-A\n\nrepos:"))->toBeTrue();
    expect(str_contains($result, "\n\n# comment-B\n"))->toBeTrue();

    // Stronger guarantee: capture pre/post-block regions and compare byte-for-byte.
    $preBeforeEnd = strpos($original, 'repos:');
    $preBefore = substr($original, 0, $preBeforeEnd);

    $postBeforeStart = strpos($original, "\n\n# comment-B");
    $postBefore = substr($original, $postBeforeStart);

    $preAfterEnd = strpos($result, 'repos:');
    $preAfter = substr($result, 0, $preAfterEnd);

    $postAfterStart = strpos($result, "\n\n# comment-B");
    $postAfter = substr($result, $postAfterStart);

    expect($preAfter)->toBe($preBefore);
    expect($postAfter)->toBe($postBefore);
});

it('Test 3: documents in-block comment loss', function () {
    $original = "repos:\n  - slug: a/b\n    path: /x\n  # in-block comment\n  - slug: c/d\n    path: /y\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $editor->writeBlock('repos', [['slug' => 'e/f', 'path' => '/z']]);

    $result = file_get_contents($path);

    expect(str_contains($result, '# in-block comment'))->toBeFalse();
});

it('Test 4: appends a missing block at end of file', function () {
    $original = "claude_api_key: \"\"\ndefaults:\n  max_files_changed: 3\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    expect($editor->readBlock('repos'))->toBeNull();

    $editor->writeBlock('repos', [['slug' => 'a/b', 'path' => '/x']]);

    $result = file_get_contents($path);

    // The original prefix survives unchanged.
    expect(str_starts_with($result, $original))->toBeTrue();
    // The appended block is present.
    expect(str_contains($result, 'repos:'))->toBeTrue();
    expect(str_contains($result, 'slug: a/b'))->toBeTrue();
    // File ends with exactly one newline.
    expect(substr($result, -1))->toBe("\n");
});

it('Test 5: handles a block at start-of-file without leading blank-line damage', function () {
    $original = "repos:\n  - slug: a/b\n    path: /x\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $editor->writeBlock('repos', [['slug' => 'c/d', 'path' => '/y']]);

    $result = file_get_contents($path);

    // First char is still `r` (no spurious leading whitespace/blank line).
    expect($result[0])->toBe('r');
    expect(str_starts_with($result, 'repos:'))->toBeTrue();
});

it('Test 6: ensures exactly one trailing newline when the input lacks one', function () {
    $original = "claude_api_key: \"\"\nrepos:\n  - slug: a/b\n    path: /x"; // no \n
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $editor->writeBlock('repos', [['slug' => 'c/d', 'path' => '/y']]);

    $result = file_get_contents($path);

    // Exactly one trailing newline.
    expect(substr($result, -1))->toBe("\n");
    expect(substr($result, -2, 1))->not->toBe("\n");
});

it('Test 7a: preserves CRLF line endings end-to-end', function () {
    $original = "# comment-A\r\n\r\nrepos:\r\n  - slug: a/b\r\n    path: /x\r\n\r\n# comment-B\r\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $parsed = $editor->readBlock('repos');
    expect($parsed)->toBe([['slug' => 'a/b', 'path' => '/x']]);

    $editor->writeBlock('repos', [['slug' => 'c/d', 'path' => '/y']]);

    $result = file_get_contents($path);

    $crlfCount = substr_count($result, "\r\n");
    $bareLfCount = substr_count($result, "\n") - $crlfCount;
    expect($crlfCount)->toBeGreaterThan($bareLfCount);

    // Out-of-block comment survives with CRLF.
    expect(str_contains($result, "# comment-A\r\n"))->toBeTrue();
});

it('Test 7b: keeps LF-dominant files free of CR bytes', function () {
    $original = "# comment-A\n\nrepos:\n  - slug: a/b\n    path: /x\n\n# comment-B\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $editor->writeBlock('repos', [['slug' => 'c/d', 'path' => '/y']]);

    $result = file_get_contents($path);

    expect(str_contains($result, "\r"))->toBeFalse();
});

it('Test 8: deleteBlock removes the block entirely', function () {
    $original = "claude_api_key: \"\"\nrepos:\n  - slug: a/b\n    path: /x\ndefaults:\n  max_files_changed: 3\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $editor->deleteBlock('repos');

    $result = file_get_contents($path);

    expect(str_contains($result, 'repos:'))->toBeFalse();
    expect(str_contains($result, 'slug: a/b'))->toBeFalse();
    expect(str_contains($result, 'claude_api_key:'))->toBeTrue();
    expect(str_contains($result, 'defaults:'))->toBeTrue();
});

it('Test 9: readBlock returns null when the key is absent', function () {
    $original = "claude_api_key: \"\"\ndefaults:\n  max_files_changed: 3\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    expect($editor->readBlock('repos'))->toBeNull();
});

it('Test 10: ignores a commented-out # repos: example and rewrites only the real block', function () {
    $original = "# repos:\n#   - slug: example/owner\n#     path: /tmp/example\n\nclaude_api_key: \"\"\n\nrepos:\n  - slug: real/repo\n    path: /tmp/real\n";
    $path = yamlBlockEditorTmpFile($original);

    $editor = new YamlBlockEditor($path);
    $editor->writeBlock('repos', [['slug' => 'new/one', 'path' => '/tmp/new']]);

    $result = file_get_contents($path);

    // The commented-out example survives byte-for-byte.
    expect(str_contains($result, "# repos:\n#   - slug: example/owner\n#     path: /tmp/example\n"))->toBeTrue();

    // The real block was rewritten.
    expect(str_contains($result, 'new/one'))->toBeTrue();
    expect(str_contains($result, 'real/repo'))->toBeFalse();
});

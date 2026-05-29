<?php

use App\Commands\ConfigReposAddCommand;
use App\Commands\ConfigReposEditCommand;
use App\Commands\ConfigReposRemoveCommand;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The load-bearing claim of Phase 24's YAML mutation strategy: comments
 * OUTSIDE the repos: block survive byte-for-byte across every write
 * subcommand, while comments INSIDE the block are dropped (the documented
 * trade-off — D-01).
 *
 * Task 1 covers the helper-level guarantee on YamlBlockEditor directly.
 * This file exercises the SAME guarantee end-to-end through all three
 * subcommand classes via CommandTester, so a future regression where a
 * subcommand bypasses YamlBlockEditor would still trip a failure.
 */
function setupCommentPreservationHome(): array
{
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-config-comment-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    // Provision the directories the fixture references (keep-me, leave-me)
    // plus the ones the test cases will need to use as --path arguments.
    foreach (['keep-me', 'leave-me', 'new-keep-me', 'new-add'] as $dir) {
        mkdir($home.'/'.$dir, 0755, true);
    }

    $fixturePath = __DIR__.'/../fixtures/config/repos-comment-preservation.yml';
    $fixture = file_get_contents($fixturePath);
    $contents = str_replace('HOME_PLACEHOLDER', $home, $fixture);
    file_put_contents($home.'/.copland.yml', $contents);

    $cleanup = function () use ($home, $originalHome): void {
        if (is_dir($home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $node) {
                $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
            }
            rmdir($home);
        }
        $_SERVER['HOME'] = $originalHome;
    };

    return [$home, $contents, $cleanup];
}

function commentPreservationRegions(string $contents): array
{
    $reposStart = strpos($contents, 'repos:');
    if ($reposStart === false) {
        throw new RuntimeException('Fixture missing repos: header');
    }
    $defaultsStart = strpos($contents, "\ndefaults:");
    if ($defaultsStart === false) {
        // Pre-defaults blank line may have moved; try without leading \n.
        $defaultsStart = strpos($contents, 'defaults:');
        // Find the \n preceding it so the post-region includes the blank-line glue.
        if ($defaultsStart !== false) {
            $defaultsStart = strrpos(substr($contents, 0, $defaultsStart), "\n");
        }
    }
    if ($defaultsStart === false) {
        throw new RuntimeException('Fixture missing defaults: section');
    }

    return [
        'pre' => substr($contents, 0, $reposStart),
        'post' => substr($contents, $defaultsStart),
    ];
}

function runConfigCommand(Command $command, array $args): CommandTester
{
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->execute($args, ['capture_stderr_separately' => true]);

    return $tester;
}

it('Test 1: add — out-of-block regions byte-equivalent after write', function () {
    [$home, $original, $cleanup] = setupCommentPreservationHome();
    $originalRegions = commentPreservationRegions($original);

    $tester = runConfigCommand(new ConfigReposAddCommand, [
        '--slug' => 'owner/new-add',
        '--path' => $home.'/new-add',
    ]);
    expect($tester->getStatusCode())->toBe(0);

    $after = file_get_contents($home.'/.copland.yml');
    $afterRegions = commentPreservationRegions($after);

    expect($afterRegions['pre'])->toBe($originalRegions['pre']);
    expect($afterRegions['post'])->toBe($originalRegions['post']);

    $cleanup();
});

it('Test 2: edit — out-of-block regions byte-equivalent after write', function () {
    [$home, $original, $cleanup] = setupCommentPreservationHome();
    $originalRegions = commentPreservationRegions($original);

    $tester = runConfigCommand(new ConfigReposEditCommand, [
        '--slug' => 'owner/keep-me',
        '--path' => $home.'/new-keep-me',
    ]);
    expect($tester->getStatusCode())->toBe(0);

    $after = file_get_contents($home.'/.copland.yml');
    $afterRegions = commentPreservationRegions($after);

    expect($afterRegions['pre'])->toBe($originalRegions['pre']);
    expect($afterRegions['post'])->toBe($originalRegions['post']);

    $cleanup();
});

it('Test 3: remove — out-of-block regions byte-equivalent after write', function () {
    [$home, $original, $cleanup] = setupCommentPreservationHome();
    $originalRegions = commentPreservationRegions($original);

    $tester = runConfigCommand(new ConfigReposRemoveCommand, [
        '--slug' => 'owner/leave-me',
    ]);
    expect($tester->getStatusCode())->toBe(0);

    $after = file_get_contents($home.'/.copland.yml');
    $afterRegions = commentPreservationRegions($after);

    expect($afterRegions['pre'])->toBe($originalRegions['pre']);
    expect($afterRegions['post'])->toBe($originalRegions['post']);

    $cleanup();
});

it('Test 4: in-block comments are dropped (documented trade-off)', function () {
    [$home, , $cleanup] = setupCommentPreservationHome();

    $tester = runConfigCommand(new ConfigReposEditCommand, [
        '--slug' => 'owner/keep-me',
        '--path' => $home.'/new-keep-me',
    ]);
    expect($tester->getStatusCode())->toBe(0);

    $after = file_get_contents($home.'/.copland.yml');

    // In-block comments are NOT preserved — pinning the documented trade-off.
    expect($after)->not->toContain('# In-block comment');

    // But the OUT-of-block comments still survive.
    expect($after)->toContain('# Top-of-file comment');
    expect($after)->toContain('# Pre-repos comment');
    expect($after)->toContain('# Post-repos comment');
    expect($after)->toContain('# Trailing comment');

    $cleanup();
});

it('Test 5: compound add → edit → remove — regions still byte-equivalent to original', function () {
    [$home, $original, $cleanup] = setupCommentPreservationHome();
    $originalRegions = commentPreservationRegions($original);

    $t1 = runConfigCommand(new ConfigReposAddCommand, [
        '--slug' => 'owner/new-add',
        '--path' => $home.'/new-add',
    ]);
    expect($t1->getStatusCode())->toBe(0);

    $t2 = runConfigCommand(new ConfigReposEditCommand, [
        '--slug' => 'owner/keep-me',
        '--path' => $home.'/new-keep-me',
    ]);
    expect($t2->getStatusCode())->toBe(0);

    $t3 = runConfigCommand(new ConfigReposRemoveCommand, [
        '--slug' => 'owner/leave-me',
    ]);
    expect($t3->getStatusCode())->toBe(0);

    $after = file_get_contents($home.'/.copland.yml');
    $afterRegions = commentPreservationRegions($after);

    expect($afterRegions['pre'])->toBe($originalRegions['pre']);
    expect($afterRegions['post'])->toBe($originalRegions['post']);

    $cleanup();
});

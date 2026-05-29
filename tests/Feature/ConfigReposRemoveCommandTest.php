<?php

use App\Commands\ConfigReposRemoveCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

function setupConfigReposRemoveHome(?string $globalYaml = null, array $existingDirs = []): array
{
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-config-repos-remove-cmd-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    if ($globalYaml !== null) {
        file_put_contents($home.'/.copland.yml', str_replace('HOME', $home, $globalYaml));
    }

    foreach ($existingDirs as $rel) {
        $abs = $home.'/'.$rel;
        if (! is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
    }

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

    return [$home, $cleanup];
}

function makeConfigReposRemoveTester(): CommandTester
{
    $command = new ConfigReposRemoveCommand;
    $command->setLaravel(app());

    return new CommandTester($command);
}

it('Test 1: happy path — removes the middle entry, leaves others in order', function () {
    [$home, $cleanup] = setupConfigReposRemoveHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/first\n    path: HOME/first\n  - slug: owner/middle\n    path: HOME/middle\n  - slug: owner/third\n    path: HOME/third\n",
        existingDirs: ['first', 'middle', 'third'],
    );

    $tester = makeConfigReposRemoveTester();
    $exit = $tester->execute(['--slug' => 'owner/middle']);

    expect($exit)->toBe(0);

    $parsed = Yaml::parseFile($home.'/.copland.yml');
    expect($parsed['repos'])->toBe([
        ['slug' => 'owner/first', 'path' => $home.'/first'],
        ['slug' => 'owner/third', 'path' => $home.'/third'],
    ]);

    $cleanup();
});

it('Test 2: removing the last entry leaves repos: [] and the block header intact', function () {
    [$home, $cleanup] = setupConfigReposRemoveHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/only\n    path: HOME/only\n",
        existingDirs: ['only'],
    );

    $tester = makeConfigReposRemoveTester();
    $exit = $tester->execute(['--slug' => 'owner/only']);

    expect($exit)->toBe(0);

    $contents = file_get_contents($home.'/.copland.yml');
    // Block header still present (we did NOT delete the block).
    expect($contents)->toContain('repos:');

    // And it parses back to an empty array (whatever Yaml::dump emitted for []).
    $parsed = Yaml::parseFile($home.'/.copland.yml');
    expect($parsed['repos'])->toBe([]);

    $cleanup();
});

it('Test 3: slug not found exits non-zero with stderr', function () {
    [, $cleanup] = setupConfigReposRemoveHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/keepme\n    path: HOME/keepme\n",
        existingDirs: ['keepme'],
    );

    $tester = makeConfigReposRemoveTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/missing'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())
        ->toContain("Repo 'owner/missing' not found in ~/.copland.yml.");

    $cleanup();
});

it('Test 4: missing --slug exits non-zero with stderr', function () {
    [, $cleanup] = setupConfigReposRemoveHome(
        globalYaml: "claude_api_key: \"\"\n",
    );

    $tester = makeConfigReposRemoveTester();
    $exit = $tester->execute(
        [],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('Missing required flag: --slug');

    $cleanup();
});

it('Test 5a: missing global config exits non-zero', function () {
    [, $cleanup] = setupConfigReposRemoveHome();

    $tester = makeConfigReposRemoveTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('.copland.yml');

    $cleanup();
});

it('Test 5b: malformed global YAML exits non-zero with parse stderr', function () {
    [, $cleanup] = setupConfigReposRemoveHome(
        globalYaml: "defaults:\n  - bad: [unterminated\n",
    );

    $tester = makeConfigReposRemoveTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    $err = $tester->getErrorOutput();
    expect($err)->toContain('.copland.yml');
    expect(strtolower($err))->toContain('parse');

    $cleanup();
});

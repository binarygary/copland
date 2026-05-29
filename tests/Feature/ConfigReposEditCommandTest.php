<?php

use App\Commands\ConfigReposEditCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

function setupConfigReposEditHome(?string $globalYaml = null, array $existingDirs = []): array
{
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-config-repos-edit-cmd-'.uniqid();

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

function makeConfigReposEditTester(): CommandTester
{
    $command = new ConfigReposEditCommand;
    $command->setLaravel(app());

    return new CommandTester($command);
}

it('Test 1: happy path — rewrites only the path; slug unchanged; order preserved', function () {
    [$home, $cleanup] = setupConfigReposEditHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/foo\n    path: HOME/old\n",
        existingDirs: ['old', 'new'],
    );

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute([
        '--slug' => 'owner/foo',
        '--path' => $home.'/new',
    ]);

    expect($exit)->toBe(0);

    $parsed = Yaml::parseFile($home.'/.copland.yml');
    expect($parsed['repos'])->toBe([
        ['slug' => 'owner/foo', 'path' => $home.'/new'],
    ]);

    $cleanup();
});

it('Test 2: slug not found exits non-zero with stderr', function () {
    [$home, $cleanup] = setupConfigReposEditHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/bar\n    path: HOME/bar\n",
        existingDirs: ['bar', 'somewhere'],
    );

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/missing', '--path' => $home.'/somewhere'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())
        ->toContain("Repo 'owner/missing' not found in ~/.copland.yml.");

    $cleanup();
});

it('Test 3: invalid path is rejected with the same template as add', function () {
    [$home, $cleanup] = setupConfigReposEditHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/foo\n    path: HOME/old\n",
        existingDirs: ['old'],
    );

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo', '--path' => '/tmp/nope-edit-24-01-zzz'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())
        ->toContain("Path '/tmp/nope-edit-24-01-zzz' does not exist or is not a directory.");

    $cleanup();
});

it('Test 4: missing --slug exits non-zero with stderr', function () {
    [$home, $cleanup] = setupConfigReposEditHome(
        globalYaml: "claude_api_key: \"\"\n",
        existingDirs: ['dir'],
    );

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute(
        ['--path' => $home.'/dir'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('Missing required flag: --slug');

    $cleanup();
});

it('Test 5: missing --path exits non-zero with stderr', function () {
    [, $cleanup] = setupConfigReposEditHome(
        globalYaml: "claude_api_key: \"\"\n",
    );

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('Missing required flag: --path');

    $cleanup();
});

it('Test 6: other repos in the list are untouched', function () {
    [$home, $cleanup] = setupConfigReposEditHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/first\n    path: HOME/first\n  - slug: owner/middle\n    path: HOME/middle\n  - slug: owner/third\n    path: HOME/third\n",
        existingDirs: ['first', 'middle', 'third', 'middle-new'],
    );

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute([
        '--slug' => 'owner/middle',
        '--path' => $home.'/middle-new',
    ]);

    expect($exit)->toBe(0);

    $parsed = Yaml::parseFile($home.'/.copland.yml');
    expect($parsed['repos'])->toBe([
        ['slug' => 'owner/first', 'path' => $home.'/first'],
        ['slug' => 'owner/middle', 'path' => $home.'/middle-new'],
        ['slug' => 'owner/third', 'path' => $home.'/third'],
    ]);

    $cleanup();
});

it('Test 7a: missing global config exits non-zero', function () {
    [$home, $cleanup] = setupConfigReposEditHome();

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo', '--path' => $home],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('.copland.yml');

    $cleanup();
});

it('Test 7b: malformed global YAML exits non-zero with parse stderr', function () {
    [$home, $cleanup] = setupConfigReposEditHome(
        globalYaml: "defaults:\n  - bad: [unterminated\n",
        existingDirs: ['dir'],
    );

    $tester = makeConfigReposEditTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo', '--path' => $home.'/dir'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    $err = $tester->getErrorOutput();
    expect($err)->toContain('.copland.yml');
    expect(strtolower($err))->toContain('parse');

    $cleanup();
});

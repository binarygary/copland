<?php

use App\Commands\ConfigReposAddCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Feature tests for `copland config:repos:add`.
 *
 * Mirrors the tmp-HOME pattern from tests/Feature/ConfigShowCommandTest.php.
 * Helper is renamed (setupConfigReposAddHome) to avoid "function already
 * declared" collisions when Pest autoloads the suite.
 */
function setupConfigReposAddHome(?string $globalYaml = null, array $existingDirs = []): array
{
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-config-repos-add-cmd-'.uniqid();

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

function makeConfigReposAddTester(): CommandTester
{
    $command = new ConfigReposAddCommand;
    $command->setLaravel(app());

    return new CommandTester($command);
}

it('Test 1: appends to a fresh config that has no repos block (happy path)', function () {
    [$home, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\n",
        existingDirs: ['foo-repo'],
    );

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute([
        '--slug' => 'owner/foo',
        '--path' => $home.'/foo-repo',
    ]);

    expect($exit)->toBe(0);

    $contents = file_get_contents($home.'/.copland.yml');
    expect($contents)->toContain('owner/foo');
    expect($contents)->toContain($home.'/foo-repo');
    expect($contents)->toContain('repos:');

    $cleanup();
});

it('Test 2: appends to an existing repos list (happy path)', function () {
    [$home, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/existing\n    path: HOME/existing\n",
        existingDirs: ['existing', 'newone'],
    );

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute([
        '--slug' => 'owner/newone',
        '--path' => $home.'/newone',
    ]);

    expect($exit)->toBe(0);

    $contents = file_get_contents($home.'/.copland.yml');
    expect($contents)->toContain('owner/existing');
    expect($contents)->toContain('owner/newone');

    $cleanup();
});

it('Test 3: missing --slug exits non-zero with stderr', function () {
    [$home, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\n",
        existingDirs: ['dir'],
    );

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute(
        ['--path' => $home.'/dir'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('Missing required flag: --slug');

    $cleanup();
});

it('Test 4: missing --path exits non-zero with stderr', function () {
    [, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\n",
    );

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('Missing required flag: --path');

    $cleanup();
});

it('Test 5: invalid slug format is rejected', function () {
    [$home, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\n",
        existingDirs: ['dir'],
    );

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute(
        ['--slug' => 'NOT-A-SLUG', '--path' => $home.'/dir'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toMatch('/Invalid slug.*owner\/repo/');

    $cleanup();
});

it('Test 6: nonexistent path is rejected', function () {
    [, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\n",
    );

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo', '--path' => '/tmp/definitely-not-real-zzz-24-01'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())
        ->toContain("Path '/tmp/definitely-not-real-zzz-24-01' does not exist or is not a directory.");

    $cleanup();
});

it('Test 7: a file (not a directory) for --path is rejected with the same template', function () {
    [$home, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\n",
    );

    $filePath = $home.'/im-a-file.txt';
    file_put_contents($filePath, 'not a dir');

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo', '--path' => $filePath],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain("Path '{$filePath}' does not exist or is not a directory.");

    $cleanup();
});

it('Test 8: duplicate slug is rejected with a helpful message', function () {
    [$home, $cleanup] = setupConfigReposAddHome(
        globalYaml: "claude_api_key: \"\"\nrepos:\n  - slug: owner/foo\n    path: HOME/foo\n",
        existingDirs: ['foo', 'other'],
    );

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo', '--path' => $home.'/other'],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())
        ->toContain("Repo 'owner/foo' already exists. Use config:repos:edit to change its path.");

    $cleanup();
});

it('Test 9: missing global config exits non-zero with stderr naming the file', function () {
    [$home, $cleanup] = setupConfigReposAddHome();

    $tester = makeConfigReposAddTester();
    $exit = $tester->execute(
        ['--slug' => 'owner/foo', '--path' => $home],
        ['capture_stderr_separately' => true],
    );

    expect($exit)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('.copland.yml');
    // Bootstrap MUST NOT create the file as a side effect.
    expect(file_exists($home.'/.copland.yml'))->toBeFalse();

    $cleanup();
});

it('Test 10: malformed global YAML exits non-zero with stderr mentioning parse', function () {
    [$home, $cleanup] = setupConfigReposAddHome(
        globalYaml: "defaults:\n  - bad: [unterminated\n",
        existingDirs: ['dir'],
    );

    $tester = makeConfigReposAddTester();
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

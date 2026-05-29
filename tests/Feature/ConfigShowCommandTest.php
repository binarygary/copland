<?php

use App\Commands\ConfigShowCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Build an isolated tmp HOME and return [$home, $cleanup]. Mirrors the unit
 * helper but lives in feature scope so the function name does not collide.
 */
function setupConfigShowHome(?string $globalYaml = null, array $repos = []): array
{
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-config-show-cmd-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    if ($globalYaml !== null) {
        file_put_contents($home.'/.copland.yml', str_replace('HOME', $home, $globalYaml));
    }

    foreach ($repos as $relPath => $localYaml) {
        $repoDir = $home.'/'.$relPath;
        if (! is_dir($repoDir)) {
            mkdir($repoDir, 0755, true);
        }
        if ($localYaml !== null) {
            file_put_contents($repoDir.'/.copland.yml', $localYaml);
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

function makeConfigShowTester(): CommandTester
{
    $command = new ConfigShowCommand;
    $command->setLaravel(app());

    return new CommandTester($command);
}

it('emits exactly one JSON document on stdout and exits 0 in --json mode', function () {
    [, $cleanup] = setupConfigShowHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
repos:
  - slug: owner/repo
    path: HOME/repo
YAML,
        repos: ['repo' => "task_source: github\n"],
    );

    $tester = makeConfigShowTester();
    $exitCode = $tester->execute(['--json' => true]);
    $display = $tester->getDisplay();

    expect($exitCode)->toBe(0);
    $parsed = json_decode(trim($display), true);
    expect($parsed)->not->toBeNull();
    expect(array_keys($parsed))->toBe(['schema_version', 'defaults', 'asana_token_set', 'repos']);

    $cleanup();
});

it('writes nothing on stdout in --json mode besides the JSON document + newline', function () {
    [, $cleanup] = setupConfigShowHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
repos:
  - slug: owner/repo
    path: HOME/repo
YAML,
        repos: ['repo' => "task_source: github\n"],
    );

    $tester = makeConfigShowTester();
    $tester->execute(['--json' => true]);
    $display = $tester->getDisplay();

    // The display must be exactly: json_encode($parsed, JSON_UNESCAPED_SLASHES) + "\n"
    $parsed = json_decode(trim($display), true);
    $expected = json_encode($parsed, JSON_UNESCAPED_SLASHES)."\n";

    expect($display)->toBe($expected);

    $cleanup();
});

it('redacts the asana token end-to-end (raw value never appears in --json stdout)', function () {
    [, $cleanup] = setupConfigShowHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
asana_token: "secret-xyz-end-to-end"
repos: []
YAML,
    );

    $tester = makeConfigShowTester();
    $exitCode = $tester->execute(['--json' => true]);
    $display = $tester->getDisplay();

    expect($exitCode)->toBe(0);
    expect($display)->not->toContain('secret-xyz-end-to-end');

    $parsed = json_decode(trim($display), true);
    expect($parsed['asana_token_set'])->toBeTrue();

    $cleanup();
});

it('prints a human-readable summary in non-json mode and exits 0', function () {
    [, $cleanup] = setupConfigShowHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
repos:
  - slug: owner/visible-slug
    path: HOME/visible-slug
YAML,
        repos: ['visible-slug' => "task_source: github\n"],
    );

    $tester = makeConfigShowTester();
    $exitCode = $tester->execute([]);
    $display = $tester->getDisplay();

    expect($exitCode)->toBe(0);
    // Human mode should surface at least the repo slug so the user can verify config visually.
    expect($display)->toContain('owner/visible-slug');
    // And it must NOT be one-line JSON.
    expect(json_decode(trim($display), true))->toBeNull();

    $cleanup();
});

// ---------------------------------------------------------------------------
// Task 4: error-path coverage
// ---------------------------------------------------------------------------

it('exits non-zero with stderr message and no JSON on stdout when ~/.copland.yml is missing', function () {
    // No global YAML written — the tmp HOME is empty. The command MUST detect
    // this BEFORE instantiating GlobalConfig (whose ctor would silently create
    // a default file and mask the error).
    [$home, $cleanup] = setupConfigShowHome();

    $tester = makeConfigShowTester();
    $exitCode = $tester->execute(['--json' => true], ['capture_stderr_separately' => true]);

    expect($exitCode)->not->toBe(0);
    expect($tester->getErrorOutput())->toContain('.copland.yml');
    // No JSON on stdout.
    expect(trim($tester->getDisplay()))->toBe('');
    // And the bootstrap MUST NOT have created the file as a side effect of running the command.
    expect(file_exists($home.'/.copland.yml'))->toBeFalse();

    $cleanup();
});

it('exits non-zero with a stderr parse-error message when ~/.copland.yml is malformed', function () {
    [, $cleanup] = setupConfigShowHome(
        globalYaml: "defaults:\n  - bad: [unterminated\n",
    );

    $tester = makeConfigShowTester();
    $exitCode = $tester->execute(['--json' => true], ['capture_stderr_separately' => true]);

    expect($exitCode)->not->toBe(0);
    $err = $tester->getErrorOutput();
    // Error must point the user at the file and indicate a parse failure.
    expect($err)->toContain('.copland.yml');
    expect(strtolower($err))->toContain('parse');
    // No JSON on stdout.
    expect(trim($tester->getDisplay()))->toBe('');

    $cleanup();
});

it('exits non-zero with a stderr message naming the missing path when a configured repo path does not exist', function () {
    [, $cleanup] = setupConfigShowHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
repos:
  - slug: owner/ghost
    path: /tmp/copland-does-not-exist-zzz-23-01
YAML,
    );

    $tester = makeConfigShowTester();
    $exitCode = $tester->execute(['--json' => true], ['capture_stderr_separately' => true]);

    expect($exitCode)->not->toBe(0);
    $err = $tester->getErrorOutput();
    expect($err)->toContain('owner/ghost');
    expect($err)->toContain('/tmp/copland-does-not-exist-zzz-23-01');
    // No JSON on stdout.
    expect(trim($tester->getDisplay()))->toBe('');

    $cleanup();
});

it('emits nothing on stdout in --json mode when erroring (stderr-only error channel)', function () {
    // Re-use the missing-global-config setup but assert the stdout/stderr split
    // explicitly through capture_stderr_separately. This is the JSON-mode-stdout-
    // is-pure invariant: a downstream consumer that pipes stdout into jq must
    // never see a partial document on error.
    [, $cleanup] = setupConfigShowHome();

    $tester = makeConfigShowTester();
    $tester->execute(['--json' => true], ['capture_stderr_separately' => true]);

    $stdout = $tester->getDisplay();
    $stderr = $tester->getErrorOutput();

    // stdout must be empty (or pure whitespace) — NO partial JSON.
    expect(trim($stdout))->toBe('');
    // Error message lives on stderr.
    expect($stderr)->not->toBe('');

    $cleanup();
});

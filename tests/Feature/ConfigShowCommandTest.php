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

<?php

use App\Config\GlobalConfig;
use App\Services\ConfigShowService;

/**
 * Helper: stand up an isolated tmp HOME with optional global YAML body and
 * optional repo folders. Returns [$home, $cleanup] where $cleanup restores
 * $_SERVER['HOME'] and removes the tmp dir.
 */
function makeIsolatedHome(?string $globalYaml = null, array $repos = []): array
{
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-config-show-svc-'.uniqid();

    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    if ($globalYaml !== null) {
        file_put_contents($home.'/.copland.yml', $globalYaml);
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
        // best-effort recursive remove
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

it('builds a snapshot whose top-level + per-repo keys match the canonical fixture', function () {
    [$home, $cleanup] = makeIsolatedHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
asana_token: "tok-xyz"
defaults:
  max_files_changed: 3
  max_lines_changed: 250
  base_branch: main
models:
  selector: claude-haiku-4-5
  planner: claude-sonnet-4-6
  executor: claude-sonnet-4-6
repos:
  - slug: owner/with-local
    path: HOME/with-local
  - slug: owner/no-local
    path: HOME/no-local
YAML,
        repos: [
            'with-local' => "task_source: asana\nrepo_summary: \"x\"\nconventions: \"y\"\nllm: {}\n",
            'no-local' => null,
        ],
    );

    // resolve HOME placeholder in the YAML we just wrote
    $yaml = file_get_contents($home.'/.copland.yml');
    file_put_contents($home.'/.copland.yml', str_replace('HOME', $home, $yaml));

    $service = new ConfigShowService(new GlobalConfig);
    $snapshot = $service->snapshot();

    $fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/config/show-snapshot.json'), true);

    expect(array_keys($snapshot))->toBe(array_keys($fixture));
    expect(array_keys($snapshot['repos'][0]))->toBe(array_keys($fixture['repos'][0]));
    expect(array_keys($snapshot['repos'][1]))->toBe(array_keys($fixture['repos'][1]));
    expect($snapshot['schema_version'])->toBe(1);

    $cleanup();
});

it('marks asana_token_set true and redacts the raw token from the snapshot', function () {
    [, $cleanup] = makeIsolatedHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
asana_token: "secret-abc-123"
YAML,
    );

    $service = new ConfigShowService(new GlobalConfig);
    $snapshot = $service->snapshot();

    expect($snapshot['asana_token_set'])->toBeTrue();
    expect(json_encode($snapshot))->not->toContain('secret-abc-123');

    $cleanup();
});

it('treats an empty asana_token as not set', function () {
    [, $cleanup] = makeIsolatedHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
asana_token: ""
YAML,
    );

    $service = new ConfigShowService(new GlobalConfig);
    $snapshot = $service->snapshot();

    expect($snapshot['asana_token_set'])->toBeFalse();

    $cleanup();
});

it('treats a whitespace-only asana_token as not set', function () {
    [, $cleanup] = makeIsolatedHome(
        globalYaml: "claude_api_key: \"\"\nasana_token: \"   \\t  \"\n",
    );

    $service = new ConfigShowService(new GlobalConfig);
    $snapshot = $service->snapshot();

    expect($snapshot['asana_token_set'])->toBeFalse();

    $cleanup();
});

it('treats an absent asana_token key as not set', function () {
    [, $cleanup] = makeIsolatedHome(
        globalYaml: "claude_api_key: \"\"\n",
    );

    $service = new ConfigShowService(new GlobalConfig);
    $snapshot = $service->snapshot();

    expect($snapshot['asana_token_set'])->toBeFalse();

    $cleanup();
});

it('reports local_config as null when the per-repo .copland.yml is missing', function () {
    [$home, $cleanup] = makeIsolatedHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
repos:
  - slug: owner/no-local
    path: HOME/no-local
YAML,
        repos: ['no-local' => null],
    );

    file_put_contents(
        $home.'/.copland.yml',
        str_replace('HOME', $home, file_get_contents($home.'/.copland.yml')),
    );

    $service = new ConfigShowService(new GlobalConfig);
    $snapshot = $service->snapshot();

    expect($snapshot['repos'])->toHaveCount(1);
    expect($snapshot['repos'][0]['local_config'])->toBeNull();

    $cleanup();
});

it('exposes per-repo local_config as the RAW YAML (no default-filling)', function () {
    // The per-repo YAML deliberately OMITS task_source — RepoConfig would fill
    // a "github" default, but the snapshot must not. This is the canonical
    // "snapshot exposes YAML as written" assertion.
    [$home, $cleanup] = makeIsolatedHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
repos:
  - slug: owner/raw
    path: HOME/raw
YAML,
        repos: ['raw' => "repo_summary: \"only this and nothing else\"\n"],
    );

    file_put_contents(
        $home.'/.copland.yml',
        str_replace('HOME', $home, file_get_contents($home.'/.copland.yml')),
    );

    $service = new ConfigShowService(new GlobalConfig);
    $snapshot = $service->snapshot();

    $local = $snapshot['repos'][0]['local_config'];
    expect($local)->toBeArray();
    expect($local)->toHaveKey('repo_summary');
    expect($local)->not->toHaveKey('task_source');

    $cleanup();
});

it('sources defaults from GlobalConfig accessors', function () {
    [, $cleanup] = makeIsolatedHome(
        globalYaml: <<<'YAML'
claude_api_key: ""
defaults:
  max_files_changed: 7
  max_lines_changed: 1024
  base_branch: trunk
models:
  selector: model-a
  planner: model-b
  executor: model-c
YAML,
    );

    $globalConfig = new GlobalConfig;
    $snapshot = (new ConfigShowService($globalConfig))->snapshot();

    expect($snapshot['defaults'])->toBe([
        'max_files_changed' => $globalConfig->defaultMaxFiles(),
        'max_lines_changed' => $globalConfig->defaultMaxLines(),
        'base_branch' => $globalConfig->defaultBaseBranch(),
        'selector_model' => $globalConfig->selectorModel(),
        'planner_model' => $globalConfig->plannerModel(),
        'executor_model' => $globalConfig->executorModel(),
    ]);
    expect($snapshot['defaults']['max_files_changed'])->toBe(7);
    expect($snapshot['defaults']['base_branch'])->toBe('trunk');
    expect($snapshot['defaults']['selector_model'])->toBe('model-a');

    $cleanup();
});

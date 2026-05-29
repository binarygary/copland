<?php

namespace App\Services;

use App\Config\GlobalConfig;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the v1 JSON-serializable snapshot of the merged global + per-repo
 * configuration that downstream consumers (Godot console, future tooling)
 * read via `copland config:show --json`.
 *
 * Contract reference: tests/fixtures/config/show-snapshot.json.
 *
 * The service is the pure-data builder. The caller (ConfigShowCommand) is
 * responsible for preflight checks: that `~/.copland.yml` exists, that it
 * parses, and that every configured repo path exists on disk. snapshot()
 * assumes preflight has run.
 *
 * Notable invariants:
 * - The raw asana_token NEVER appears in the snapshot. Only the boolean
 *   `asana_token_set` (derived from strlen(trim($token)) > 0) is emitted.
 * - Per-repo `local_config` is the RAW parsed `.copland.yml` for the repo
 *   (or null if the file is absent). We deliberately bypass RepoConfig
 *   because RepoConfig fills schema defaults at read time — the snapshot
 *   must expose the YAML as the user wrote it.
 */
class ConfigShowService
{
    public function __construct(private GlobalConfig $globalConfig) {}

    /**
     * @return array{
     *     schema_version: int,
     *     defaults: array<string,mixed>,
     *     asana_token_set: bool,
     *     repos: array<int, array<string,mixed>>,
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => 1,
            'defaults' => [
                'max_files_changed' => $this->globalConfig->defaultMaxFiles(),
                'max_lines_changed' => $this->globalConfig->defaultMaxLines(),
                'base_branch' => $this->globalConfig->defaultBaseBranch(),
                'selector_model' => $this->globalConfig->selectorModel(),
                'planner_model' => $this->globalConfig->plannerModel(),
                'executor_model' => $this->globalConfig->executorModel(),
            ],
            'asana_token_set' => strlen(trim($this->globalConfig->asanaToken())) > 0,
            'repos' => array_map(
                fn (array $repo): array => $this->buildRepoEntry($repo),
                $this->globalConfig->configuredRepos(),
            ),
        ];
    }

    /**
     * @param  array{slug:string,path:string}  $configured
     * @return array{
     *     slug: string,
     *     path: string,
     *     asana_project: string|null,
     *     asana_filters: array<mixed>,
     *     local_config: array<string,mixed>|null,
     * }
     */
    private function buildRepoEntry(array $configured): array
    {
        $slug = $configured['slug'];
        $path = $configured['path'];

        return [
            'slug' => $slug,
            'path' => $path,
            'asana_project' => $this->globalConfig->asanaProjectForRepo($slug),
            'asana_filters' => $this->globalConfig->asanaFiltersForRepo($slug),
            'local_config' => $this->readRawLocalConfig($path),
        ];
    }

    /**
     * Raw `Yaml::parseFile` on `<repoPath>/.copland.yml`. Returns null when
     * the file is absent. We bypass RepoConfig on purpose — RepoConfig's
     * constructor auto-creates the file with a default body AND its
     * accessors fill defaults; both would hide which fields the user
     * actually set in their YAML.
     *
     * @return array<string,mixed>|null
     */
    private function readRawLocalConfig(string $repoPath): ?array
    {
        $file = rtrim($repoPath, '/').'/.copland.yml';

        if (! file_exists($file)) {
            return null;
        }

        $parsed = Yaml::parseFile($file);

        return is_array($parsed) ? $parsed : [];
    }
}

<?php

namespace App\Config;

use App\Support\HomeDirectory;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class GlobalConfig
{
    private array $data;

    private string $path;

    public function __construct()
    {
        $this->path = $this->resolvePath();
        $this->ensureExists();
        $this->data = Yaml::parseFile($this->path) ?? [];
    }

    private function resolvePath(): string
    {
        $home = HomeDirectory::resolve();

        $preferred = $home.'/.copland.yml';
        $legacy = $home.'/.copland/config.yml';

        if (file_exists($preferred)) {
            return $preferred;
        }

        if (file_exists($legacy)) {
            return $legacy;
        }

        return $preferred;
    }

    private function ensureExists(): void
    {
        if (file_exists($this->path)) {
            return;
        }

        $dir = dirname($this->path);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create config directory at {$dir}");
        }

        $default = <<<'YAML'
claude_api_key: ""

models:
  selector: claude-haiku-4-5
  planner: claude-sonnet-4-6
  executor: claude-sonnet-4-6

defaults:
  max_files_changed: 3
  max_lines_changed: 250
  base_branch: main

api:
  retry:
    max_attempts: 3
    base_delay_seconds: 1

# repos:
#   - slug: owner/repo
#     path: /absolute/path/to/local/checkout
#   - owner/current-repo
YAML;

        if (file_put_contents($this->path, $default.PHP_EOL) === false) {
            throw new RuntimeException("Failed to create global config at {$this->path}");
        }
    }

    public function claudeApiKey(): string
    {
        return $this->data['claude_api_key'] ?? '';
    }

    public function defaultMaxFiles(): int
    {
        return $this->data['defaults']['max_files_changed'] ?? 3;
    }

    public function defaultMaxLines(): int
    {
        return $this->data['defaults']['max_lines_changed'] ?? 250;
    }

    public function defaultBaseBranch(): string
    {
        return $this->data['defaults']['base_branch'] ?? 'main';
    }

    public function selectorModel(): string
    {
        return $this->data['models']['selector'] ?? 'claude-haiku-4-5';
    }

    public function plannerModel(): string
    {
        return $this->data['models']['planner'] ?? 'claude-sonnet-4-6';
    }

    public function executorModel(): string
    {
        return $this->data['models']['executor'] ?? 'claude-sonnet-4-6';
    }

    public function repos(): array
    {
        return $this->data['repos'] ?? [];
    }

    public function configuredRepos(): array
    {
        $currentPath = getcwd() ?: '.';
        $currentRepo = $this->detectRepoSlugAtPath($currentPath);

        return array_map(
            function (mixed $repo) use ($currentPath, $currentRepo): array {
                if (is_string($repo)) {
                    $slug = trim($repo);

                    if ($slug === '') {
                        throw new RuntimeException('Configured repo slugs cannot be empty.');
                    }

                    if ($currentRepo !== $slug) {
                        throw new RuntimeException(
                            "Configured repo '{$slug}' needs an explicit path when it does not match the current checkout."
                        );
                    }

                    return ['slug' => $slug, 'path' => $currentPath];
                }

                if (is_array($repo)) {
                    $slug = trim((string) ($repo['slug'] ?? ''));
                    $path = trim((string) ($repo['path'] ?? $currentPath));

                    if ($slug === '') {
                        throw new RuntimeException('Configured repo objects must include a non-empty slug.');
                    }

                    if ($path === '') {
                        throw new RuntimeException("Configured repo '{$slug}' must resolve to a non-empty path.");
                    }

                    return ['slug' => $slug, 'path' => $path];
                }

                throw new RuntimeException('Configured repos must be strings or objects with slug/path.');
            },
            $this->repos(),
        );
    }

    public function llmConfig(): array
    {
        return $this->data['llm'] ?? [];
    }

    public function asanaToken(): string
    {
        return $this->data['asana_token'] ?? '';
    }

    public function asanaProjectForRepo(string $slug): ?string
    {
        foreach ($this->repos() as $repo) {
            if (is_array($repo) && ($repo['slug'] ?? '') === $slug) {
                return isset($repo['asana_project']) ? (string) $repo['asana_project'] : null;
            }
        }

        return null;
    }

    public function asanaFiltersForRepo(string $slug): array
    {
        foreach ($this->repos() as $repo) {
            if (is_array($repo) && ($repo['slug'] ?? '') === $slug) {
                return $repo['asana_filters'] ?? [];
            }
        }

        return [];
    }

    public function retryMaxAttempts(): int
    {
        return $this->data['api']['retry']['max_attempts'] ?? 3;
    }

    public function retryBaseDelaySeconds(): int
    {
        return $this->data['api']['retry']['base_delay_seconds'] ?? 1;
    }

    private function detectRepoSlugAtPath(string $path): ?string
    {
        $process = new Process(['git', '-C', $path, 'remote', 'get-url', 'origin']);
        $process->run();

        if ($process->isSuccessful()) {
            return $this->parseRepoSlug(trim($process->getOutput()));
        }

        $configPath = rtrim($path, '/').'/.git/config';

        if (! file_exists($configPath)) {
            return null;
        }

        $config = file_get_contents($configPath);

        if ($config === false || preg_match('/url\s*=\s*(.+)/', $config, $matches) !== 1) {
            return null;
        }

        return $this->parseRepoSlug(trim($matches[1]));
    }

    private function parseRepoSlug(string $origin): ?string
    {
        $normalized = preg_replace('#\.git$#', '', $origin);

        if ($normalized === null) {
            return null;
        }

        if (preg_match('#github\.com[:/](?<owner>[^/]+)/(?<repo>[^/]+)$#', $normalized, $matches) !== 1) {
            return null;
        }

        return $matches['owner'].'/'.$matches['repo'];
    }
}

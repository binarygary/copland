<?php

namespace App\Config;

use RuntimeException;
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
        $home = $_SERVER['HOME'] ?? null;

        if (! is_string($home) || $home === '') {
            throw new RuntimeException('HOME is not set.');
        }

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
}

<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class RepoConfig
{
    private array $data;

    private string $path;

    public function __construct(string $repoPath)
    {
        $this->path = rtrim($repoPath, '/').'/.copland.yml';
        $this->ensureExists();
        $this->data = Yaml::parseFile($this->path) ?? [];
    }

    private function ensureExists(): void
    {
        if (file_exists($this->path)) {
            return;
        }

$default = <<<'YAML'
base_branch: main
max_executor_rounds: 12
read_file_max_lines: 300

issue_labels:
  required: [agent-ready]
  blocked: [agent-skip, blocked]

allowed_commands:
  - php artisan
  - composer
  - npm
  - pest

blocked_paths:
  - database/migrations

risky_keywords:
  - migration
  - auth
  - billing
  - deploy
  - infrastructure

repo_summary: ""
conventions: ""
YAML;

        file_put_contents($this->path, $default.PHP_EOL);
    }

    public function baseBranch(): string
    {
        return $this->data['base_branch'] ?? 'main';
    }

    public function maxExecutorRounds(): int
    {
        return (int) ($this->data['max_executor_rounds'] ?? 12);
    }

    public function readFileMaxLines(): int
    {
        return max(1, (int) ($this->data['read_file_max_lines'] ?? 300));
    }

    public function requiredLabels(): array
    {
        return $this->data['issue_labels']['required'] ?? ['agent-ready'];
    }

    public function blockedLabels(): array
    {
        return $this->data['issue_labels']['blocked'] ?? ['agent-skip', 'blocked'];
    }

    public function allowedCommands(): array
    {
        return $this->data['allowed_commands'] ?? [];
    }

    public function blockedPaths(): array
    {
        return $this->data['blocked_paths'] ?? [];
    }

    public function riskyKeywords(): array
    {
        return $this->data['risky_keywords'] ?? [];
    }

    public function repoSummary(): string
    {
        return $this->data['repo_summary'] ?? '';
    }

    public function conventions(): string
    {
        return $this->data['conventions'] ?? '';
    }

    public function llmConfig(): array
    {
        return $this->data['llm'] ?? [];
    }

    public function taskSource(): string
    {
        return $this->data['task_source'] ?? 'github';
    }
}

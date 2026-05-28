<?php

namespace App\Support;

use Anthropic\Client as AnthropicClient;
use App\Config\GlobalConfig;
use App\Config\RepoConfig;
use App\Contracts\LlmClient;
use OpenAI;

/**
 * Resolves the correct LlmClient per stage based on llm: config.
 *
 * Resolution order (D-05):
 *   repo llm.stages.{stage} → global llm.stages.{stage}
 *   → repo llm.default → global llm.default → ['provider' => 'anthropic']
 *
 * Backwards-compatible: when no llm: config is present, returns AnthropicApiClient.
 */
final class LlmClientFactory
{
    /**
     * Resolve and build the appropriate LlmClient for the given pipeline stage.
     */
    public static function forStage(string $stage, GlobalConfig $global, ?RepoConfig $repo = null): LlmClient
    {
        $config = self::resolveConfig($stage, $global, $repo);

        return match ($config['provider'] ?? 'anthropic') {
            'ollama' => self::buildOllama($config),
            'openrouter' => self::buildOpenRouter($config),
            'claude-code' => self::buildClaudeCode($config, $stage, $repo),
            default => self::buildAnthropic($global),
        };
    }

    /**
     * Return deduplicated list of ['binary_path', 'model'] entries for all
     * claude-code-configured stages. Used by RunCommand for startup warning.
     */
    public static function claudeCodeStageConfigs(GlobalConfig $global, ?RepoConfig $repo = null): array
    {
        $stages = ['selector', 'planner', 'executor'];
        $seen = [];
        $result = [];

        foreach ($stages as $stage) {
            $config = self::resolveConfig($stage, $global, $repo);

            if (($config['provider'] ?? '') !== 'claude-code') {
                continue;
            }

            $binaryPath = $config['binary_path'] ?? 'claude';
            $model = $config['model'] ?? '';

            if (isset($seen[$binaryPath])) {
                continue;
            }

            $seen[$binaryPath] = true;
            $result[] = ['binary_path' => $binaryPath, 'model' => $model];
        }

        return $result;
    }

    /**
     * Return deduplicated list of ['base_url', 'model'] entries for all
     * ollama-configured stages. Used by RunCommand for probe/warning.
     */
    public static function ollamaStageConfigs(GlobalConfig $global, ?RepoConfig $repo = null): array
    {
        $stages = ['selector', 'planner', 'executor'];
        $seen = [];
        $result = [];

        foreach ($stages as $stage) {
            $config = self::resolveConfig($stage, $global, $repo);

            if (($config['provider'] ?? '') !== 'ollama') {
                continue;
            }

            $baseUrl = $config['base_url'] ?? 'http://localhost:11434/v1';
            $model = $config['model'] ?? '';

            // Deduplicate by base_url
            if (isset($seen[$baseUrl])) {
                continue;
            }

            $seen[$baseUrl] = true;
            $result[] = ['base_url' => $baseUrl, 'model' => $model];
        }

        return $result;
    }

    /**
     * D-05 resolution order:
     *   1. repo llm.stages.{stage}
     *   2. global llm.stages.{stage}
     *   3. repo llm.default
     *   4. global llm.default
     *   5. ['provider' => 'anthropic']  (implicit fallback)
     */
    private static function resolveConfig(string $stage, GlobalConfig $global, ?RepoConfig $repo): array
    {
        $globalLlm = $global->llmConfig();
        $repoLlm = $repo?->llmConfig() ?? [];

        // 1. repo stage
        if (isset($repoLlm['stages'][$stage]) && is_array($repoLlm['stages'][$stage])) {
            return $repoLlm['stages'][$stage];
        }

        // 2. global stage
        if (isset($globalLlm['stages'][$stage]) && is_array($globalLlm['stages'][$stage])) {
            return $globalLlm['stages'][$stage];
        }

        // 3. repo default
        if (isset($repoLlm['default']) && is_array($repoLlm['default'])) {
            return $repoLlm['default'];
        }

        // 4. global default
        if (isset($globalLlm['default']) && is_array($globalLlm['default'])) {
            return $globalLlm['default'];
        }

        // 5. anthropic fallback
        return ['provider' => 'anthropic'];
    }

    /**
     * Build an AnthropicApiClient using credentials from GlobalConfig.
     */
    private static function buildAnthropic(GlobalConfig $global): AnthropicApiClient
    {
        return new AnthropicApiClient(
            client: new AnthropicClient(apiKey: $global->claudeApiKey()),
            maxAttempts: $global->retryMaxAttempts(),
            baseDelaySeconds: $global->retryBaseDelaySeconds(),
        );
    }

    /**
     * Build an OpenAiCompatClient pointed at an Ollama instance.
     */
    private static function buildOllama(array $config): OpenAiCompatClient
    {
        $baseUrl = $config['base_url'] ?? 'http://localhost:11434/v1';

        $client = OpenAI::factory()
            ->withApiKey('ollama')
            ->withBaseUri($baseUrl)
            ->make();

        return new OpenAiCompatClient($client);
    }

    /**
     * Build an OpenAiCompatClient for OpenRouter.
     * HTTP-Referer and X-Title headers are baked into the client here (D-19).
     */
    private static function buildOpenRouter(array $config): OpenAiCompatClient
    {
        $client = OpenAI::factory()
            ->withApiKey($config['api_key'] ?? '')
            ->withBaseUri('https://openrouter.ai/api/v1')
            ->withHttpHeader('HTTP-Referer', 'https://github.com/binarygary/copland')
            ->withHttpHeader('X-Title', 'Copland')
            ->make();

        return new OpenAiCompatClient($client);
    }

    /**
     * Per-stage allowed-tools defaults for the claude-code provider.
     * Overridable via per-stage config key `allowed_tools: [...]` (REPLACES the default).
     *
     * @return array<int, string>
     */
    private static function claudeCodeDefaultAllowedTools(string $stage): array
    {
        return match ($stage) {
            'selector' => [],
            'planner' => ['Read'],
            'executor' => ['Read', 'Edit', 'Write', 'Bash(git status)', 'Bash(git diff *)'],
            default => [],
        };
    }

    /**
     * Build a ClaudeCodeClient for a stage configured with provider=claude-code.
     *
     * Per-stage allowed-tools come from `allowed_tools` in config when present
     * (REPLACES default), or the built-in default otherwise. For the executor
     * stage, the repo's `allowed_commands` are mapped to `Bash(<first-word> *)`
     * entries and appended to the default whitelist (deduped).
     *
     * The JSON schema for `--json-schema` is loaded from
     * `resources/schemas/{$stage}.json` for selector and planner. The executor
     * stage uses an inline minimal {summary: string} schema since the executor's
     * "result" is the final summary text.
     */
    private static function buildClaudeCode(array $config, string $stage, ?RepoConfig $repo): ClaudeCodeClient
    {
        $binaryPath = $config['binary_path'] ?? 'claude';
        $model = $config['model'] ?? null;
        $maxBudgetUsd = isset($config['max_budget_usd']) ? (float) $config['max_budget_usd'] : null;

        if (isset($config['allowed_tools']) && is_array($config['allowed_tools'])) {
            $allowedTools = array_values($config['allowed_tools']);
        } else {
            $allowedTools = self::claudeCodeDefaultAllowedTools($stage);

            if ($stage === 'executor' && $repo !== null) {
                foreach ($repo->allowedCommands() as $cmd) {
                    $first = preg_split('/\s+/', trim((string) $cmd), 2)[0] ?? '';
                    if ($first === '') {
                        continue;
                    }
                    $entry = "Bash({$first} *)";
                    if (! in_array($entry, $allowedTools, true)) {
                        $allowedTools[] = $entry;
                    }
                }
            }
        }

        if ($stage === 'executor') {
            // The executor returns a free-form summary; constrain only that field.
            $schema = '{"type":"object","properties":{"summary":{"type":"string"}},"required":["summary"]}';
        } else {
            $schemaPath = base_path("resources/schemas/{$stage}.json");
            $schema = @file_get_contents($schemaPath);
            if ($schema === false) {
                throw new \RuntimeException(
                    "claude-code: schema file not found or unreadable for stage '{$stage}' at {$schemaPath}"
                );
            }
        }

        // RunCommand::runRepo() has already chdir($path) before the factory call,
        // so getcwd() is the workspace root.
        $cwd = getcwd() ?: '.';

        return new ClaudeCodeClient(
            runner: new ClaudeCodeRunner($binaryPath),
            jsonSchema: $schema,
            allowedTools: $allowedTools,
            workspaceCwd: $cwd,
            maxBudgetUsd: $maxBudgetUsd,
        );
    }
}

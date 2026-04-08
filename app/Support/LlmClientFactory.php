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
            default => self::buildAnthropic($global),
        };
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
}

<?php

namespace Tests\Unit;

use App\Config\GlobalConfig;
use App\Config\RepoConfig;
use App\Support\AnthropicApiClient;
use App\Support\ClaudeCodeClient;
use App\Support\LlmClientFactory;
use App\Support\OpenAiCompatClient;
use Tests\TestCase;

/**
 * Tests for LlmClientFactory::forStage() — D-05 resolution order:
 * repo stage → global stage → repo default → global default → anthropic fallback
 */
class LlmClientFactoryTest extends TestCase
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a GlobalConfig stub with the given llm config data.
     */
    private function makeGlobal(array $llm = [], string $apiKey = 'test-key', int $maxAttempts = 3, int $baseDelay = 1): GlobalConfig
    {
        return new class ($llm, $apiKey, $maxAttempts, $baseDelay) extends GlobalConfig
        {
            public function __construct(
                private array $llmData,
                private string $key,
                private int $maxA,
                private int $baseD,
            ) {
                // Skip parent constructor (no config file needed)
            }

            public function llmConfig(): array
            {
                return $this->llmData;
            }

            public function claudeApiKey(): string
            {
                return $this->key;
            }

            public function retryMaxAttempts(): int
            {
                return $this->maxA;
            }

            public function retryBaseDelaySeconds(): int
            {
                return $this->baseD;
            }
        };
    }

    /**
     * Create a RepoConfig stub with the given llm config data.
     *
     * @param  array<int, string>  $allowedCommands  Used by the executor-stage
     *   claude-code path to derive `Bash(<first-word> *)` entries.
     */
    private function makeRepo(array $llm = [], array $allowedCommands = []): RepoConfig
    {
        return new class ($llm, $allowedCommands) extends RepoConfig
        {
            public function __construct(private array $llmData, private array $allowed)
            {
                // Skip parent constructor (no config file needed)
            }

            public function llmConfig(): array
            {
                return $this->llmData;
            }

            public function allowedCommands(): array
            {
                return $this->allowed;
            }
        };
    }

    // ─── Test 1: empty config → AnthropicApiClient (backwards-compat) ────────

    public function test_for_stage_returns_anthropic_client_when_llm_config_absent(): void
    {
        $global = $this->makeGlobal([]);

        $client = LlmClientFactory::forStage('selector', $global);

        $this->assertInstanceOf(AnthropicApiClient::class, $client);
    }

    // ─── Test 2: global llm.default.provider=ollama → OpenAiCompatClient ──────

    public function test_for_stage_returns_openai_compat_client_for_global_ollama_default(): void
    {
        $global = $this->makeGlobal([
            'default' => [
                'provider' => 'ollama',
                'base_url' => 'http://localhost:11434/v1',
                'model' => 'llama3.1',
            ],
        ]);

        $client = LlmClientFactory::forStage('selector', $global);

        $this->assertInstanceOf(OpenAiCompatClient::class, $client);
    }

    // ─── Test 3: global llm.stages.selector.provider=openrouter → OpenAiCompatClient

    public function test_for_stage_returns_openai_compat_client_for_global_stage_openrouter(): void
    {
        $global = $this->makeGlobal([
            'stages' => [
                'selector' => [
                    'provider' => 'openrouter',
                    'api_key' => 'or-key-123',
                    'model' => 'openai/gpt-4o',
                ],
            ],
        ]);

        $client = LlmClientFactory::forStage('selector', $global);

        $this->assertInstanceOf(OpenAiCompatClient::class, $client);
    }

    // ─── Test 4: repo stage overrides global stage (D-05 first priority) ──────

    public function test_for_stage_repo_stage_config_overrides_global_stage_config(): void
    {
        // Global has selector=anthropic, repo overrides selector=ollama
        $global = $this->makeGlobal([
            'stages' => [
                'selector' => ['provider' => 'anthropic'],
            ],
        ]);
        $repo = $this->makeRepo([
            'stages' => [
                'selector' => [
                    'provider' => 'ollama',
                    'base_url' => 'http://localhost:11434/v1',
                    'model' => 'llama3.2',
                ],
            ],
        ]);

        $client = LlmClientFactory::forStage('selector', $global, $repo);

        // Repo stage wins → OpenAiCompatClient (not AnthropicApiClient)
        $this->assertInstanceOf(OpenAiCompatClient::class, $client);
    }

    // ─── Test 5: ollamaStageConfigs() returns deduplicated ollama entries ──────

    public function test_ollama_stage_configs_returns_deduplicated_ollama_stage_entries(): void
    {
        $global = $this->makeGlobal([
            'stages' => [
                'selector' => [
                    'provider' => 'ollama',
                    'base_url' => 'http://localhost:11434/v1',
                    'model' => 'llama3.1',
                ],
                'planner' => [
                    'provider' => 'ollama',
                    'base_url' => 'http://localhost:11434/v1',
                    'model' => 'llama3.1',
                ],
                'executor' => [
                    'provider' => 'openrouter',
                    'api_key' => 'key',
                    'model' => 'gpt-4o',
                ],
            ],
        ]);

        $configs = LlmClientFactory::ollamaStageConfigs($global);

        // selector and planner share same base_url → deduplicated to 1 entry
        $this->assertCount(1, $configs);
        $this->assertSame('http://localhost:11434/v1', $configs[0]['base_url']);
        $this->assertSame('llama3.1', $configs[0]['model']);
    }

    // ─── Test 6: global llm.default.provider=claude-code → ClaudeCodeClient ───

    public function test_for_stage_returns_claude_code_client_for_global_claude_code_default(): void
    {
        $global = $this->makeGlobal([
            'default' => [
                'provider' => 'claude-code',
                'model' => 'sonnet',
                'max_budget_usd' => 0.50,
            ],
        ]);

        $client = LlmClientFactory::forStage('selector', $global);

        $this->assertInstanceOf(ClaudeCodeClient::class, $client);
    }

    // ─── Test 7: per-stage allowed-tools defaults (with executor repo merge) ──

    public function test_claude_code_per_stage_allowed_tools_defaults_match_spec(): void
    {
        $global = $this->makeGlobal([
            'default' => [
                'provider' => 'claude-code',
                'model' => 'sonnet',
            ],
        ]);

        // selector → []
        $selector = LlmClientFactory::forStage('selector', $global);
        $this->assertInstanceOf(ClaudeCodeClient::class, $selector);
        $this->assertSame([], $selector->allowedTools());

        // planner → ['Read']
        $planner = LlmClientFactory::forStage('planner', $global);
        $this->assertInstanceOf(ClaudeCodeClient::class, $planner);
        $this->assertSame(['Read'], $planner->allowedTools());

        // executor → default whitelist PLUS Bash(<first-word> *) entries derived
        // from the repo's allowedCommands(), deduped.
        $repo = $this->makeRepo([], allowedCommands: ['composer install', 'pest', 'composer test']);
        $executor = LlmClientFactory::forStage('executor', $global, $repo);
        $this->assertInstanceOf(ClaudeCodeClient::class, $executor);

        $tools = $executor->allowedTools();
        // Built-in defaults
        $this->assertContains('Read', $tools);
        $this->assertContains('Edit', $tools);
        $this->assertContains('Write', $tools);
        $this->assertContains('Bash(git status)', $tools);
        $this->assertContains('Bash(git diff *)', $tools);
        // Mapped from allowed_commands
        $this->assertContains('Bash(composer *)', $tools);
        $this->assertContains('Bash(pest *)', $tools);
        // 'composer install' and 'composer test' both map to 'Bash(composer *)' once (deduped)
        $composerMatches = array_filter($tools, fn (string $t): bool => $t === 'Bash(composer *)');
        $this->assertCount(1, $composerMatches);
    }

    // ─── Test 8: repo-stage allowed_tools override REPLACES the default ───────

    public function test_claude_code_allowed_tools_config_replaces_default_does_not_merge(): void
    {
        $global = $this->makeGlobal([
            'stages' => [
                'executor' => [
                    'provider' => 'claude-code',
                    'model' => 'sonnet',
                    'allowed_tools' => ['Read', 'Bash(make *)'],
                ],
            ],
        ]);

        // The repo here would normally contribute Bash(<cmd> *) entries — but
        // an explicit allowed_tools key REPLACES the default whitelist entirely.
        $repo = $this->makeRepo([], allowedCommands: ['composer install', 'pest']);

        $client = LlmClientFactory::forStage('executor', $global, $repo);
        $this->assertInstanceOf(ClaudeCodeClient::class, $client);

        $this->assertSame(['Read', 'Bash(make *)'], $client->allowedTools());
        $this->assertNotContains('Edit', $client->allowedTools());
        $this->assertNotContains('Bash(composer *)', $client->allowedTools());
    }
}

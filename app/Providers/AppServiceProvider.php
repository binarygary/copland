<?php

namespace App\Providers;

use App\Config\GlobalConfig;
use App\Services\ClaudeExecutorService;
use App\Services\ClaudePlannerService;
use App\Services\ClaudeSelectorService;
use App\Support\LlmClientFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     *
     * Per-service LlmClient bindings per D-08.
     * RepoConfig is null here because the actual repo path is only known at
     * run time. RunCommand wires the real RepoConfig in Plan 03.
     */
    public function register(): void
    {
        $this->app->bind(ClaudeSelectorService::class, function ($app) {
            $client = LlmClientFactory::forStage('selector', $app->make(GlobalConfig::class));

            return new ClaudeSelectorService($app->make(GlobalConfig::class), $client);
        });

        $this->app->bind(ClaudePlannerService::class, function ($app) {
            $client = LlmClientFactory::forStage('planner', $app->make(GlobalConfig::class));

            return new ClaudePlannerService($app->make(GlobalConfig::class), $client);
        });

        $this->app->bind(ClaudeExecutorService::class, function ($app) {
            $client = LlmClientFactory::forStage('executor', $app->make(GlobalConfig::class));

            return new ClaudeExecutorService($app->make(GlobalConfig::class), $client);
        });
    }
}

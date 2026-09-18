<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine;

use Illuminate\Support\ServiceProvider;

class StubEngineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stub-engine.php', 'stub-engine');

        $this->app->singleton(Services\Interpolator::class);
        $this->app->singleton(Services\Scaffolder::class);
        $this->app->singleton(StubEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/stub-engine.php' => $this->app->configPath('stub-engine.php'),
            ], 'stub-engine-config');
        }
    }
}

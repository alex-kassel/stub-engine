<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine;

use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Support\ServiceProvider;

class StubEngineServiceProvider extends ServiceProvider
{
    public const CONFIG_PATH = __DIR__.'/../config/stub-engine.php';

    public const CONFIG_KEY = 'stub-engine';

    public const CONFIG_TAG = 'stub-engine-config';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->bound('config')) {
            $this->mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY);
        }

        $this->app->singleton(StubEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => config_path('stub-engine.php'),
            ], self::CONFIG_TAG);
        }
    }
}

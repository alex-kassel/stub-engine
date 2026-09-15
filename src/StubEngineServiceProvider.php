<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine;

use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Support\ServiceProvider;

class StubEngineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StubEngine::class, function () {
            return new StubEngine;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

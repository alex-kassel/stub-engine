<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Services\Interpolator;
use AlexKassel\StubEngine\StubEngine;
use AlexKassel\StubEngine\StubEngineServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            StubEngineServiceProvider::class,
        ];
    }

    protected function engine(): StubEngine
    {
        return $this->app->make(StubEngine::class);
    }

    protected function interpolator(): Interpolator
    {
        return $this->app->make(Interpolator::class);
    }
}

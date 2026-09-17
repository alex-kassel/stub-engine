<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

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
}

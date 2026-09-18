<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Facades\StubEngine as StubEngineFacade;
use AlexKassel\StubEngine\Services\Interpolator;
use AlexKassel\StubEngine\Services\Scaffolder;
use AlexKassel\StubEngine\StubEngine;
use AlexKassel\StubEngine\StubEngineServiceProvider;

class StubEngineServiceProviderTest extends TestCase
{
    public function test_it_registers_stub_engine_as_singleton_in_container(): void
    {
        $this->assertTrue($this->app->bound(StubEngine::class));
        $this->assertTrue($this->app->bound(Interpolator::class));
        $this->assertTrue($this->app->bound(Scaffolder::class));

        $instance1 = $this->app->make(StubEngine::class);
        $instance2 = $this->app->make(StubEngine::class);

        $this->assertInstanceOf(StubEngine::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function test_facade_resolves_stub_engine_singleton(): void
    {
        $this->assertInstanceOf(StubEngine::class, StubEngineFacade::getFacadeRoot());
        $this->assertSame($this->app->make(StubEngine::class), StubEngineFacade::getFacadeRoot());
    }

    public function test_it_merges_package_configuration(): void
    {
        $this->assertTrue($this->app['config']->has('stub-engine'));
        $this->assertSame('{{', config('stub-engine.delimiters.open'));
        $this->assertSame('}}', config('stub-engine.delimiters.close'));
    }

    public function test_it_publishes_configuration(): void
    {
        $publishes = StubEngineServiceProvider::$publishes;
        $tags = StubEngineServiceProvider::$publishGroups;

        $this->assertArrayHasKey('stub-engine-config', $tags);
        $this->assertArrayHasKey(StubEngineServiceProvider::class, $publishes);
    }
}

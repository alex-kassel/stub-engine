<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Facades;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\StubEngine as StubEngineCore;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ScaffoldBuilder from(string $source)
 * @method static StubEngineCore registerModifier(string $name, callable $callback)
 * @method static string renderFile(ScaffoldRequest $request)
 * @method static ScaffoldResult scaffold(ScaffoldRequest $request)
 * @method static array<int, string> extractTokens(string $content, ?string $open = null, ?string $close = null)
 *
 * @see StubEngineCore
 */
class StubEngine extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StubEngineCore::class;
    }
}

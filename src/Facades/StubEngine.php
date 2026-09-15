<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Facades;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Services\StubEngine as StubEngineService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ScaffoldResult scaffoldTree(string $sourceDir, string $targetDir, array $tokens, ?string $overrideDir = null, string $stubExtension = '.stub')
 *
 * @see StubEngineService
 */
class StubEngine extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StubEngineService::class;
    }
}

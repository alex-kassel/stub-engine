<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Facades;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Services\StubEngine as StubEngineService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ScaffoldBuilder newBuilder()
 * @method static ScaffoldBuilder from(string $sourceDir)
 * @method static ScaffoldBuilder fromFile(string $sourceFile)
 * @method static StubEngineService registerModifier(string $name, callable $callback)
 * @method static array findUnresolvedTokens(string $content, ?string $openDelimiter = null, ?string $closeDelimiter = null)
 * @method static string interpolate(string $content, array $tokens, ?string $openDelimiter = null, ?string $closeDelimiter = null)
 * @method static string renderFile(string $sourceFile, array $tokens, ?string $overrideFile = null, ?string $openDelimiter = null, ?string $closeDelimiter = null, bool $strict = false)
 * @method static bool scaffoldFile(string $sourceFile, string $targetFile, array $tokens, ?string $overrideFile = null, bool $force = false, bool $dryRun = false, ?string $openDelimiter = null, ?string $closeDelimiter = null, bool $strict = false, bool $formatWithPint = false, bool $formatStrict = false, ?string $pintBinary = null, ?string $targetDir = null)
 * @method static ScaffoldResult scaffoldTree(string $sourceDir, string $targetDir, array $tokens, ?string $overrideDir = null, OverrideStrategy $strategy = OverrideStrategy::Overlay, string $stubExtension = StubEngineService::DEFAULT_STUB_EXTENSION, bool $force = false, bool $dryRun = false, ?string $openDelimiter = null, ?string $closeDelimiter = null, bool $strict = false, bool $formatWithPint = false, bool $formatStrict = false, ?string $pintBinary = null, ?callable $onProgress = null)
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

<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Facades;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Services\StubEngine as StubEngineService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ScaffoldBuilder builder()
 * @method static ScaffoldBuilder from(string $sourceDir)
 * @method static ScaffoldBuilder fromFile(string $sourceFile)
 * @method static ScaffoldBuilder forPackage(string $package, ?string $subpath = null)
 * @method static StubEngineService registerModifier(string $name, callable $callback)
 * @method static void ensureWithinTargetDirectory(string $targetDir, string $destination)
 * @method static array findUnresolvedTokens(string $content, ?string $openDelimiter = null, ?string $closeDelimiter = null)
 * @method static array resolveDelimiters(?string $open = null, ?string $close = null)
 * @method static string interpolateWithMap(string $content, array $compiledTokens)
 * @method static string interpolate(string $content, array $tokens, ?string $openDelimiter = null, ?string $closeDelimiter = null)
 * @method static array resolveTokens(array $tokens, ?string $openDelimiter = null, ?string $closeDelimiter = null)
 * @method static array getMergedTokens(array $tokens, ?string $open = null, ?string $close = null)
 * @method static string interpolateContent(string $content, array $mergedTokens, string $open, string $close)
 * @method static string applyModifier(string $value, string $modifierDirective)
 * @method static string renderFile(string $sourceFile, array $tokens, ?string $overrideFile = null, ?string $openDelimiter = null, ?string $closeDelimiter = null, bool $strict = false)
 * @method static bool scaffoldFile(string $sourceFile, string $targetFile, array $tokens, ?string $overrideFile = null, bool $force = false, bool $dryRun = false, ?string $openDelimiter = null, ?string $closeDelimiter = null, bool $strict = false)
 * @method static ScaffoldResult scaffoldTree(string $sourceDir, string $targetDir, array $tokens, ?string $overrideDir = null, OverrideStrategy $strategy = OverrideStrategy::Overlay, string $stubExtension = StubEngineService::DEFAULT_STUB_EXTENSION, bool $force = false, bool $dryRun = false, ?string $openDelimiter = null, ?string $closeDelimiter = null, bool $strict = false)
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

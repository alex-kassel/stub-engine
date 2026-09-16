<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Engines\Interpolator;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Resolvers\StubResolver;
use AlexKassel\StubEngine\Support\PathGuard;
use BadMethodCallException;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StubEngine
{
    use Macroable;

    public const DEFAULT_STUB_EXTENSION = '.stub';

    public const DEFAULT_TOKEN_OPEN_DELIMITER = Interpolator::DEFAULT_TOKEN_OPEN_DELIMITER;

    public const DEFAULT_TOKEN_CLOSE_DELIMITER = Interpolator::DEFAULT_TOKEN_CLOSE_DELIMITER;

    public const DEFAULT_MODIFIER_SEPARATOR = Interpolator::DEFAULT_MODIFIER_SEPARATOR;

    public const DEFAULT_DATE_FORMAT = Interpolator::DEFAULT_DATE_FORMAT;

    public const DEFAULT_LIMIT_LENGTH = Interpolator::DEFAULT_LIMIT_LENGTH;

    public const DEFAULT_LIMIT_END = Interpolator::DEFAULT_LIMIT_END;

    public const CONFIG_DELIMITERS_KEY = Interpolator::CONFIG_DELIMITERS_KEY;

    public const CONFIG_OPEN_DELIMITER_KEY = Interpolator::CONFIG_OPEN_DELIMITER_KEY;

    public const CONFIG_CLOSE_DELIMITER_KEY = Interpolator::CONFIG_CLOSE_DELIMITER_KEY;

    public const CONFIG_GLOBAL_TOKENS_KEY = Interpolator::CONFIG_GLOBAL_TOKENS_KEY;

    public const CONFIG_LEGACY_OPEN_KEY = Interpolator::CONFIG_LEGACY_OPEN_KEY;

    public const CONFIG_LEGACY_CLOSE_KEY = Interpolator::CONFIG_LEGACY_CLOSE_KEY;

    public const CONFIG_LEGACY_GLOBAL_TOKENS_KEY = Interpolator::CONFIG_LEGACY_GLOBAL_TOKENS_KEY;

    public const EMPTY_STRING_FALLBACK = Interpolator::EMPTY_STRING_FALLBACK;

    public const EMPTY_ARRAY_FALLBACK = Interpolator::EMPTY_ARRAY_FALLBACK;

    public const DEFAULT_IGNORED_FILES = StubResolver::DEFAULT_IGNORED_FILES;

    protected Filesystem $files;

    protected Interpolator $interpolator;

    protected StubResolver $resolver;

    protected PathGuard $pathGuard;

    /**
     * @param  Filesystem|null  $files  Filesystem repository
     * @param  array<string, mixed>  $config  Stub engine configuration array
     * @param  Interpolator|null  $interpolator  Token interpolation engine
     * @param  StubResolver|null  $resolver  Stub discovery and overlay resolver
     * @param  PathGuard|null  $pathGuard  Target directory containment validator
     */
    public function __construct(
        ?Filesystem $files = null,
        protected array $config = [],
        ?Interpolator $interpolator = null,
        ?StubResolver $resolver = null,
        ?PathGuard $pathGuard = null,
    ) {
        $this->files = $files ?? new Filesystem;
        $this->interpolator = $interpolator ?? new Interpolator($this->config);
        $this->resolver = $resolver ?? new StubResolver($this->files);
        $this->pathGuard = $pathGuard ?? new PathGuard;
    }

    /**
     * Create a new fluent ScaffoldBuilder instance bound to this engine.
     */
    public function newBuilder(): ScaffoldBuilder
    {
        return new ScaffoldBuilder($this);
    }

    /**
     * Create a new fluent ScaffoldBuilder instance resolved from the container or fresh.
     */
    public static function builder(): ScaffoldBuilder
    {
        $engine = function_exists('app') && app()->bound(self::class)
            ? app(self::class)
            : new self;

        return $engine->newBuilder();
    }

    /**
     * Fluent entry point: start tree scaffolding from a source directory.
     */
    public static function from(string $sourceDir): ScaffoldBuilder
    {
        return static::builder()->from($sourceDir);
    }

    /**
     * Fluent entry point: start single-file scaffolding from a source file.
     */
    public static function fromFile(string $sourceFile): ScaffoldBuilder
    {
        return static::builder()->fromFile($sourceFile);
    }

    /**
     * Fluent entry point: auto-discover package overrides from host conventions.
     */
    public static function forPackage(string $package, ?string $subpath = null): ScaffoldBuilder
    {
        return static::builder()->forPackage($package, $subpath);
    }

    /**
     * Dynamically handle calls to the class or forward to ScaffoldBuilder.
     *
     * @param  array<int, mixed>  $parameters
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            $macro = static::$macros[$method];

            if ($macro instanceof Closure) {
                try {
                    $macro = $macro->bindTo($this, static::class) ?? throw new RuntimeException;
                } catch (Throwable) {
                    $macro = $macro->bindTo(null, static::class);
                }
            }

            return $macro(...$parameters);
        }

        if (method_exists(ScaffoldBuilder::class, $method)) {
            return $this->newBuilder()->$method(...$parameters);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.', static::class, $method
        ));
    }

    /**
     * Access the underlying Filesystem instance.
     */
    public function filesystem(): Filesystem
    {
        return $this->files;
    }

    /**
     * Access the underlying Interpolator instance.
     */
    public function interpolator(): Interpolator
    {
        return $this->interpolator;
    }

    /**
     * Access the underlying StubResolver instance.
     */
    public function resolver(): StubResolver
    {
        return $this->resolver;
    }

    /**
     * Access the underlying PathGuard instance.
     */
    public function pathGuard(): PathGuard
    {
        return $this->pathGuard;
    }

    /**
     * Register a custom token modifier callback.
     *
     * @param  callable(string, ...mixed): string  $callback
     */
    public function registerModifier(string $name, callable $callback): self
    {
        $this->interpolator->registerModifier($name, $callback);

        return $this;
    }

    /**
     * Validate that a destination path stays strictly within the target directory.
     *
     * @throws InvalidArgumentException
     */
    public function ensureWithinTargetDirectory(string $targetDir, string $destination): void
    {
        $this->pathGuard->ensureWithinTargetDirectory($targetDir, $destination);
    }

    /**
     * Scan content and return any unresolved token placeholders.
     *
     * @return array<int, string>
     */
    public function findUnresolvedTokens(
        string $content,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
    ): array {
        return $this->interpolator->findUnresolvedTokens($content, $openDelimiter, $closeDelimiter);
    }

    /**
     * Resolve the effective open and close delimiters just-in-time.
     *
     * @return array{0: string, 1: string}
     */
    public function resolveDelimiters(?string $open = null, ?string $close = null): array
    {
        return $this->interpolator->resolveDelimiters($open, $close);
    }

    /**
     * Merge global config tokens with runtime tokens and normalize keys for interpolation.
     *
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    public function getMergedTokens(array $tokens, ?string $open = null, ?string $close = null): array
    {
        return $this->interpolator->getMergedTokens($tokens, $open, $close);
    }

    /**
     * Single-pass regex interpolation supporting flexible whitespace, modifier chaining,
     * parameterized modifier directives, and Blade verbatim escape prefixes (@{{).
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $mergedTokens  Normalized token replacements
     * @param  string  $open  Effective open delimiter
     * @param  string  $close  Effective close delimiter
     * @param  array<int, string>|null  $unresolved  Optional output reference for unresolved placeholders
     */
    public function interpolateContent(
        string $content,
        array $mergedTokens,
        string $open,
        string $close,
        ?array &$unresolved = null,
    ): string {
        return $this->interpolator->interpolateContent($content, $mergedTokens, $open, $close, $unresolved);
    }

    /**
     * Apply a single modifier directive (with optional parameters) to a string value.
     */
    public function applyModifier(string $value, string $modifierDirective): string
    {
        return $this->interpolator->applyModifier($value, $modifierDirective);
    }

    /**
     * Interpolate token placeholders using a pre-compiled replacement dictionary.
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $compiledTokens  Pre-compiled replacements sorted by key length
     */
    public function interpolateWithMap(string $content, array $compiledTokens): string
    {
        return $this->interpolator->interpolateWithMap($content, $compiledTokens);
    }

    /**
     * Interpolate token placeholders in a given string, supporting case modifiers,
     * custom/configurable delimiters, whitespace tolerance, modifier chaining,
     * and Blade verbatim escape syntax (@{{).
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $tokens  Key-value token replacements
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  array<int, string>|null  $unresolved  Optional output reference for unresolved placeholders
     */
    public function interpolate(
        string $content,
        array $tokens,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        ?array &$unresolved = null,
    ): string {
        return $this->interpolator->interpolate($content, $tokens, $openDelimiter, $closeDelimiter, $unresolved);
    }

    /**
     * Resolve and expand token placeholders with case and formatting modifiers,
     * merging global config tokens and sorting by key length in descending order.
     *
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    public function resolveTokens(
        array $tokens,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
    ): array {
        return $this->interpolator->resolveTokens($tokens, $openDelimiter, $closeDelimiter);
    }

    /**
     * Render a single stub file into a string with token replacements.
     *
     * @param  string  $sourceFile  Default fallback stub file path
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideFile  Optional host override file (takes priority if it exists)
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  bool  $strict  Whether to throw an exception if unresolved tokens remain
     *
     * @throws InvalidArgumentException
     */
    public function renderFile(
        string $sourceFile,
        array $tokens,
        ?string $overrideFile = null,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        bool $strict = false,
    ): string {
        $isOverride = $overrideFile !== null && $this->files->isFile($overrideFile);
        $effectiveSource = $isOverride ? $overrideFile : $sourceFile;

        if (! $this->files->isFile($effectiveSource)) {
            throw new InvalidArgumentException("Stub file not found: [{$effectiveSource}].");
        }

        $content = (string) $this->files->get($effectiveSource);
        $unresolved = [];
        $rendered = $this->interpolator->interpolate($content, $tokens, $openDelimiter, $closeDelimiter, $unresolved);

        if ($strict && $unresolved !== []) {
            throw new InvalidArgumentException("Unresolved tokens in stub file [{$effectiveSource}]: ".implode(', ', $unresolved));
        }

        return $rendered;
    }

    /**
     * Scaffold a single stub file to a target destination with token replacements.
     *
     * @param  string  $sourceFile  Default fallback stub file path
     * @param  string  $targetFile  Target file path to generate
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideFile  Optional host override file (takes priority if it exists)
     * @param  bool  $force  Whether to overwrite an existing target file
     * @param  bool  $dryRun  Whether to simulate without writing to disk
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  bool  $strict  Whether to throw an exception if unresolved tokens remain
     * @return bool True if created/overwritten, false if skipped because it already exists
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldFile(
        string $sourceFile,
        string $targetFile,
        array $tokens,
        ?string $overrideFile = null,
        bool $force = false,
        bool $dryRun = false,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        bool $strict = false,
    ): bool {
        $this->pathGuard->ensureWithinTargetDirectory(dirname($targetFile), $targetFile);

        if ($this->files->exists($targetFile) && ! $force) {
            return false;
        }

        $rendered = $this->renderFile($sourceFile, $tokens, $overrideFile, $openDelimiter, $closeDelimiter, $strict);

        if (! $dryRun) {
            $this->files->ensureDirectoryExists(dirname($targetFile));
            $this->files->put($targetFile, $rendered);
        }

        return true;
    }

    /**
     * Scaffold a complete directory tree from stubs with configurable strategy
     * (Overlay cascading merge vs Replace all-or-nothing), dual-axis token replacements,
     * safe overwrite/simulation controls, and protection for raw non-stub assets.
     *
     * @param  string  $sourceDir  Default fallback stubs directory
     * @param  string  $targetDir  Target directory where files will be created
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideDir  Optional host override directory
     * @param  OverrideStrategy  $strategy  Override strategy (Overlay for cascading merge, Replace for complete directory substitution)
     * @param  string  $stubExtension  Extension to strip from output files
     * @param  bool  $force  Whether to overwrite existing target files
     * @param  bool  $dryRun  Whether to simulate without writing to disk
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  bool  $strict  Whether to throw an exception if unresolved tokens remain
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldTree(
        string $sourceDir,
        string $targetDir,
        array $tokens,
        ?string $overrideDir = null,
        OverrideStrategy $strategy = OverrideStrategy::Overlay,
        string $stubExtension = self::DEFAULT_STUB_EXTENSION,
        bool $force = false,
        bool $dryRun = false,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        bool $strict = false,
    ): ScaffoldResult {
        $stubsMap = $this->resolver->resolveStubsMap($sourceDir, $overrideDir, $strategy);

        // Resolve delimiters and merged tokens ONCE for the entire tree
        [$open, $close] = $this->interpolator->resolveDelimiters($openDelimiter, $closeDelimiter);
        $mergedTokens = $this->interpolator->getMergedTokens($tokens, $open, $close);

        $createdFiles = [];
        $overwrittenFiles = [];
        $skippedFiles = [];
        $overrideFiles = [];
        $rawCopiedFiles = [];
        $unresolvedTokensMap = [];
        $extLen = strlen($stubExtension);

        foreach ($stubsMap as $relPath => $stubInfo) {
            $isStub = $stubExtension !== '' && str_ends_with($relPath, $stubExtension);
            $targetRelPath = $this->interpolator->interpolateContent($relPath, $mergedTokens, $open, $close);

            if ($isStub) {
                $targetRelPath = substr($targetRelPath, 0, -$extLen);
            }

            $destination = rtrim($targetDir, '/\\').'/'.$targetRelPath;
            $this->pathGuard->ensureWithinTargetDirectory($targetDir, $destination);

            $exists = $this->files->exists($destination);

            if ($exists && ! $force) {
                $skippedFiles[] = $targetRelPath;
                if ($stubInfo['isOverride']) {
                    $overrideFiles[] = $targetRelPath;
                }

                continue;
            }

            if ($exists) {
                $overwrittenFiles[] = $targetRelPath;
            } else {
                $createdFiles[] = $targetRelPath;
            }

            if ($stubInfo['isOverride']) {
                $overrideFiles[] = $targetRelPath;
            }

            if ($isStub) {
                $rawContent = (string) $this->files->get($stubInfo['sourcePath']);
                $unresolved = [];
                $renderedContent = $this->interpolator->interpolateContent($rawContent, $mergedTokens, $open, $close, $unresolved);

                if ($unresolved !== []) {
                    $unresolvedTokensMap[$targetRelPath] = $unresolved;
                    if ($strict) {
                        throw new InvalidArgumentException("Unresolved tokens in [{$targetRelPath}]: ".implode(', ', $unresolved));
                    }
                }

                if (! $dryRun) {
                    $this->files->ensureDirectoryExists(dirname($destination));
                    $this->files->put($destination, $renderedContent);
                }
            } else {
                // Raw asset: copy directly without text replacement
                $rawCopiedFiles[] = $targetRelPath;

                if (! $dryRun) {
                    $this->files->ensureDirectoryExists(dirname($destination));
                    $this->files->copy($stubInfo['sourcePath'], $destination);
                }
            }
        }

        return new ScaffoldResult(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            createdFiles: $createdFiles,
            overwrittenFiles: $overwrittenFiles,
            skippedFiles: $skippedFiles,
            overrideFiles: $overrideFiles,
            rawCopiedFiles: $rawCopiedFiles,
            unresolvedTokens: $unresolvedTokensMap,
            dryRun: $dryRun,
            strategy: $strategy,
        );
    }
}

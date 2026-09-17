<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Engines\Interpolator;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Events\FileScaffolded;
use AlexKassel\StubEngine\Events\FileScaffolding;
use AlexKassel\StubEngine\Events\TreeScaffolded;
use AlexKassel\StubEngine\Events\TreeScaffolding;
use AlexKassel\StubEngine\Formatters\PintFormatter;
use AlexKassel\StubEngine\Resolvers\StubResolver;
use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;
use Throwable;

class StubEngine
{
    use Macroable;

    public const DEFAULT_STUB_EXTENSION = '.stub';

    public const DEFAULT_PINT_PATH = PintFormatter::DEFAULT_PINT_PATH;

    public const DEFAULT_FORMAT_WITH_PINT = false;

    public const DEFAULT_FORMAT_STRICT = false;

    protected Filesystem $files;

    protected Interpolator $interpolator;

    protected StubResolver $resolver;

    protected ?Dispatcher $events;

    protected PintFormatter $formatter;

    /**
     * @param  Filesystem|null  $files  Filesystem repository
     * @param  array<string, mixed>  $config  Stub engine configuration array
     * @param  Interpolator|null  $interpolator  Token interpolation engine
     * @param  StubResolver|null  $resolver  Stub discovery and overlay resolver
     * @param  Dispatcher|null  $events  Laravel event dispatcher
     * @param  PintFormatter|null  $formatter  Laravel Pint code formatter service
     */
    public function __construct(
        ?Filesystem $files = null,
        protected array $config = [],
        ?Interpolator $interpolator = null,
        ?StubResolver $resolver = null,
        ?Dispatcher $events = null,
        ?PintFormatter $formatter = null,
    ) {
        $this->files = $files ?? new Filesystem;
        $this->interpolator = $interpolator ?? new Interpolator($this->config);
        $this->resolver = $resolver ?? new StubResolver($this->files);
        $this->events = $events;
        $this->formatter = $formatter ?? new PintFormatter;
    }

    /**
     * Create a new fluent ScaffoldBuilder instance bound to this engine.
     */
    public function newBuilder(): ScaffoldBuilder
    {
        return new ScaffoldBuilder($this);
    }

    /**
     * Start tree scaffolding from a source directory.
     */
    public function from(string $sourceDir): ScaffoldBuilder
    {
        return $this->newBuilder()->from($sourceDir);
    }

    /**
     * Start single-file scaffolding from a source file.
     */
    public function fromFile(string $sourceFile): ScaffoldBuilder
    {
        return $this->newBuilder()->fromFile($sourceFile);
    }

    /**
     * Dynamically handle calls to custom macros.
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
     * Access the underlying Laravel Event Dispatcher instance.
     */
    public function events(): ?Dispatcher
    {
        return $this->events;
    }

    /**
     * Set or replace the Laravel Event Dispatcher instance.
     */
    public function setEventDispatcher(?Dispatcher $events): self
    {
        $this->events = $events;

        return $this;
    }

    /**
     * Access the underlying PintFormatter instance.
     */
    public function formatter(): PintFormatter
    {
        return $this->formatter;
    }

    /**
     * Dispatch an event through the configured dispatcher.
     */
    public function dispatchEvent(object $event): void
    {
        $this->events?->dispatch($event);
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
        bool $formatWithPint = self::DEFAULT_FORMAT_WITH_PINT,
        bool $formatStrict = self::DEFAULT_FORMAT_STRICT,
        ?string $pintBinary = null,
        ?string $targetDir = null,
    ): bool {
        $boundaryDir = $targetDir ?? $this->resolveTargetDirectory($targetFile);
        $this->ensureWithinTargetDirectory($boundaryDir, $targetFile);

        if ($this->files->exists($targetFile) && ! $force) {
            return false;
        }

        $rendered = $this->renderFile($sourceFile, $tokens, $overrideFile, $openDelimiter, $closeDelimiter, $strict);
        $isOverride = $overrideFile !== null && $this->files->isFile($overrideFile);

        $scaffoldingEvent = new FileScaffolding(
            destination: $targetFile,
            relativePath: basename($targetFile),
            content: $rendered,
            isOverride: $isOverride,
            isRawCopy: false,
            dryRun: $dryRun,
        );

        $this->dispatchEvent($scaffoldingEvent);

        if ($scaffoldingEvent->shouldSkip) {
            return false;
        }

        if (! $dryRun) {
            $this->putFile($targetFile, $scaffoldingEvent->content);

            if ($formatWithPint) {
                $this->formatter->format([$targetFile], $formatStrict, $pintBinary);
            }
        }

        $this->dispatchEvent(new FileScaffolded(
            destination: $targetFile,
            relativePath: basename($targetFile),
            isOverride: $isOverride,
            isRawCopy: false,
            dryRun: $dryRun,
        ));

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
        bool $formatWithPint = self::DEFAULT_FORMAT_WITH_PINT,
        bool $formatStrict = self::DEFAULT_FORMAT_STRICT,
        ?string $pintBinary = null,
        ?callable $onProgress = null,
    ): ScaffoldResult {
        $this->dispatchEvent(new TreeScaffolding(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            overrideDir: $overrideDir,
            dryRun: $dryRun,
        ));

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
        $totalFiles = count($stubsMap);
        $currentIndex = 0;

        foreach ($stubsMap as $relPath => $stubInfo) {
            $currentIndex++;
            $isStub = $stubExtension !== '' && str_ends_with($relPath, $stubExtension);
            $targetRelPath = $this->interpolator->interpolateContent($relPath, $mergedTokens, $open, $close);

            if ($isStub) {
                $targetRelPath = substr($targetRelPath, 0, -$extLen);
            }

            $destination = rtrim($targetDir, '/\\').'/'.$targetRelPath;
            $this->ensureWithinTargetDirectory($targetDir, $destination);

            $exists = $this->files->exists($destination);

            if ($exists && ! $force) {
                $skippedFiles[] = $targetRelPath;
                if ($stubInfo['isOverride']) {
                    $overrideFiles[] = $targetRelPath;
                }

                if ($onProgress !== null) {
                    $onProgress($targetRelPath, $currentIndex, $totalFiles);
                }

                continue;
            }

            if ($stubInfo['isOverride']) {
                $overrideFiles[] = $targetRelPath;
            }

            if ($exists) {
                $overwrittenFiles[] = $targetRelPath;
            } else {
                $createdFiles[] = $targetRelPath;
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
                    $this->putFile($destination, $renderedContent);
                }
            } else {
                // Raw asset: copy directly on filesystem without reading into PHP RAM
                $rawCopiedFiles[] = $targetRelPath;

                if (! $dryRun) {
                    $this->copyFile($stubInfo['sourcePath'], $destination);
                }
            }

            if ($onProgress !== null) {
                $onProgress($targetRelPath, $currentIndex, $totalFiles);
            }
        }

        $formatted = false;
        $warnings = [];

        if (! $dryRun && $formatWithPint) {
            $allWrittenFiles = array_map(
                static fn (string $rel): string => rtrim($targetDir, '/\\').'/'.$rel,
                array_merge($createdFiles, $overwrittenFiles)
            );

            $formatResult = $this->formatter->format($allWrittenFiles, $formatStrict, $pintBinary);
            $formatted = $formatResult['formatted'];
            $warnings = $formatResult['warnings'];
        }

        $result = new ScaffoldResult(
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
            warnings: $warnings,
            formatted: $formatted,
        );

        $this->dispatchEvent(new TreeScaffolded($result));

        return $result;
    }

    /**
     * Write file contents ensuring target directory exists via Laravel Filesystem.
     */
    protected function putFile(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists($this->files->dirname($path));
        $this->files->put($path, $content);
    }

    /**
     * Copy file from source to target ensuring target directory exists via Laravel Filesystem.
     */
    protected function copyFile(string $source, string $target): void
    {
        $this->files->ensureDirectoryExists($this->files->dirname($target));
        $this->files->copy($source, $target);
    }

    /**
     * Validate that a destination path stays strictly within the target directory.
     *
     * @throws InvalidArgumentException
     */
    protected function ensureWithinTargetDirectory(string $targetDir, string $destination): void
    {
        $normalizer = new WhitespacePathNormalizer;

        try {
            $normTarget = ($targetDir === '.' || $targetDir === '')
                ? ''
                : $normalizer->normalizePath($targetDir);
            $normDest = $normalizer->normalizePath($destination);
        } catch (PathTraversalDetected) {
            throw new InvalidArgumentException("Target path [{$destination}] attempts directory traversal outside target directory [{$targetDir}].");
        }

        if ($normTarget === '') {
            return;
        }

        if (! str_starts_with($normDest, $normTarget.'/') && $normDest !== $normTarget) {
            throw new InvalidArgumentException("Target path [{$destination}] attempts directory traversal outside target directory [{$targetDir}].");
        }
    }

    /**
     * Resolve the effective target boundary for a destination file when no target directory is explicitly provided.
     */
    protected function resolveTargetDirectory(string $destination): string
    {
        $normalized = str_replace('\\', '/', $destination);

        if (! str_starts_with($normalized, '/')) {
            return '.';
        }

        $basePath = str_replace('\\', '/', base_path());
        if (str_starts_with($normalized, $basePath)) {
            return $basePath;
        }

        $tempDir = str_replace('\\', '/', sys_get_temp_dir());
        if (str_starts_with($normalized, $tempDir)) {
            return $tempDir;
        }

        $parts = explode('/', $normalized);
        $baseParts = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                break;
            }
            $baseParts[] = $part;
        }
        array_pop($baseParts);

        return implode('/', $baseParts) ?: '/';
    }
}

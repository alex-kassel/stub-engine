<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StubEngine
{
    public const DEFAULT_STUB_EXTENSION = '.stub';

    public const DEFAULT_TOKEN_OPEN_DELIMITER = '{{';

    public const DEFAULT_TOKEN_CLOSE_DELIMITER = '}}';

    public const DEFAULT_MODIFIER_SEPARATOR = '|';

    public const CONFIG_OPEN_DELIMITER_KEY = 'stub-engine.delimiters.open';

    public const CONFIG_CLOSE_DELIMITER_KEY = 'stub-engine.delimiters.close';

    public const CONFIG_GLOBAL_TOKENS_KEY = 'stub-engine.global_tokens';

    public function __construct(
        protected Filesystem $files = new Filesystem,
    ) {}

    /**
     * Safely retrieve a configuration value without crashing if config is unbound.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                $container = Container::getInstance();
                if ($container !== null && $container->bound('config')) {
                    return config($key, $default);
                }
            } catch (\Throwable) {
                return $default;
            }
        }

        return $default;
    }

    /**
     * Resolve the effective open and close delimiters just-in-time.
     *
     * @return array{0: string, 1: string}
     */
    public function resolveDelimiters(?string $open = null, ?string $close = null): array
    {
        $configOpen = (string) $this->getConfig(self::CONFIG_OPEN_DELIMITER_KEY, '');
        $configClose = (string) $this->getConfig(self::CONFIG_CLOSE_DELIMITER_KEY, '');

        $effectiveOpen = $open ?: ($configOpen ?: self::DEFAULT_TOKEN_OPEN_DELIMITER);
        $effectiveClose = $close ?: ($configClose ?: self::DEFAULT_TOKEN_CLOSE_DELIMITER);

        return [$effectiveOpen, $effectiveClose];
    }

    /**
     * Interpolate token placeholders in a given string, supporting case modifiers,
     * custom/configurable delimiters, and collision safety by key length ordering.
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $tokens  Key-value token replacements
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     */
    public function interpolate(
        string $content,
        array $tokens,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
    ): string {
        $resolved = $this->resolveTokens($tokens, $openDelimiter, $closeDelimiter);

        return str_replace(array_keys($resolved), array_values($resolved), $content);
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
        [$open, $close] = $this->resolveDelimiters($openDelimiter, $closeDelimiter);

        $globalTokens = (array) $this->getConfig(self::CONFIG_GLOBAL_TOKENS_KEY, []);
        $mergedTokens = array_merge($globalTokens, $tokens);

        $expanded = [];

        foreach ($mergedTokens as $key => $value) {
            $strValue = (string) $value;

            $cleanKey = trim(str_replace([$open, $close], '', $key));
            if ($cleanKey === '') {
                continue;
            }

            // If the key originally contained delimiters or non-alphanumeric wrapper, preserve direct replacement
            if ($key !== $cleanKey || ! preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                $expanded[$key] = $strValue;
            }

            $expanded[$open.' '.$cleanKey.' '.$close] = $strValue;
            $expanded[$open.$cleanKey.$close] = $strValue;

            // Standard case & string formatting modifiers
            $modifiers = [
                'studly' => Str::studly($strValue),
                'camel' => Str::camel($strValue),
                'kebab' => Str::kebab($strValue),
                'snake' => Str::snake($strValue),
                'lower' => Str::lower($strValue),
                'upper' => Str::upper($strValue),
                'title' => Str::title($strValue),
                'plural' => Str::plural($strValue),
                'singular' => Str::singular($strValue),
            ];

            foreach ($modifiers as $mod => $modVal) {
                $expanded[$open.' '.$cleanKey.self::DEFAULT_MODIFIER_SEPARATOR.$mod.' '.$close] = $modVal;
                $expanded[$open.$cleanKey.self::DEFAULT_MODIFIER_SEPARATOR.$mod.$close] = $modVal;
                $expanded[$open.' '.$cleanKey.' '.self::DEFAULT_MODIFIER_SEPARATOR.' '.$mod.' '.$close] = $modVal;
            }
        }

        uksort($expanded, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $expanded;
    }

    /**
     * Render a single stub file into a string with token replacements.
     *
     * @param  string  $sourceFile  Default fallback stub file path
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideFile  Optional host override file (takes priority if it exists)
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     *
     * @throws InvalidArgumentException
     */
    public function renderFile(
        string $sourceFile,
        array $tokens,
        ?string $overrideFile = null,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
    ): string {
        $isOverride = $overrideFile !== null && $this->files->isFile($overrideFile);
        $effectiveSource = $isOverride ? $overrideFile : $sourceFile;

        if (! $this->files->isFile($effectiveSource)) {
            throw new InvalidArgumentException("Stub file not found: [{$effectiveSource}].");
        }

        $content = (string) $this->files->get($effectiveSource);

        return $this->interpolate($content, $tokens, $openDelimiter, $closeDelimiter);
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
    ): bool {
        if ($this->files->exists($targetFile) && ! $force) {
            return false;
        }

        $rendered = $this->renderFile($sourceFile, $tokens, $overrideFile, $openDelimiter, $closeDelimiter);

        if (! $dryRun) {
            $this->files->ensureDirectoryExists(dirname($targetFile));
            $this->files->put($targetFile, $rendered);
        }

        return true;
    }

    /**
     * Scaffold a complete directory tree from stubs with configurable strategy
     * (Overlay cascading merge vs Replace all-or-nothing), dual-axis token replacements,
     * and safe overwrite/simulation controls.
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
    ): ScaffoldResult {
        if (! $this->files->isDirectory($sourceDir)) {
            throw new InvalidArgumentException("Stubs source directory not found: [{$sourceDir}].");
        }

        $hasOverrideDir = $overrideDir !== null && $this->files->isDirectory($overrideDir);

        /** @var array<string, array{sourcePath: string, isOverride: bool}> $stubsMap */
        $stubsMap = [];

        if ($hasOverrideDir && $strategy === OverrideStrategy::Replace) {
            // Replace strategy: discard default source stubs completely and use only host override directory
            foreach ($this->files->allFiles($overrideDir) as $file) {
                $relPath = str_replace('\\', '/', $file->getRelativePathname());
                $stubsMap[$relPath] = [
                    'sourcePath' => $file->getPathname(),
                    'isOverride' => true,
                ];
            }
        } else {
            // Overlay strategy (default): base stubs first
            foreach ($this->files->allFiles($sourceDir) as $file) {
                $relPath = str_replace('\\', '/', $file->getRelativePathname());
                $stubsMap[$relPath] = [
                    'sourcePath' => $file->getPathname(),
                    'isOverride' => false,
                ];
            }

            // Layer host overrides on top (cascading file-by-file overlay)
            if ($hasOverrideDir) {
                foreach ($this->files->allFiles($overrideDir) as $file) {
                    $relPath = str_replace('\\', '/', $file->getRelativePathname());
                    $stubsMap[$relPath] = [
                        'sourcePath' => $file->getPathname(),
                        'isOverride' => true,
                    ];
                }
            }
        }

        $createdFiles = [];
        $overwrittenFiles = [];
        $skippedFiles = [];
        $overrideFiles = [];
        $extLen = strlen($stubExtension);

        foreach ($stubsMap as $relPath => $stubInfo) {
            $targetRelPath = $this->interpolate($relPath, $tokens, $openDelimiter, $closeDelimiter);
            if ($stubExtension !== '' && str_ends_with($targetRelPath, $stubExtension)) {
                $targetRelPath = substr($targetRelPath, 0, -$extLen);
            }

            $destination = rtrim($targetDir, '/\\').'/'.$targetRelPath;
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

            $rawContent = (string) $this->files->get($stubInfo['sourcePath']);
            $renderedContent = $this->interpolate($rawContent, $tokens, $openDelimiter, $closeDelimiter);

            if (! $dryRun) {
                $this->files->ensureDirectoryExists(dirname($destination));
                $this->files->put($destination, $renderedContent);
            }
        }

        return new ScaffoldResult(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            createdFiles: $createdFiles,
            overwrittenFiles: $overwrittenFiles,
            skippedFiles: $skippedFiles,
            overrideFiles: $overrideFiles,
            dryRun: $dryRun,
            strategy: $strategy,
        );
    }
}
